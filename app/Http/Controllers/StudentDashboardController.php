<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Holiday;
use App\Models\MakeupSession;
use App\Models\Student;
use App\Services\AuthService;
use App\Traits\ApiResponseTrait;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentDashboardController extends Controller
{
    use ApiResponseTrait;

    /**
     * GET /api/student/dashboard
     * Öğrencinin kendi dashboard'u: sınıf bilgisi, devamsızlık özeti, bugünün dersi.
     */
    public function dashboard(Request $request): JsonResponse
    {
        /** @var Student $student */
        $student = $request->user();
        $student->loadMissing('classroom.user');

        $classroom = $student->classroom;
        if (! $classroom) {
            return $this->errorResponse('Öğrenci bir sınıfa bağlı değil.', 422);
        }

        $limit = $this->absenceLimitFor($classroom->user);

        $records = Attendance::where('student_id', $student->id)
            ->where('classroom_id', $classroom->id)
            ->orderBy('date')
            ->get();

        $present = $records->whereIn('status', ['present', 'late'])->count();
        $absent  = $records->where('status', 'absent')->count();
        $excused = $records->where('status', 'excused')->count();
        $late    = $records->where('status', 'late')->count();

        $totalAtt = $records->count();
        $effectiveTotal = $totalAtt - $excused;
        $attendanceRate = $effectiveTotal > 0 ? round(($present / $effectiveTotal) * 100, 1) : null;

        $status = $absent >= $limit
            ? 'Kaldı'
            : ($absent >= $limit - 1 && $absent > 0 ? 'Sınırda' : 'Güvenli');

        // Bugünün durumu
        $today = Carbon::now();
        $todayDate = $today->toDateString();
        $daysTr = ['Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar'];
        $todayDay = $daysTr[$today->dayOfWeekIso - 1];

        $isTodayClassDay = $classroom->day === $todayDay && $classroom->status === 'Aktif';
        $todayHoliday = Holiday::where('user_id', $classroom->user_id)
            ->where('date', $todayDate)
            ->first();

        $todayRecord = $records->firstWhere(fn ($r) => $r->date->toDateString() === $todayDate);

        // Son 6 hafta katılım trendi
        $trend = [];
        for ($i = 5; $i >= 0; $i--) {
            $start = Carbon::now()->subWeeks($i)->startOfWeek()->toDateString();
            $end   = Carbon::now()->subWeeks($i)->endOfWeek()->toDateString();
            $weekRecords = $records->filter(
                fn ($r) => $r->date->toDateString() >= $start && $r->date->toDateString() <= $end
            );
            $weekTotal   = $weekRecords->count();
            $weekPresent = $weekRecords->whereIn('status', ['present', 'late'])->count();
            $trend[] = [
                'name'     => (6 - $i).'. Hafta',
                'oran'     => $weekTotal > 0 ? round(($weekPresent / $weekTotal) * 100, 1) : 0,
                'has_data' => $weekTotal > 0,
            ];
        }
        $hasAnyTrendData = collect($trend)->contains('has_data', true);

        return $this->successResponse([
            'student' => [
                'id'             => $student->id,
                'student_number' => $student->student_number,
                'name'           => $student->name,
                'email'          => $student->email,
            ],
            'classroom' => [
                'id'           => $classroom->id,
                'name'         => $classroom->name,
                'code'         => $classroom->code,
                'department'   => $classroom->department,
                'day'          => $classroom->day,
                'time'         => $classroom->time,
                'status'       => $classroom->status,
                'is_archived'  => $classroom->archived_at !== null,
                'teacher_name' => $classroom->user?->name,
            ],
            'summary' => [
                'limit'           => $limit,
                'totalSessions'   => $totalAtt,
                'present'         => $present,
                'absent'          => $absent,
                'late'            => $late,
                'excused'         => $excused,
                'limitLeft'       => max(0, $limit - $absent),
                'status'          => $status,
                'attendanceRate'  => $attendanceRate,
            ],
            'today' => [
                'date'         => $todayDate,
                'day'          => $todayDay,
                'isClassDay'   => $isTodayClassDay,
                'classTime'    => $classroom->time,
                'holiday'      => $todayHoliday ? [
                    'name' => $todayHoliday->name,
                    'type' => $todayHoliday->type,
                ] : null,
                'myStatus'     => $todayRecord?->status,
            ],
            'trend' => $hasAnyTrendData ? $trend : [],
        ]);
    }

    /**
     * GET /api/student/attendances
     * Öğrencinin (aynı öğrenci numarasıyla farklı sınıflardaki) tüm yoklama geçmişi.
     * Çıktı: { classrooms: [{id, name, code, day, time}], records: [{id, date, status, classroom_id, classroom_name}] }
     */
    public function attendances(Request $request): JsonResponse
    {
        /** @var Student $student */
        $student = $request->user();

        // Aynı student_number altındaki tüm Student satırları + classroom'lar tek sorguda
        $studentRows = Student::with('classroom:id,name,code,day,time')
            ->where('student_number', $student->student_number)
            ->get();

        $studentIds = $studentRows->pluck('id');

        $records = Attendance::whereIn('student_id', $studentIds)
            ->with('classroom:id,name,code,day,time')
            ->orderByDesc('date')
            ->get()
            ->map(fn ($r) => [
                'id'              => $r->id,
                'date'            => $r->date->toDateString(),
                'status'          => $r->status,
                'classroom_id'    => $r->classroom_id,
                'classroom_name'  => $r->classroom?->name,
                'classroom_code'  => $r->classroom?->code,
            ]);

        // Filtre dropdown'u — eager-loaded classroom'lardan deduplicate
        $classrooms = $studentRows
            ->pluck('classroom')
            ->filter()
            ->unique('id')
            ->values()
            ->map(fn ($c) => [
                'id'   => $c->id,
                'name' => $c->name,
                'code' => $c->code,
                'day'  => $c->day,
                'time' => $c->time,
            ]);

        return $this->successResponse([
            'classrooms' => $classrooms,
            'records'    => $records,
        ]);
    }

    /**
     * GET /api/student/schedule
     * Aynı student_number altındaki tüm Student kayıtlarına bağlı sınıfların haftalık programı.
     * Her ders kartı: gün, saat, sınıf adı, kod, hoca adı.
     */
    public function schedule(Request $request): JsonResponse
    {
        /** @var Student $student */
        $student = $request->user();

        $classrooms = Student::where('student_number', $student->student_number)
            ->with('classroom.user:id,name,first_name,last_name')
            ->get()
            ->pluck('classroom')
            ->filter(fn ($c) => $c && ! $c->archived_at)
            ->unique('id')
            ->values()
            ->map(function ($c) {
                $teacher = $c->user;
                $teacherName = $teacher
                    ? trim(($teacher->first_name ?? '').' '.($teacher->last_name ?? '')) ?: $teacher->name
                    : null;
                return [
                    'id'           => $c->id,
                    'name'         => $c->name,
                    'code'         => $c->code,
                    'department'   => $c->department,
                    'day'          => $c->day,
                    'time'         => $c->time,
                    'teacher_name' => $teacherName,
                ];
            });

        // Bölüm: tüm sınıfların ortak bölümü (öğrenci tek bölümde olur varsayımı)
        $department = $classrooms->pluck('department')->filter()->unique()->first();

        // Yaklaşan telafi dersleri (sadece bu öğrencinin dahil olduğu sınıflar)
        $classroomIds = $classrooms->pluck('id');
        $makeups = MakeupSession::with('classroom.user:id,name,first_name,last_name')
            ->whereIn('classroom_id', $classroomIds)
            ->where('date', '>=', \Carbon\Carbon::today()->toDateString())
            ->orderBy('date')
            ->orderBy('time')
            ->get()
            ->map(function ($m) {
                $teacher = $m->classroom?->user;
                $teacherName = $teacher
                    ? (trim(($teacher->first_name ?? '').' '.($teacher->last_name ?? '')) ?: $teacher->name)
                    : null;
                return [
                    'id'             => $m->id,
                    'classroom_id'   => $m->classroom_id,
                    'classroom_name' => $m->classroom?->name,
                    'classroom_code' => $m->classroom?->code,
                    'date'           => $m->date?->toDateString(),
                    'date_label'     => $m->date?->locale('tr')->translatedFormat('d F Y'),
                    'time'           => $m->time,
                    'day'            => $m->day,
                    'note'           => $m->note,
                    'teacher_name'   => $teacherName,
                ];
            });

        return $this->successResponse([
            'department' => $department,
            'classes'    => $classrooms,
            'makeups'    => $makeups,
        ]);
    }

    /**
     * GET /api/student/holidays?from=YYYY-MM-DD&to=YYYY-MM-DD
     * Öğrencinin kayıtlı olduğu tüm sınıfların hocalarındaki tatiller (deduplicated by date).
     */
    public function holidays(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to'   => ['nullable', 'date'],
        ]);

        $from = $request->query('from') ?: Carbon::today()->toDateString();
        $to   = $request->query('to')   ?: Carbon::today()->addMonths(2)->toDateString();

        /** @var Student $student */
        $student = $request->user();

        // Öğrencinin tüm sınıflarının hocalarını topla
        $teacherIds = Student::where('student_number', $student->student_number)
            ->with('classroom:id,user_id')
            ->get()
            ->pluck('classroom.user_id')
            ->filter()
            ->unique()
            ->values();

        if ($teacherIds->isEmpty()) {
            return $this->successResponse([]);
        }

        // Tatilleri çek + tarih bazlı dedupe (aynı tarihi farklı hoca eklemiş olabilir)
        $holidays = Holiday::whereIn('user_id', $teacherIds)
            ->whereBetween('date', [$from, $to])
            ->orderBy('date')
            ->get()
            ->groupBy(fn ($h) => $h->date->toDateString())
            ->map(fn ($group) => $group->first()) // her tarihten ilkini al
            ->values()
            ->map(fn ($h) => [
                'id'   => $h->id,
                'date' => $h->date->toDateString(),
                'name' => $h->name,
                'type' => $h->type,
            ]);

        return $this->successResponse($holidays);
    }

    private function absenceLimitFor($teacher): int
    {
        $defaults = AuthService::defaultPreferences();
        $prefs = $teacher?->preferences ?? $defaults;
        $val = (int) ($prefs['defaultAbsenceLimit'] ?? $defaults['defaultAbsenceLimit']);
        return max(1, min(20, $val));
    }
}
