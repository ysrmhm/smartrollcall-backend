<?php

namespace App\Http\Controllers;

use App\Mail\AttendanceNotificationMail;
use App\Models\Attendance;
use App\Models\Classroom;
use App\Models\Holiday;
use App\Models\InboxMessage;
use App\Models\Student;
use App\Models\StudentInboxMessage;
use App\Services\AuthService;
use App\Traits\ApiResponseTrait;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class AttendanceController extends Controller
{
    use ApiResponseTrait;

    /**
     * POST /api/classrooms/{classroom}/attendances
     * Bir sınıfa, bir tarihe ait yoklama satırlarını upsert eder.
     */
    public function store(Request $request, Classroom $classroom): JsonResponse
    {
        $this->authorizeClassroom($request, $classroom);

        if ($classroom->archived_at) {
            return $this->errorResponse('Arşivlenmiş bir sınıfa yoklama eklenemez. Önce sınıfı geri alın.', 422);
        }

        $data = $request->validate([
            'date'                  => ['required', 'date'],
            'records'               => ['required', 'array', 'min:1'],
            'records.*.student_id'  => ['required', 'integer'],
            'records.*.status'      => ['required', Rule::in(['present', 'absent', 'late', 'excused'])],
        ]);

        $date = Carbon::parse($data['date'])->toDateString();

        // Sadece bu sınıfın öğrencileri kabul edilir
        $validStudentIds = $classroom->students()->pluck('id')->all();

        $saved = 0;
        DB::transaction(function () use ($classroom, $date, $data, $validStudentIds, &$saved) {
            foreach ($data['records'] as $row) {
                if (! in_array($row['student_id'], $validStudentIds, true)) {
                    continue;
                }
                Attendance::updateOrCreate(
                    [
                        'student_id'   => $row['student_id'],
                        'classroom_id' => $classroom->id,
                        'date'         => $date,
                    ],
                    ['status' => $row['status']]
                );
                $saved++;
            }
            $classroom->update(['attendance_taken' => true]);
        });

        // === Otomatik Bildirim Üretimi ===
        $user = $request->user();
        $limit = $this->absenceLimitFor($user);

        // 1) Yoklama tamamlandı bildirimi
        InboxMessage::create([
            'user_id' => $user->id,
            'type'    => 'success',
            'title'   => 'Yoklama Tamamlandı',
            'body'    => "{$classroom->name} sınıfı için {$saved} öğrenci işaretlendi.",
            'link'    => "/dashboard/past-attendances/{$classroom->id}_{$date}",
        ]);

        // 2) Risk durumu değişen öğrencileri tespit et
        $absentStudentIds = collect($data['records'])
            ->where('status', 'absent')
            ->pluck('student_id')
            ->all();

        if (! empty($absentStudentIds)) {
            $absenceCounts = Attendance::where('classroom_id', $classroom->id)
                ->whereIn('student_id', $absentStudentIds)
                ->where('status', 'absent')
                ->select('student_id', DB::raw('COUNT(*) as cnt'))
                ->groupBy('student_id')
                ->get()
                ->keyBy('student_id');

            $studentsById = Student::whereIn('id', $absentStudentIds)->get()->keyBy('id');

            foreach ($absentStudentIds as $sid) {
                $count = (int) ($absenceCounts[$sid]->cnt ?? 0);
                $student = $studentsById[$sid] ?? null;
                if (! $student) continue;

                // Tam sınıra geldiyse → kritik bildirim
                if ($count === $limit) {
                    InboxMessage::create([
                        'user_id' => $user->id,
                        'type'    => 'danger',
                        'title'   => 'Devamsızlıktan Kaldı',
                        'body'    => "{$student->name} ({$classroom->name}) artık devamsızlık sınırını aştı: {$count}/{$limit}.",
                        'link'    => '/dashboard/risk-report',
                    ]);
                }
                // Sınıra yaklaştıysa → uyarı bildirimi (sadece ilk kez sınıra geldiğinde)
                elseif ($count === ($limit - 1) && $limit > 1) {
                    InboxMessage::create([
                        'user_id' => $user->id,
                        'type'    => 'warning',
                        'title'   => 'Sınıra Yaklaşan Öğrenci',
                        'body'    => "{$student->name} ({$classroom->name}) sınıra ulaştı: {$count}/{$limit}. Bir sonraki devamsızlığında kalır.",
                        'link'    => '/dashboard/risk-report',
                    ]);
                }
            }
        }

        // === Öğrenci Tarafı Bildirimleri ===
        // Her record için ilgili öğrenciye kişisel bildirim üret.
        $absentCountsAll = Attendance::where('classroom_id', $classroom->id)
            ->where('status', 'absent')
            ->select('student_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('student_id')
            ->get()
            ->keyBy('student_id');

        $dateLabel = Carbon::parse($date)->locale('tr')->translatedFormat('d F Y');
        $studentLink = '/student/attendances';

        foreach ($data['records'] as $row) {
            if (! in_array($row['student_id'], $validStudentIds, true)) continue;

            $sid = $row['student_id'];
            $status = $row['status'];

            $payload = match ($status) {
                'absent' => (function () use ($absentCountsAll, $sid, $limit, $classroom, $dateLabel, $studentLink) {
                    $cnt = (int) ($absentCountsAll[$sid]->cnt ?? 0);
                    if ($cnt >= $limit) {
                        return [
                            'type'  => 'danger',
                            'title' => 'Devamsızlık Sınırını Aştınız',
                            'body'  => "{$classroom->name} dersinden {$dateLabel} tarihinde yok sayıldınız. Toplam: {$cnt}/{$limit} — sınıfı geçemezsiniz.",
                            'link'  => $studentLink,
                        ];
                    }
                    if ($cnt === $limit - 1 && $limit > 1) {
                        return [
                            'type'  => 'warning',
                            'title' => 'Devamsızlık Sınırına Yaklaştınız',
                            'body'  => "{$classroom->name} dersinden {$dateLabel} tarihinde yok sayıldınız. Toplam: {$cnt}/{$limit}. Bir sonraki devamsızlığınızda kalırsınız.",
                            'link'  => $studentLink,
                        ];
                    }
                    return [
                        'type'  => 'warning',
                        'title' => 'Yoklamada Yoktunuz',
                        'body'  => "{$classroom->name} dersinden {$dateLabel} tarihinde yok sayıldınız. Toplam: {$cnt}/{$limit}.",
                        'link'  => $studentLink,
                    ];
                })(),
                'late' => [
                    'type'  => 'info',
                    'title' => 'Geç Geldiğiniz İşaretlendi',
                    'body'  => "{$classroom->name} dersinden {$dateLabel} tarihinde geç geldiniz. Var olarak sayıldı, hak düşmedi.",
                    'link'  => $studentLink,
                ],
                'excused' => [
                    'type'  => 'info',
                    'title' => 'Mazeretli Sayıldınız',
                    'body'  => "{$classroom->name} dersinden {$dateLabel} tarihinde mazeretli sayıldınız. Devamsızlık hakkınızdan düşmedi.",
                    'link'  => $studentLink,
                ],
                default => null, // 'present' için bildirim yok
            };

            if ($payload) {
                StudentInboxMessage::create(array_merge($payload, ['student_id' => $sid]));
            }
        }

        // === Otomatik E-Posta Bildirimi (sadece 'absent' ve 'late' için) ===
        // Response gönderildikten SONRA arkada gönderilir → hoca yoklama sayfasında beklemez.
        $this->scheduleAttendanceMails(
            $classroom, $request->user(), $data['records'], $validStudentIds,
            $absentCountsAll, $limit, $date
        );

        // Achievements cache'lerini temizle — etkilenen öğrencilerin rozetleri/XP'si değişti
        $affectedSids = collect($data['records'])->pluck('student_id')->unique()->all();
        $affectedNumbers = Student::whereIn('id', $affectedSids)->pluck('student_number')->unique();
        foreach ($affectedNumbers as $num) {
            StudentAchievementsController::invalidateFor($num);
        }

        return $this->successResponse([
            'saved' => $saved,
            'date'  => $date,
        ], "Yoklama kaydedildi: {$saved} öğrenci.");
    }

    /**
     * Yoklama kaydedildikten sonra yok/geç olarak işaretlenen öğrencilere
     * e-posta bildirimi yollar. terminating() callback'i ile response döndükten
     * sonra çalışır → hoca'nın UI'da beklemesini önler.
     */
    private function scheduleAttendanceMails(
        Classroom $classroom, $teacher, array $records, array $validStudentIds,
        $absentCountsAll, int $limit, string $date
    ): void {
        // Mail gönderilecek öğrencileri topla
        $targets = [];
        $teacherName = trim(($teacher->first_name ?? '').' '.($teacher->last_name ?? '')) ?: ($teacher->name ?? 'Hoca');
        $dateLabel = Carbon::parse($date)->locale('tr')->translatedFormat('d F Y l');
        $timeLabel = $classroom->time;

        $statusLabels = [
            'absent'  => 'Yok',
            'late'    => 'Geç Geldi',
            'excused' => 'Mazeretli',
        ];

        $studentEmails = Student::whereIn('id', collect($records)->pluck('student_id'))
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->get(['id', 'email', 'first_name', 'last_name']);
        $emailById = $studentEmails->keyBy('id');

        foreach ($records as $row) {
            if (! in_array($row['student_id'], $validStudentIds, true)) continue;
            $status = $row['status'];
            // Sadece absent ve late için mail at — present/excused mail spam olur
            if (! in_array($status, ['absent', 'late'], true)) continue;

            $student = $emailById[$row['student_id']] ?? null;
            if (! $student) continue; // email yok → skip

            $studentName = trim(($student->first_name ?? '').' '.($student->last_name ?? '')) ?: 'Öğrenci';
            $absentCount = (int) ($absentCountsAll[$row['student_id']]->cnt ?? 0);

            $targets[] = [
                'email'        => $student->email,
                'mail'         => new AttendanceNotificationMail(
                    studentName:   $studentName,
                    teacherName:   $teacherName,
                    classroomName: $classroom->name,
                    dateLabel:     $dateLabel,
                    timeLabel:     $timeLabel,
                    status:        $status,
                    statusLabel:   $statusLabels[$status] ?? '—',
                    absentCount:   $absentCount,
                    absenceLimit:  $limit,
                ),
            ];
        }

        if (empty($targets)) return;

        // HTTP response'u dönmeden mail gönderme bekletme — terminating() ile defer.
        // PHP-FPM'de fastcgi_finish_request() benzeri davranış: kullanıcı yanıtı alır,
        // sonra arka planda mailler gönderilir.
        app()->terminating(function () use ($targets) {
            foreach ($targets as $t) {
                try {
                    Mail::to($t['email'])->send($t['mail']);
                } catch (\Throwable $e) {
                    Log::warning('Yoklama e-posta gönderim hatası', [
                        'to'    => $t['email'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });
    }

    /**
     * GET /api/attendances
     * Bu kullanıcının tüm sınıflarındaki tüm yoklama oturumları (gün+sınıf bazlı özet).
     */
    public function history(Request $request): JsonResponse
    {
        $user = $request->user();
        $showArchived = (bool) ($user->preferences['showArchivedInReports'] ?? false);

        $classroomIds = Classroom::where('user_id', $user->id)
            ->when(! $showArchived, fn ($q) => $q->whereNull('archived_at'))
            ->pluck('id');

        $driver = DB::connection()->getDriverName();
        $dateExpr = $driver === 'pgsql'
            ? "to_char(date, 'YYYY-MM-DD')"
            : "DATE_FORMAT(date, '%Y-%m-%d')";

        $sessions = Attendance::query()
            ->whereIn('classroom_id', $classroomIds)
            ->select(
                'classroom_id',
                DB::raw("$dateExpr as date"),
                DB::raw("SUM(CASE WHEN status='present' THEN 1 ELSE 0 END) as present"),
                DB::raw("SUM(CASE WHEN status='absent' THEN 1 ELSE 0 END) as absent"),
                DB::raw("SUM(CASE WHEN status='late' THEN 1 ELSE 0 END) as late"),
                DB::raw("SUM(CASE WHEN status='excused' THEN 1 ELSE 0 END) as excused"),
                DB::raw('COUNT(*) as total'),
            )
            ->groupBy('classroom_id', DB::raw($dateExpr))
            ->orderByDesc(DB::raw($dateExpr))
            ->get();

        $classrooms = Classroom::whereIn('id', $sessions->pluck('classroom_id')->unique())->get()->keyBy('id');

        $payload = $sessions->map(function ($s) use ($classrooms) {
            $c = $classrooms[$s->classroom_id] ?? null;
            $dateStr = Carbon::parse($s->date)->toDateString();
            return [
                'id'             => $s->classroom_id.'-'.$dateStr,
                'classroom_id'   => $s->classroom_id,
                'classroom_name' => $c?->name,
                'department'     => $c?->department,
                'time'           => $c?->time,
                'date'           => $dateStr,
                'present'        => (int) $s->present,
                'absent'         => (int) $s->absent,
                'late'           => (int) $s->late,
                'excused'        => (int) $s->excused,
                'total'          => (int) $s->total,
                'is_archived'    => $c?->archived_at !== null,
            ];
        });

        return $this->successResponse($payload);
    }

    /**
     * GET /api/classrooms/{classroom}/attendances/{date}
     * Tek bir günün detay yoklaması (öğrenci listesi + status).
     */
    public function day(Request $request, Classroom $classroom, string $date): JsonResponse
    {
        $this->authorizeClassroom($request, $classroom);

        $parsedDate = Carbon::parse($date)->toDateString();

        $records = Attendance::where('classroom_id', $classroom->id)
            ->where('date', $parsedDate)
            ->with('student')
            ->get();

        if ($records->isEmpty()) {
            return $this->notFoundResponse('Bu tarihte yoklama kaydı yok.');
        }

        $payload = [
            'date' => $parsedDate,
            'classroom' => [
                'id'         => $classroom->id,
                'name'       => $classroom->name,
                'department' => $classroom->department,
                'time'       => $classroom->time,
                'day'        => $classroom->day,
            ],
            'totals' => [
                'present' => $records->where('status', 'present')->count(),
                'absent'  => $records->where('status', 'absent')->count(),
                'late'    => $records->where('status', 'late')->count(),
                'excused' => $records->where('status', 'excused')->count(),
                'total'   => $records->count(),
            ],
            'records' => $records->map(fn ($r) => [
                'id'             => $r->id,
                'student_id'     => $r->student_id,
                'student_number' => $r->student?->student_number,
                'name'           => $r->student?->name,
                'status'         => $r->status,
            ])->values(),
        ];

        return $this->successResponse($payload);
    }

    /**
     * GET /api/risk-students
     * Tüm sınıflarındaki risk öğrencileri (sınıra yakın veya aşmış).
     */
    public function riskStudents(Request $request): JsonResponse
    {
        $user = $request->user();
        $limit = $this->absenceLimitFor($user);

        $classroomIds = Classroom::where('user_id', $user->id)->whereNull('archived_at')->pluck('id');

        $absences = Attendance::query()
            ->whereIn('classroom_id', $classroomIds)
            ->where('status', 'absent')
            ->select('student_id', 'classroom_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('student_id', 'classroom_id')
            ->havingRaw('COUNT(*) >= ?', [max(1, $limit - 1)])
            ->orderByRaw('COUNT(*) DESC')
            ->get();

        $studentIds = $absences->pluck('student_id')->unique();
        $students = Student::with('classroom')->whereIn('id', $studentIds)->get()->keyBy('id');

        $payload = $absences->map(function ($a) use ($students, $limit) {
            $s = $students[$a->student_id] ?? null;
            $count = (int) $a->cnt;
            return [
                'student_id'     => $a->student_id,
                'name'           => $s?->name,
                'email'          => $s?->email,
                'classroom_id'   => $a->classroom_id,
                'classroom_name' => $s?->classroom?->name,
                'absent'         => $count,
                'limit'          => $limit,
                'status'         => $count >= $limit ? 'Kaldı' : 'Sınırda',
            ];
        });

        return $this->successResponse([
            'limit'   => $limit,
            'students' => $payload,
        ]);
    }

    /**
     * GET /api/dashboard
     * Dashboard sayfası için toplu istatistik.
     */
    public function dashboardStats(Request $request): JsonResponse
    {
        $user = $request->user();
        $limit = $this->absenceLimitFor($user);

        $classrooms = Classroom::where('user_id', $user->id)
            ->whereNull('archived_at')
            ->withCount('students')
            ->get();
        $classroomIds = $classrooms->pluck('id');

        $totalStudents = (int) $classrooms->sum('students_count');
        $activeClasses = $classrooms->where('status', 'Aktif')->count();
        $completedClasses = $classrooms->where('status', 'Tamamlandı')->count();

        // Genel katılım yüzdesi
        // Mazeretli (excused) kayıtlar oranı bozmasın diye paydadan düşülür.
        $totalAtt   = Attendance::whereIn('classroom_id', $classroomIds)->count();
        $presentAtt = Attendance::whereIn('classroom_id', $classroomIds)
            ->whereIn('status', ['present', 'late'])
            ->count();
        $excusedAtt = Attendance::whereIn('classroom_id', $classroomIds)
            ->where('status', 'excused')
            ->count();
        $effectiveTotal = $totalAtt - $excusedAtt;
        $attendanceRate = $effectiveTotal > 0 ? round(($presentAtt / $effectiveTotal) * 100, 1) : null;

        // Risk öğrenciler (tek seferde)
        $absences = Attendance::query()
            ->whereIn('classroom_id', $classroomIds)
            ->where('status', 'absent')
            ->select('student_id', 'classroom_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('student_id', 'classroom_id')
            ->havingRaw('COUNT(*) >= ?', [max(1, $limit - 1)])
            ->get();

        $riskCount = $absences->count();

        // Top 3 risk
        $top3 = $absences->sortByDesc('cnt')->take(3);
        $topStudentIds = $top3->pluck('student_id');
        $topStudents = Student::with('classroom')->whereIn('id', $topStudentIds)->get()->keyBy('id');
        $topRiskStudents = $top3->map(function ($a) use ($topStudents, $limit) {
            $s = $topStudents[$a->student_id] ?? null;
            $cnt = (int) $a->cnt;
            return [
                'student_id'     => $a->student_id,
                'name'           => $s?->name,
                'email'          => $s?->email,
                'classroom_name' => $s?->classroom?->name,
                'absent'         => $cnt,
                'limit'          => $limit,
                'status'         => $cnt >= $limit ? 'Kaldı' : 'Sınırda',
            ];
        })->values();

        // Trend: son 6 hafta katılım %
        $trend = [];
        for ($i = 5; $i >= 0; $i--) {
            $start = Carbon::now()->subWeeks($i)->startOfWeek()->toDateString();
            $end = Carbon::now()->subWeeks($i)->endOfWeek()->toDateString();
            $weekTotal = Attendance::whereIn('classroom_id', $classroomIds)
                ->whereBetween('date', [$start, $end])->count();
            $weekPresent = Attendance::whereIn('classroom_id', $classroomIds)
                ->whereBetween('date', [$start, $end])
                ->whereIn('status', ['present', 'late'])->count();
            $trend[] = [
                'name' => (6 - $i).'. Hafta',
                'oran' => $weekTotal > 0 ? round(($weekPresent / $weekTotal) * 100, 1) : 0,
                'has_data' => $weekTotal > 0,
            ];
        }
        $hasAnyTrendData = collect($trend)->contains('has_data', true);

        // Bugünün dersleri — gün eşleşmesine göre + her birinin bugün yoklama durumu
        $today          = Carbon::now();
        $todayDate      = $today->toDateString();
        $daysTr         = ['Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar'];
        $todayDay       = $daysTr[$today->dayOfWeekIso - 1];

        // Bugün tatil mi?
        $todayHoliday = Holiday::where('user_id', $user->id)
            ->where('date', $todayDate)
            ->first();
        $todayHolidayPayload = $todayHoliday ? [
            'id'   => $todayHoliday->id,
            'name' => $todayHoliday->name,
            'type' => $todayHoliday->type,
            'date' => $todayDate,
        ] : null;

        $todayClassroomsRaw = $classrooms
            ->where('status', 'Aktif')
            ->filter(fn ($c) => $c->day === $todayDay)
            ->sortBy('time')
            ->values();

        // Hangi sınıfların bugün attendance kaydı var?
        $attendedTodayIds = Attendance::whereIn('classroom_id', $todayClassroomsRaw->pluck('id'))
            ->where('date', $todayDate)
            ->select('classroom_id')
            ->distinct()
            ->pluck('classroom_id')
            ->all();

        $todayClasses = $todayClassroomsRaw->map(fn ($c) => [
            'id'             => $c->id,
            'name'           => $c->name,
            'department'     => $c->department,
            'day'            => $c->day,
            'time'           => $c->time,
            'students_count' => $c->students_count ?? 0,
            'attended_today' => in_array($c->id, $attendedTodayIds, true),
        ])->values();

        $todayDoneCount    = $todayClasses->where('attended_today', true)->count();
        $todayPendingCount = $todayClasses->count() - $todayDoneCount;

        return $this->successResponse([
            'totalClasses'      => $classrooms->count(),
            'totalStudents'     => $totalStudents,
            'activeClasses'     => $activeClasses,
            'completedClasses'  => $completedClasses,
            'attendanceRate'    => $attendanceRate,
            'riskCount'         => $riskCount,
            'topRiskStudents'   => $topRiskStudents,
            'trend'             => $hasAnyTrendData ? $trend : [],

            // Bugün-bazlı veri
            'todayDay'          => $todayDay,
            'todayDate'         => $todayDate,
            'todayClasses'      => $todayClasses,
            'todayDoneCount'    => $todayDoneCount,
            'todayPendingCount' => $todayPendingCount,
            'todayHoliday'      => $todayHolidayPayload,

            // Geriye uyum: upcomingClasses === todayClasses
            'upcomingClasses'   => $todayClasses,
            'limit'             => $limit,
        ]);
    }

    /**
     * GET /api/classrooms/{classroom}/stats
     * Tek bir sınıfın detay raporu (ClassStatistics sayfası için).
     */
    public function classStats(Request $request, Classroom $classroom): JsonResponse
    {
        $this->authorizeClassroom($request, $classroom);

        $user = $request->user();
        $limit = $this->absenceLimitFor($user);

        $students = $classroom->students()->orderBy('student_number')->get();

        // Bu sınıfa ait tüm attendance kayıtları
        $attendances = Attendance::where('classroom_id', $classroom->id)->get();
        $bySession = $attendances->groupBy(fn ($a) => $a->date->toDateString());

        // Öğrenci bazlı istatistik
        $byStudent = $attendances->groupBy('student_id');
        $studentStats = $students->map(function ($s) use ($byStudent, $limit) {
            $records = $byStudent[$s->id] ?? collect();
            $present = $records->whereIn('status', ['present', 'late'])->count();
            $absent  = $records->where('status', 'absent')->count();
            $excused = $records->where('status', 'excused')->count();
            // Mazeretli sınır hesabına KATILMAZ
            $status = $absent >= $limit ? 'Kaldı' : ($absent >= $limit - 1 && $absent > 0 ? 'Sınırda' : 'Güvenli');
            return [
                'student_id'     => $s->id,
                'student_number' => $s->student_number,
                'name'           => $s->name,
                'totalPresent'   => $present,
                'totalAbsent'    => $absent,
                'totalExcused'   => $excused,
                'limitLeft'      => max(0, $limit - $absent),
                'status'         => $status,
            ];
        })->values();

        $safeCount = $studentStats->where('status', 'Güvenli')->count();
        $borderlineCount = $studentStats->where('status', 'Sınırda')->count();
        $failedCount = $studentStats->where('status', 'Kaldı')->count();

        // Mazeretli oranı bozmasın diye paydadan düşülür.
        $totalAtt   = $attendances->count();
        $presentAtt = $attendances->whereIn('status', ['present', 'late'])->count();
        $excusedAtt = $attendances->where('status', 'excused')->count();
        $effectiveTotal = $totalAtt - $excusedAtt;
        $attendanceRate = $effectiveTotal > 0 ? round(($presentAtt / $effectiveTotal) * 100, 1) : null;

        // Haftalık katılım/devamsızlık (son 4 hafta)
        $weeklyTrend = [];
        for ($i = 3; $i >= 0; $i--) {
            $start = Carbon::now()->subWeeks($i)->startOfWeek()->toDateString();
            $end = Carbon::now()->subWeeks($i)->endOfWeek()->toDateString();
            $weekRecords = $attendances->filter(fn ($a) => $a->date->toDateString() >= $start && $a->date->toDateString() <= $end);
            $weeklyTrend[] = [
                'week'     => (4 - $i).'. Hafta',
                'katilim'  => $weekRecords->whereIn('status', ['present', 'late'])->count(),
                'devamsiz' => $weekRecords->where('status', 'absent')->count(),
            ];
        }
        $hasWeeklyData = collect($weeklyTrend)->contains(fn ($w) => $w['katilim'] > 0 || $w['devamsiz'] > 0);

        return $this->successResponse([
            'classroom' => [
                'id'             => $classroom->id,
                'name'           => $classroom->name,
                'department'     => $classroom->department,
                'students_count' => $students->count(),
                'session_count'  => $bySession->count(),
            ],
            'limit'           => $limit,
            'attendanceRate'  => $attendanceRate,
            'safeCount'       => $safeCount,
            'borderlineCount' => $borderlineCount,
            'failedCount'     => $failedCount,
            'students'        => $studentStats,
            'weeklyTrend'     => $hasWeeklyData ? $weeklyTrend : [],
            'hasData'         => $totalAtt > 0,
        ]);
    }

    private function absenceLimitFor($user): int
    {
        $defaults = AuthService::defaultPreferences();
        $prefs = $user->preferences ?? $defaults;
        $val = (int) ($prefs['defaultAbsenceLimit'] ?? $defaults['defaultAbsenceLimit']);
        return max(1, min(20, $val));
    }

    private function authorizeClassroom(Request $request, Classroom $classroom): void
    {
        if ($classroom->user_id !== $request->user()->id) {
            abort(response()->json([
                'success' => false,
                'message' => 'Bu sınıfa erişim yetkiniz yok.',
            ], 403));
        }
    }
}
