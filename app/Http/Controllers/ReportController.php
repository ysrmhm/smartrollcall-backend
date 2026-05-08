<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Classroom;
use App\Models\Student;
use App\Services\AuthService;
use App\Traits\ApiResponseTrait;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    use ApiResponseTrait;

    /**
     * GET /api/reports/data
     * Parametreler:
     *  - from: YYYY-MM-DD (zorunlu)
     *  - to:   YYYY-MM-DD (zorunlu)
     *  - classroom_ids[]: array (opsiyonel — boşsa hocanın tüm aktif sınıfları)
     *  - student_id: int (opsiyonel — sadece bu öğrencinin verisi)
     *  - include_archived: bool (default false)
     *
     * Dönen yapı (frontend'de Excel/PDF rapor üretmek için yeterli):
     *  {
     *    range: { from, to, days },
     *    limit: int,
     *    classrooms: [{ id, name, code, department, day, time, students_count }],
     *    students: [{ id, classroom_id, classroom_name, student_number, name,
     *                 present, absent, late, excused, total, attendance_rate, status }],
     *    sessions: [{ classroom_id, classroom_name, date, present, absent, late, excused, total }],
     *    totals:   { present, absent, late, excused, total, attendance_rate }
     *  }
     */
    public function data(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from'             => ['required', 'date'],
            'to'               => ['required', 'date', 'after_or_equal:from'],
            'classroom_ids'    => ['nullable', 'array'],
            'classroom_ids.*'  => ['integer'],
            'student_id'       => ['nullable', 'integer'],
            'include_archived' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $from = Carbon::parse($data['from'])->startOfDay();
        $to   = Carbon::parse($data['to'])->endOfDay();
        $includeArchived = (bool) ($data['include_archived'] ?? false);

        // Hocanın izinli sınıfları
        $allowedClassrooms = Classroom::where('user_id', $user->id)
            ->when(! $includeArchived, fn ($q) => $q->whereNull('archived_at'))
            ->get();

        // Filtre: classroom_ids varsa sadece o sınıflar
        $selected = $allowedClassrooms;
        if (! empty($data['classroom_ids'])) {
            $selected = $allowedClassrooms->whereIn('id', $data['classroom_ids'])->values();
        }

        if ($selected->isEmpty()) {
            return $this->successResponse($this->emptyPayload($from, $to));
        }

        $classroomIds = $selected->pluck('id');

        // Öğrenci listesi (filtre)
        $studentsQuery = Student::whereIn('classroom_id', $classroomIds)
            ->orderBy('classroom_id')
            ->orderBy('student_number');
        if (! empty($data['student_id'])) {
            $studentsQuery->where('id', (int) $data['student_id']);
        }
        $students = $studentsQuery->get();

        if ($students->isEmpty()) {
            return $this->successResponse($this->emptyPayload($from, $to, $selected));
        }

        // Yoklama kayıtları (date range filtreli)
        $attendances = Attendance::whereIn('classroom_id', $classroomIds)
            ->whereIn('student_id', $students->pluck('id'))
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get();

        $limit = $this->absenceLimitFor($user);

        // Öğrenci bazlı özet
        $byStudent = $attendances->groupBy('student_id');
        $studentRows = $students->map(function (Student $s) use ($byStudent, $selected, $limit) {
            $records = $byStudent[$s->id] ?? collect();
            $present = $records->whereIn('status', ['present', 'late'])->count();
            $absent  = $records->where('status', 'absent')->count();
            $late    = $records->where('status', 'late')->count();
            $excused = $records->where('status', 'excused')->count();
            $total   = $records->count();
            $effective = $total - $excused;
            $rate = $effective > 0 ? round(($present / $effective) * 100, 1) : null;
            $status = $absent >= $limit
                ? 'Kaldı'
                : ($absent >= $limit - 1 && $absent > 0 ? 'Sınırda' : 'Güvenli');

            $classroom = $selected->firstWhere('id', $s->classroom_id);

            return [
                'id'              => $s->id,
                'classroom_id'    => $s->classroom_id,
                'classroom_name'  => $classroom?->name ?? '—',
                'classroom_code'  => $classroom?->code,
                'student_number'  => $s->student_number,
                'name'            => $s->name,
                'email'           => $s->email,
                'present'         => $present,
                'absent'          => $absent,
                'late'            => $late,
                'excused'         => $excused,
                'total'           => $total,
                'attendance_rate' => $rate,
                'status'          => $status,
            ];
        })->values();

        // Oturum (sınıf+gün) bazlı özet
        $bySession = $attendances->groupBy(fn ($a) => $a->classroom_id.'__'.$a->date->toDateString());
        $sessionRows = $bySession->map(function ($records, $key) use ($selected) {
            [$cId, $date] = explode('__', $key);
            $classroom = $selected->firstWhere('id', (int) $cId);
            return [
                'classroom_id'   => (int) $cId,
                'classroom_name' => $classroom?->name ?? '—',
                'classroom_code' => $classroom?->code,
                'date'           => $date,
                'present'        => $records->whereIn('status', ['present', 'late'])->count(),
                'absent'         => $records->where('status', 'absent')->count(),
                'late'           => $records->where('status', 'late')->count(),
                'excused'        => $records->where('status', 'excused')->count(),
                'total'          => $records->count(),
            ];
        })->sortBy(fn ($r) => $r['date'].$r['classroom_name'])->values();

        // Genel toplam
        $totalPresent = $attendances->whereIn('status', ['present', 'late'])->count();
        $totalAbsent  = $attendances->where('status', 'absent')->count();
        $totalLate    = $attendances->where('status', 'late')->count();
        $totalExcused = $attendances->where('status', 'excused')->count();
        $totalAll     = $attendances->count();
        $effectiveAll = $totalAll - $totalExcused;
        $rateAll = $effectiveAll > 0 ? round(($totalPresent / $effectiveAll) * 100, 1) : null;

        return $this->successResponse([
            'range' => [
                'from' => $from->toDateString(),
                'to'   => $to->toDateString(),
                'days' => $from->diffInDays($to) + 1,
            ],
            'limit'      => $limit,
            'classrooms' => $selected->map(fn (Classroom $c) => [
                'id'              => $c->id,
                'name'            => $c->name,
                'code'            => $c->code,
                'department'      => $c->department,
                'day'             => $c->day,
                'time'            => $c->time,
                'students_count'  => $students->where('classroom_id', $c->id)->count(),
                'is_archived'     => $c->archived_at !== null,
            ])->values(),
            'students' => $studentRows,
            'sessions' => $sessionRows,
            'totals'   => [
                'present'         => $totalPresent,
                'absent'          => $totalAbsent,
                'late'            => $totalLate,
                'excused'         => $totalExcused,
                'total'           => $totalAll,
                'attendance_rate' => $rateAll,
            ],
        ]);
    }

    /**
     * GET /api/reports/options
     * Frontend'in filtre dropdown'larını doldurması için: hocanın sınıfları + her birinin öğrencileri.
     */
    public function options(Request $request): JsonResponse
    {
        $includeArchived = $request->boolean('include_archived', false);

        $classrooms = Classroom::where('user_id', $request->user()->id)
            ->when(! $includeArchived, fn ($q) => $q->whereNull('archived_at'))
            ->with(['students:id,classroom_id,student_number,first_name,last_name'])
            ->orderBy('name')
            ->get();

        return $this->successResponse([
            'classrooms' => $classrooms->map(fn (Classroom $c) => [
                'id'           => $c->id,
                'name'         => $c->name,
                'code'         => $c->code,
                'department'   => $c->department,
                'day'          => $c->day,
                'time'         => $c->time,
                'is_archived'  => $c->archived_at !== null,
                'students'     => $c->students->map(fn (Student $s) => [
                    'id'             => $s->id,
                    'student_number' => $s->student_number,
                    'name'           => $s->name,
                ])->values(),
            ])->values(),
        ]);
    }

    private function emptyPayload(Carbon $from, Carbon $to, $selected = null): array
    {
        return [
            'range' => [
                'from' => $from->toDateString(),
                'to'   => $to->toDateString(),
                'days' => $from->diffInDays($to) + 1,
            ],
            'limit'      => 4,
            'classrooms' => $selected ? $selected->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'code' => $c->code,
                'department' => $c->department, 'day' => $c->day, 'time' => $c->time,
                'students_count' => 0, 'is_archived' => $c->archived_at !== null,
            ])->values() : [],
            'students'   => [],
            'sessions'   => [],
            'totals'     => ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0, 'total' => 0, 'attendance_rate' => null],
        ];
    }

    private function absenceLimitFor($user): int
    {
        $defaults = AuthService::defaultPreferences();
        $prefs = $user->preferences ?? $defaults;
        $val = (int) ($prefs['defaultAbsenceLimit'] ?? $defaults['defaultAbsenceLimit']);
        return max(1, min(20, $val));
    }
}
