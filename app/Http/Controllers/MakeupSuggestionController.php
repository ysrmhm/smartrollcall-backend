<?php

namespace App\Http\Controllers;

use App\Models\Classroom;
use App\Models\Holiday;
use App\Models\Student;
use App\Traits\ApiResponseTrait;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MakeupSuggestionController extends Controller
{
    use ApiResponseTrait;

    private const DAYS = ['Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma'];

    // Adayı saatler — projedeki sınıf saatleriyle uyumlu (08-17, saat başı).
    private const CANDIDATE_HOURS = ['08:00', '09:00', '10:00', '11:00', '13:00', '14:00', '15:00', '16:00'];

    /**
     * GET /api/classrooms/{classroom}/makeup-suggestions
     * Öğrencilerin ÖTEKİ sınıflardaki ders programlarını tarar, en az çakışan
     * 3 alternatif (gün, saat) önerir + her biri için sonraki 3 takvim tarihi
     * (resmi tatiller ve hocanın akademik takvimi atlanır).
     */
    public function show(Request $request, Classroom $classroom): JsonResponse
    {
        $this->authorize($request, $classroom);

        $students = Student::where('classroom_id', $classroom->id)->get();
        $studentCount = $students->count();

        if ($studentCount === 0) {
            return $this->successResponse([
                'classroom'      => $this->classroomMeta($classroom),
                'students_count' => 0,
                'suggestions'    => [],
            ]);
        }

        // Her öğrencinin BAŞKA sınıflardaki (gün, saat) busy slot'ları.
        $studentNumbers = $students->pluck('student_number')->unique();
        $busyMap = $this->buildBusyMap($studentNumbers, $classroom->id);

        // Hocanın takviminden tatiller (next 6 hafta için sonraki tarih hesabında kullanılır)
        $now = Carbon::now()->startOfDay();
        $weeksAhead = 6;
        $until = $now->copy()->addWeeks($weeksAhead);
        $holidayDates = Holiday::where('user_id', $classroom->user_id)
            ->whereBetween('date', [$now->toDateString(), $until->toDateString()])
            ->get()
            ->mapWithKeys(fn ($h) => [$h->date->toDateString() => $h->name])
            ->all();

        // Tüm aday slotları skorla
        $candidates = [];
        foreach (self::DAYS as $day) {
            foreach (self::CANDIDATE_HOURS as $hour) {
                // Mevcut sınıfın kendi slot'unu öneri olarak gösterme
                if ($day === $classroom->day && $hour === $classroom->time) continue;

                $busy = [];
                $freeCount = 0;
                foreach ($students as $s) {
                    $busySlots = $busyMap[$s->student_number] ?? [];
                    if (in_array($day.'__'.$hour, $busySlots, true)) {
                        // Hangi dersle çakışıyor — tek bir busy slot örneği yeter (dropdown'a göstermek için)
                        $busy[] = [
                            'student_id'     => $s->id,
                            'student_number' => $s->student_number,
                            'name'           => $s->name,
                        ];
                    } else {
                        $freeCount++;
                    }
                }

                $score = $studentCount > 0 ? round(($freeCount / $studentCount) * 100, 1) : 0;
                $candidates[] = [
                    'day'                => $day,
                    'time'               => $hour,
                    'free_count'         => $freeCount,
                    'busy_count'         => count($busy),
                    'students_count'     => $studentCount,
                    'score'              => $score,
                    'busy_students'      => $busy,
                    'next_dates'         => $this->nextDatesFor($day, $now, $weeksAhead, $holidayDates),
                    'rating'             => $this->ratingFor($score),
                ];
            }
        }

        // En iyi 3'ü al — score DESC, sonra (Pazartesi=1...Cuma=5) ASC, sonra saat ASC
        usort($candidates, function ($a, $b) {
            if ($a['score'] !== $b['score']) return $b['score'] <=> $a['score'];
            $da = array_search($a['day'], self::DAYS, true);
            $db = array_search($b['day'], self::DAYS, true);
            if ($da !== $db) return $da <=> $db;
            return strcmp($a['time'], $b['time']);
        });

        $top3 = array_slice($candidates, 0, 3);

        return $this->successResponse([
            'classroom'      => $this->classroomMeta($classroom),
            'students_count' => $studentCount,
            'window'         => [
                'from'  => $now->toDateString(),
                'to'    => $until->toDateString(),
                'weeks' => $weeksAhead,
            ],
            'holiday_dates'  => $holidayDates,
            'suggestions'    => $top3,
            'all_candidates' => $candidates, // ileride "diğer alternatifler" için
        ]);
    }

    /**
     * Öğrencilerin BAŞKA sınıflarındaki busy slot'larını çıkartır.
     * Aynı student_number'lı tüm Student kayıtları → bağlı oldukları classroomlar → (day, time).
     * exclude: kendi sınıfımız (telafi yapılan ders).
     */
    private function buildBusyMap($studentNumbers, int $excludeClassroomId): array
    {
        // Bu öğrencilerin tüm Student kayıtları (kendi sınıfımızdaki dahil — sonra filtreleyeceğiz)
        $rows = Student::whereIn('student_number', $studentNumbers)
            ->with('classroom:id,day,time,archived_at')
            ->get();

        $map = [];
        foreach ($rows as $r) {
            if (! $r->classroom) continue;
            if ($r->classroom->id === $excludeClassroomId) continue;
            if ($r->classroom->archived_at !== null) continue;
            if (! $r->classroom->day || ! $r->classroom->time) continue;

            $key = $r->classroom->day.'__'.$r->classroom->time;
            $map[$r->student_number] = $map[$r->student_number] ?? [];
            if (! in_array($key, $map[$r->student_number], true)) {
                $map[$r->student_number][] = $key;
            }
        }
        return $map;
    }

    /**
     * Bir gün adı için (örn. "Çarşamba") sonraki 3 takvim tarihi.
     * Tatil olan tarihler atlanır.
     */
    private function nextDatesFor(string $day, Carbon $from, int $weeksAhead, array $holidayDates): array
    {
        $dayIso = array_search($day, self::DAYS, true) + 1; // Pazartesi=1...Cuma=5
        $dates = [];
        for ($i = 0; $i < $weeksAhead * 7 && count($dates) < 3; $i++) {
            $d = $from->copy()->addDays($i);
            if ($d->dayOfWeekIso !== $dayIso) continue;
            $iso = $d->toDateString();
            if (isset($holidayDates[$iso])) continue; // tatil — atla
            $dates[] = [
                'iso'   => $iso,
                'label' => $d->locale('tr')->translatedFormat('d F Y'),
            ];
        }
        return $dates;
    }

    private function ratingFor(float $score): array
    {
        if ($score >= 95) return ['key' => 'ideal',    'label' => 'İdeal',    'color' => 'emerald'];
        if ($score >= 85) return ['key' => 'great',    'label' => 'Çok İyi',  'color' => 'sky'];
        if ($score >= 70) return ['key' => 'good',     'label' => 'İyi',      'color' => 'amber'];
        return                     ['key' => 'poor',   'label' => 'Zayıf',    'color' => 'red'];
    }

    private function classroomMeta(Classroom $c): array
    {
        return [
            'id'         => $c->id,
            'name'       => $c->name,
            'code'       => $c->code,
            'department' => $c->department,
            'day'        => $c->day,
            'time'       => $c->time,
        ];
    }

    private function authorize(Request $request, Classroom $classroom): void
    {
        if ($classroom->user_id !== $request->user()->id) {
            abort(response()->json([
                'success' => false,
                'message' => 'Bu sınıfa erişim yetkiniz yok.',
            ], 403));
        }
    }
}
