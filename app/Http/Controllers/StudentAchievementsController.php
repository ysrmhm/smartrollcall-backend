<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Student;
use App\Traits\ApiResponseTrait;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StudentAchievementsController extends Controller
{
    use ApiResponseTrait;

    /**
     * GET /api/student/achievements
     * İçerik:
     *  - student
     *  - stats (toplam XP, level, attendance metrics)
     *  - career  (bölüme özel yolculuk: tema, mevcut/sonraki unvan, 5-stepper)
     *  - badges  (20 adet, earned/locked + progress)
     *  - showcase (öğrencinin sergilediği 3 rozet kodu)
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Student $student */
        $student = $request->user();
        $student->loadMissing('classroom');

        // Aynı student_number için 60 sn cache.
        // Yoklama/onay/showcase değişiminde invalidate edilir (bk. invalidateFor).
        $cacheKey = 'student_ach:'.$student->student_number;
        $payload = Cache::remember($cacheKey, 60, function () use ($student) {
            $studentIds = Student::where('student_number', $student->student_number)->pluck('id');

            $records = Attendance::with('classroom:id,day,name,department')
                ->whereIn('student_id', $studentIds)
                ->orderBy('date')
                ->get();

            $stats   = $this->computeStats($records);
            $xpRules = config('career_paths.xp_rules');
            $stats['total_xp'] = $this->computeTotalXp($records, $stats, $xpRules);

            $career  = $this->buildCareer($student, $stats['total_xp']);
            $stats['level']         = $career['current_level'];
            $stats['current_title'] = $career['current_title'];

            $badges  = $this->computeBadges($records, $stats);

            $showcase = DB::table('student_showcase_badges')
                ->whereIn('student_id', $studentIds)
                ->orderBy('position')
                ->get(['badge_code', 'position'])
                ->groupBy('badge_code')
                ->map(fn ($g) => $g->first()->position)
                ->all();

            $earnedCodes = collect($badges)->where('earned', true)->pluck('code')->all();
            $showcaseValid = collect($showcase)
                ->filter(fn ($pos, $code) => in_array($code, $earnedCodes, true))
                ->all();

            return [
                'student'  => [
                    'name'           => $student->name,
                    'student_number' => $student->student_number,
                    'department'     => $student->classroom?->department,
                ],
                'stats'    => $stats,
                'career'   => $career,
                'badges'   => $badges,
                'showcase' => $showcaseValid,
            ];
        });

        return $this->successResponse($payload);
    }

    /**
     * Yoklama/showcase değiştiğinde dışarıdan tetiklenir.
     * Örn: AttendanceController::store() ve updateShowcase() içinden çağrılır.
     */
    public static function invalidateFor(string $studentNumber): void
    {
        Cache::forget('student_ach:'.$studentNumber);
    }

    /**
     * PUT /api/student/achievements/showcase
     * Body: { badges: ['code1','code2','code3'] }  (max 3, sıralı)
     * Sadece kazanılmış rozetleri kabul eder.
     */
    public function updateShowcase(Request $request): JsonResponse
    {
        $data = $request->validate([
            'badges'   => ['present', 'array', 'max:3'], // boş array kabul: tüm sergilenenleri kaldır
            'badges.*' => ['string', 'max:60'],
        ]);

        /** @var Student $student */
        $student = $request->user();
        $studentIds = Student::where('student_number', $student->student_number)->pluck('id')->all();

        // Kazanılan rozetleri kontrol
        $records = Attendance::with('classroom:id,day,name,department')
            ->whereIn('student_id', $studentIds)
            ->orderBy('date')
            ->get();
        $stats = $this->computeStats($records);
        $stats['total_xp'] = $this->computeTotalXp($records, $stats, config('career_paths.xp_rules'));
        $career = $this->buildCareer($student, $stats['total_xp']);
        $stats['level'] = $career['current_level'];
        $stats['current_title'] = $career['current_title'];
        $earnedCodes = collect($this->computeBadges($records, $stats))
            ->where('earned', true)
            ->pluck('code')
            ->all();

        $valid = array_values(array_filter(
            array_unique($data['badges']),
            fn ($code) => in_array($code, $earnedCodes, true)
        ));

        DB::transaction(function () use ($student, $studentIds, $valid) {
            // Tüm Student satırları için showcase verisini sil-ekle
            DB::table('student_showcase_badges')->whereIn('student_id', $studentIds)->delete();
            $now = Carbon::now();
            $rows = [];
            foreach ($valid as $i => $code) {
                foreach ($studentIds as $sid) {
                    $rows[] = [
                        'student_id' => $sid,
                        'badge_code' => $code,
                        'position'   => $i + 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
            if (! empty($rows)) {
                DB::table('student_showcase_badges')->insert($rows);
            }
        });

        self::invalidateFor($student->student_number);

        return $this->successResponse([
            'showcase' => collect($valid)->mapWithKeys(fn ($c, $i) => [$c => $i + 1])->all(),
            'count'    => count($valid),
        ], count($valid).' rozet sergilemeye seçildi.');
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function computeStats($records): array
    {
        $present = $records->where('status', 'present')->count();
        $late    = $records->where('status', 'late')->count();
        $absent  = $records->where('status', 'absent')->count();
        $excused = $records->where('status', 'excused')->count();
        $total   = $records->count();

        $countAttended = $present + $late;
        $effectiveTotal = $total - $excused;
        $rate = $effectiveTotal > 0 ? round(($countAttended / $effectiveTotal) * 100, 1) : null;

        // Ardışık seri (oturum bazlı)
        $longestStreak = 0;
        $currentStreak = 0;
        foreach ($records as $r) {
            if ($r->status === 'absent') {
                $currentStreak = 0;
            } else {
                $currentStreak++;
                $longestStreak = max($longestStreak, $currentStreak);
            }
        }

        // Hafta hafta perfect
        $byWeek = $records->groupBy(fn ($r) => Carbon::parse($r->date)->startOfWeek()->toDateString());
        $weeks = $byWeek->keys()->sort()->values();
        $consecutivePerfect = 0;
        $maxConsecutivePerfect = 0;
        $expectedWeek = null;
        foreach ($weeks as $weekStart) {
            $rows = $byWeek[$weekStart];
            $hasAbsent = $rows->where('status', 'absent')->count() > 0;
            $thisWeek = Carbon::parse($weekStart);
            if ($expectedWeek && $thisWeek->ne($expectedWeek)) {
                $consecutivePerfect = 0;
            }
            if (! $hasAbsent) {
                $consecutivePerfect++;
                $maxConsecutivePerfect = max($maxConsecutivePerfect, $consecutivePerfect);
            } else {
                $consecutivePerfect = 0;
            }
            $expectedWeek = $thisWeek->copy()->addWeek();
        }

        $byDay = $records->whereIn('status', ['present', 'late'])
            ->groupBy(fn ($r) => $r->classroom?->day ?: '—')
            ->map->count()
            ->sortDesc();
        $favoriteDay = $byDay->keys()->first();
        $favoriteDayCount = $byDay->first() ?? 0;

        $now = Carbon::now();
        $thisMonthStart = $now->copy()->startOfMonth();
        $lastMonthStart = $now->copy()->subMonth()->startOfMonth();
        $lastMonthEnd   = $now->copy()->startOfMonth()->subSecond();

        $thisMonth = $records->filter(fn ($r) => Carbon::parse($r->date)->between($thisMonthStart, $now));
        $lastMonth = $records->filter(fn ($r) => Carbon::parse($r->date)->between($lastMonthStart, $lastMonthEnd));

        $rateOf = function ($subset) {
            $p = $subset->whereIn('status', ['present', 'late'])->count();
            $e = $subset->where('status', 'excused')->count();
            $t = $subset->count() - $e;
            return $t > 0 ? round(($p / $t) * 100, 1) : null;
        };

        $thisMonthAbsent = $thisMonth->where('status', 'absent')->count();
        $thisMonthTotal  = $thisMonth->count();

        $firstSession = $records->first()?->date?->toDateString();

        return [
            'total_sessions'            => $total,
            'present'                   => $present,
            'late'                      => $late,
            'absent'                    => $absent,
            'excused'                   => $excused,
            'attended'                  => $countAttended,
            'attendance_rate'           => $rate,
            'longest_streak'            => $longestStreak,
            'current_streak'            => $currentStreak,
            'consecutive_perfect_weeks' => $maxConsecutivePerfect,
            'favorite_day'              => $favoriteDay,
            'favorite_day_count'        => $favoriteDayCount,
            'this_month_rate'           => $rateOf($thisMonth),
            'last_month_rate'           => $rateOf($lastMonth),
            'this_month_absent'         => $thisMonthAbsent,
            'this_month_total'          => $thisMonthTotal,
            'first_session_date'        => $firstSession,
        ];
    }

    private function computeTotalXp($records, array $stats, array $rules): int
    {
        $xp = 0;
        $xp += ($rules['present'] ?? 10) * $stats['present'];
        $xp += ($rules['late']    ?? 5)  * $stats['late'];
        $xp += ($rules['excused'] ?? 3)  * $stats['excused'];

        // 4-haftalık streak bonus — kazanılan her tam 4'lük blok için
        $blocks = intdiv($stats['consecutive_perfect_weeks'], 4);
        $xp += $blocks * ($rules['streak_4weeks_bonus'] ?? 50);

        return (int) $xp;
    }

    private function buildCareer(Student $student, int $totalXp): array
    {
        $department = $student->classroom?->department;
        $paths = config('career_paths.paths');
        $path = $paths[$department] ?? config('career_paths.default');

        $thresholds = config('career_paths.thresholds'); // [1=>0, 2=>100, ...]

        // Mevcut seviye
        $level = 1;
        foreach ($thresholds as $lvl => $req) {
            if ($totalXp >= $req) $level = $lvl;
        }
        $maxLevel = max(array_keys($thresholds));

        $currentTitle = $path['titles'][$level] ?? '—';
        $nextLevel = $level < $maxLevel ? $level + 1 : null;
        $nextTitle = $nextLevel ? ($path['titles'][$nextLevel] ?? '—') : null;
        $nextThreshold = $nextLevel ? $thresholds[$nextLevel] : null;
        $prevThreshold = $thresholds[$level] ?? 0;

        $xpToNext = $nextThreshold !== null ? max(0, $nextThreshold - $totalXp) : 0;
        $progressInLevel = $nextThreshold !== null
            ? min(1.0, max(0, ($totalXp - $prevThreshold) / max(1, $nextThreshold - $prevThreshold)))
            : 1.0;

        // 5-stepper
        $steps = [];
        foreach ($thresholds as $lvl => $req) {
            $steps[] = [
                'level'      => $lvl,
                'title'      => $path['titles'][$lvl] ?? '—',
                'xp_required' => $req,
                'unlocked'   => $totalXp >= $req,
                'is_current' => $lvl === $level,
            ];
        }

        return [
            'department'        => $department,
            'theme'             => $path['theme'] ?? '—',
            'icon'              => $path['icon'] ?? 'GraduationCap',
            'color'             => $path['color'] ?? 'primary',
            'current_level'     => $level,
            'current_title'     => $currentTitle,
            'next_level'        => $nextLevel,
            'next_title'        => $nextTitle,
            'xp_to_next'        => $xpToNext,
            'progress_in_level' => round($progressInLevel, 3),
            'max_level_reached' => $level === $maxLevel,
            'steps'             => $steps,
        ];
    }

    private function computeBadges($records, array $stats): array
    {
        $now = Carbon::now();
        $last30Start = $now->copy()->subDays(30);
        $prev30Start = $now->copy()->subDays(60);
        $prev30End   = $last30Start->copy()->subSecond();

        $last30 = $records->filter(fn ($r) => Carbon::parse($r->date)->between($last30Start, $now));
        $prev30 = $records->filter(fn ($r) => Carbon::parse($r->date)->between($prev30Start, $prev30End));

        $last30Absent = $last30->where('status', 'absent')->count();
        $last30Total  = $last30->count();
        $prev30Absent = $prev30->where('status', 'absent')->count();

        $totalAttended  = $stats['attended'];
        $rate           = $stats['attendance_rate'];
        $total          = $stats['total_sessions'];
        $absent         = $stats['absent'];
        $late           = $stats['late'];
        $level          = $stats['level'];
        $perfectWeeks   = $stats['consecutive_perfect_weeks'];
        $thisMonthAbs   = $stats['this_month_absent'];
        $thisMonthTotal = $stats['this_month_total'];

        $defs = [
            // === Mevcut 9 (refined) ===
            $this->progressBadge('first_step', 'İlk Adım', 'İlk yoklamana katıldın.',
                'Sparkles', 'pink', current: min($totalAttended, 1), target: 1, criteria: '1 oturuma katıl'),
            $this->progressBadge('rookie_5', 'Çırak', '5 ders oturumuna katıldın.',
                'Target', 'sky', current: min($totalAttended, 5), target: 5, criteria: '5+ oturum'),
            $this->progressBadge('half_way_10', 'Yarı Yol', '10 ders oturumuna katıldın.',
                'Award', 'violet', current: min($totalAttended, 10), target: 10, criteria: '10+ oturum'),
            $this->progressBadge('veteran_20', 'Veteran', '20 ders oturumuna katıldın.',
                'Medal', 'indigo', current: min($totalAttended, 20), target: 20, criteria: '20+ oturum'),
            $this->thresholdBadge('punctual', 'Dakik', 'Hiç geç gelmedin (en az 4 oturum).',
                'Clock', 'blue',
                earned: $total >= 4 && $late === 0,
                detail: $total < 4 ? "{$total}/4 oturum" : ($late === 0 ? 'Hiç geç gelmedin' : "{$late} kez geç geldin"),
                criteria: '0 geç + 4+ oturum'),
            $this->thresholdBadge('diligent_90', 'Çalışkan', 'Genel katılımın %90+.',
                'Star', 'emerald',
                earned: $total >= 4 && $rate !== null && $rate >= 90,
                detail: $rate === null ? 'Veri yok' : "Mevcut: %{$rate}",
                criteria: '%90+ katılım'),
            $this->progressBadge('consistency_4w', 'İstikrar', '4 hafta üst üste eksiksiz katıldın.',
                'Flame', 'orange', current: min($perfectWeeks, 4), target: 4,
                criteria: '4 hafta üst üste eksiksiz'),
            $this->thresholdBadge('comeback', 'Geri Dönüş', 'Önceki ay yok varken son 30 günde sıfırladın.',
                'TrendingUp', 'teal',
                earned: $prev30Absent >= 1 && $last30Absent === 0 && $last30Total >= 2,
                detail: $prev30Absent === 0 ? 'Önceki ay temiz' : ($last30Absent === 0 ? 'Son 30 günde 0 yok' : "Son 30 günde {$last30Absent} yok"),
                criteria: 'Son 30 gün 0 yok'),
            $this->thresholdBadge('flawless', 'Kusursuz Katılım', 'Hiç devamsızlığın yok (en az 6 oturum).',
                'Trophy', 'yellow',
                earned: $total >= 6 && $absent === 0,
                detail: $total < 6 ? "{$total}/6 oturum" : ($absent === 0 ? 'Mükemmel!' : "{$absent} devamsızlık"),
                criteria: '0 devamsızlık + 6+ oturum'),

            // === Yeni 11 ===
            $this->progressBadge('centurion', 'Yüzbaşı', '100 ders oturumuna ulaştın.',
                'Crown', 'amber', current: min($totalAttended, 100), target: 100,
                criteria: '100+ oturum'),
            $this->progressBadge('iron_will', 'Demir Disiplin', '8 hafta üst üste eksiksiz katılım.',
                'Shield', 'red', current: min($perfectWeeks, 8), target: 8,
                criteria: '8 hafta üst üste eksiksiz'),
            $this->thresholdBadge('honors', 'Onur Listesi', 'Genel katılımın %95+ (en az 10 oturum).',
                'Medal', 'cyan',
                earned: $total >= 10 && $rate !== null && $rate >= 95,
                detail: $rate === null ? 'Veri yok' : "Mevcut: %{$rate}",
                criteria: '%95+ katılım + 10+ oturum'),
            $this->thresholdBadge('perfect_month', 'Mükemmel Ay', 'Bu ay sıfır devamsızlık.',
                'Calendar', 'emerald',
                earned: $thisMonthTotal >= 4 && $thisMonthAbs === 0,
                detail: $thisMonthTotal < 4 ? "{$thisMonthTotal}/4 oturum bu ay" : ($thisMonthAbs === 0 ? 'Mükemmel ay!' : "{$thisMonthAbs} yok bu ay"),
                criteria: 'Bu ay 0 yok + 4+ oturum'),

            // Seviye rozetleri
            $this->thresholdBadge('lvl_2_unlocked', 'İlk Yükseliş', 'Kariyerinde ikinci seviyeye geçtin.',
                'TrendingUp', 'sky',
                earned: $level >= 2,
                detail: $level >= 2 ? "Seviye {$level}'e ulaştın" : 'Henüz seviye 1',
                criteria: 'Seviye 2+'),
            $this->thresholdBadge('lvl_3_unlocked', 'Olgunlaşma', 'Kariyerinde üçüncü seviyeye geçtin.',
                'Layers', 'violet',
                earned: $level >= 3,
                detail: $level >= 3 ? "Seviye {$level}'e ulaştın" : "{$level}/3 seviye",
                criteria: 'Seviye 3+'),
            $this->thresholdBadge('lvl_4_unlocked', 'Kıdemli Adımı', 'Kariyerinde dördüncü seviyeye geçtin.',
                'Award', 'indigo',
                earned: $level >= 4,
                detail: $level >= 4 ? "Seviye {$level}'e ulaştın" : "{$level}/4 seviye",
                criteria: 'Seviye 4+'),
            $this->thresholdBadge('lvl_5_master', 'Zirve', 'Kariyerinde son seviyeye ulaştın.',
                'Crown', 'yellow',
                earned: $level >= 5,
                detail: $level >= 5 ? 'Tepe noktada!' : "{$level}/5 seviye",
                criteria: 'Seviye 5'),

            // Davranışsal
            $this->thresholdBadge('early_riser', 'Erken Ders Dostu', '08:00 dersine 3+ kez katıldın.',
                'Sunrise', 'orange',
                earned: $records->whereIn('status', ['present', 'late'])->filter(fn ($r) => str_starts_with((string) $r->classroom?->time, '08'))->count() >= 3,
                detail: 'Sabah 08:00 oturumları',
                criteria: '08:00 dersine 3+ kez'),
            $this->thresholdBadge('marathon', 'Maraton', '50+ ders oturumuna katıldın.',
                'Activity', 'rose',
                earned: $totalAttended >= 50,
                detail: $totalAttended >= 50 ? "Toplam {$totalAttended} oturum" : "{$totalAttended}/50",
                criteria: '50+ oturum'),
            $this->thresholdBadge('all_rounder', 'Çok Yönlü', 'Birden fazla derse düzenli katıldın.',
                'Compass', 'fuchsia',
                earned: $records->groupBy('classroom_id')->filter(fn ($g) => $g->whereIn('status', ['present', 'late'])->count() >= 5)->count() >= 2,
                detail: 'Birden fazla derste 5+ katılım',
                criteria: '2+ derste 5+ oturum'),
        ];

        return $defs;
    }

    private function progressBadge(
        string $code, string $name, string $description, string $icon, string $color,
        int $current, int $target, string $criteria
    ): array {
        $clamped = min($current, $target);
        $progress = $target > 0 ? round($clamped / $target, 3) : 0;
        return [
            'code'        => $code,
            'name'        => $name,
            'description' => $description,
            'icon'        => $icon,
            'color'       => $color,
            'earned'      => $clamped >= $target,
            'progress'    => $progress,
            'current'     => $current,
            'target'      => $target,
            'criteria'    => $criteria,
            'detail'      => "{$current}/{$target}",
        ];
    }

    private function thresholdBadge(
        string $code, string $name, string $description, string $icon, string $color,
        bool $earned, string $detail, string $criteria
    ): array {
        return [
            'code'        => $code,
            'name'        => $name,
            'description' => $description,
            'icon'        => $icon,
            'color'       => $color,
            'earned'      => $earned,
            'progress'    => $earned ? 1.0 : 0.0,
            'current'     => null,
            'target'      => null,
            'criteria'    => $criteria,
            'detail'      => $detail,
        ];
    }
}
