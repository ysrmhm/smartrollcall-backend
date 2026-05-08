<?php

namespace App\Http\Controllers;

use App\Models\Holiday;
use App\Traits\ApiResponseTrait;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class HolidayController extends Controller
{
    use ApiResponseTrait;

    /**
     * GET /api/holidays
     * Optional: ?from=YYYY-MM-DD&to=YYYY-MM-DD
     */
    public function index(Request $request): JsonResponse
    {
        $query = Holiday::where('user_id', $request->user()->id);

        if ($request->filled('from')) {
            $query->where('date', '>=', Carbon::parse($request->input('from'))->toDateString());
        }
        if ($request->filled('to')) {
            $query->where('date', '<=', Carbon::parse($request->input('to'))->toDateString());
        }

        $holidays = $query->orderBy('date')->get()->map(fn (Holiday $h) => $this->transform($h));

        return $this->successResponse($holidays);
    }

    /**
     * POST /api/holidays
     */
    public function store(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $data = $request->validate([
            'date' => [
                'required', 'date',
                Rule::unique('holidays')->where(fn ($q) => $q->where('user_id', $userId)),
            ],
            'name' => ['required', 'string', 'max:150'],
            'type' => ['nullable', Rule::in(['public', 'exam', 'custom'])],
        ]);

        $holiday = Holiday::create([
            'user_id' => $userId,
            'date'    => Carbon::parse($data['date'])->toDateString(),
            'name'    => $data['name'],
            'type'    => $data['type'] ?? 'custom',
        ]);

        return $this->createdResponse($this->transform($holiday));
    }

    /**
     * PUT /api/holidays/{holiday}
     */
    public function update(Request $request, Holiday $holiday): JsonResponse
    {
        $this->authorize($request, $holiday);

        $userId = $request->user()->id;

        $data = $request->validate([
            'date' => [
                'required', 'date',
                Rule::unique('holidays')->where(fn ($q) => $q->where('user_id', $userId))->ignore($holiday->id),
            ],
            'name' => ['required', 'string', 'max:150'],
            'type' => ['nullable', Rule::in(['public', 'exam', 'custom'])],
        ]);

        $holiday->update([
            'date' => Carbon::parse($data['date'])->toDateString(),
            'name' => $data['name'],
            'type' => $data['type'] ?? 'custom',
        ]);

        return $this->successResponse($this->transform($holiday->fresh()), 'Tatil güncellendi.');
    }

    /**
     * DELETE /api/holidays/{holiday}
     */
    public function destroy(Request $request, Holiday $holiday): JsonResponse
    {
        $this->authorize($request, $holiday);

        $holiday->delete();

        return $this->successResponse(null, 'Tatil silindi.');
    }

    /**
     * POST /api/holidays/import-public?year={yyyy}
     * Türkiye resmi tatillerini storage/app/turkish_holidays.json'dan yükler.
     */
    public function importPublic(Request $request): JsonResponse
    {
        $year = (int) $request->query('year', date('Y'));

        $jsonPath = storage_path('app/turkish_holidays.json');
        if (! file_exists($jsonPath)) {
            return $this->serverErrorResponse('Tatil şablonu bulunamadı (turkish_holidays.json).');
        }

        $data = json_decode(file_get_contents($jsonPath), true);
        if (! is_array($data)) {
            return $this->serverErrorResponse('Tatil şablonu okunamadı.');
        }

        $yearKey = (string) $year;
        $holidays = $data[$yearKey] ?? null;

        if (! $holidays || empty($holidays)) {
            return $this->errorResponse(
                "{$year} yılı için tatil şablonu mevcut değil. Lütfen manuel ekleyin veya başka bir yıl seçin.",
                422
            );
        }

        $userId = $request->user()->id;
        $existingDates = Holiday::where('user_id', $userId)
            ->whereYear('date', $year)
            ->pluck('date')
            ->map(fn ($d) => $d instanceof Carbon ? $d->toDateString() : (string) $d)
            ->all();

        $inserted = 0;
        $skipped = 0;

        DB::transaction(function () use ($holidays, $userId, $existingDates, &$inserted, &$skipped) {
            foreach ($holidays as $h) {
                if (! isset($h['date'], $h['name'])) {
                    continue;
                }
                if (in_array($h['date'], $existingDates, true)) {
                    $skipped++;
                    continue;
                }
                Holiday::create([
                    'user_id' => $userId,
                    'date'    => $h['date'],
                    'name'    => $h['name'],
                    'type'    => $h['type'] ?? 'public',
                ]);
                $inserted++;
            }
        });

        return $this->successResponse([
            'inserted'          => $inserted,
            'skipped'           => $skipped,
            'year'              => $year,
            'total_in_template' => count($holidays),
        ], "Resmi tatil import: {$inserted} eklendi, {$skipped} atlandı.");
    }

    /**
     * POST /api/holidays/bulk-range
     * Tarih aralığında her gün için bir Holiday oluşturur (Vize/Final haftaları için).
     * Body: { from, to, name, type }
     */
    public function bulkRange(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to'   => ['required', 'date', 'after_or_equal:from'],
            'name' => ['required', 'string', 'max:150'],
            'type' => ['nullable', Rule::in(['public', 'exam', 'custom'])],
        ]);

        $start = Carbon::parse($data['from'])->startOfDay();
        $end   = Carbon::parse($data['to'])->startOfDay();

        $totalDays = (int) $start->diffInDays($end) + 1;
        if ($totalDays > 30) {
            return $this->errorResponse('En fazla 30 günlük aralık seçebilirsiniz.', 422);
        }

        $userId = $request->user()->id;
        $existingDates = Holiday::where('user_id', $userId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->pluck('date')
            ->map(fn ($d) => $d instanceof Carbon ? $d->toDateString() : (string) $d)
            ->all();

        $inserted = 0;
        $skipped = 0;

        DB::transaction(function () use ($start, $end, $data, $userId, $existingDates, &$inserted, &$skipped) {
            $cur = $start->copy();
            while ($cur->lessThanOrEqualTo($end)) {
                $dateStr = $cur->toDateString();
                if (in_array($dateStr, $existingDates, true)) {
                    $skipped++;
                } else {
                    Holiday::create([
                        'user_id' => $userId,
                        'date'    => $dateStr,
                        'name'    => $data['name'],
                        'type'    => $data['type'] ?? 'custom',
                    ]);
                    $inserted++;
                }
                $cur->addDay();
            }
        });

        return $this->successResponse([
            'inserted' => $inserted,
            'skipped'  => $skipped,
            'days'     => $totalDays,
        ], "Aralık ekleme: {$inserted} eklendi, {$skipped} atlandı.");
    }

    /**
     * POST /api/holidays/copy-year
     * Body: { from: 2025, to: 2026 } — kullanıcının from yılındaki tatilleri to yılına kopyalar.
     */
    public function copyYear(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'integer', 'min:2020', 'max:2099'],
            'to'   => ['required', 'integer', 'min:2020', 'max:2099', 'different:from'],
        ]);

        $userId = $request->user()->id;
        $diffYears = $data['to'] - $data['from'];

        $sourceHolidays = Holiday::where('user_id', $userId)
            ->whereYear('date', $data['from'])
            ->get();

        if ($sourceHolidays->isEmpty()) {
            return $this->errorResponse("{$data['from']} yılında kopyalanacak tatil yok.", 422);
        }

        $existingDatesInTarget = Holiday::where('user_id', $userId)
            ->whereYear('date', $data['to'])
            ->pluck('date')
            ->map(fn ($d) => $d instanceof Carbon ? $d->toDateString() : (string) $d)
            ->all();

        $inserted = 0;
        $skipped = 0;

        DB::transaction(function () use ($sourceHolidays, $diffYears, $userId, $existingDatesInTarget, &$inserted, &$skipped) {
            foreach ($sourceHolidays as $h) {
                $newDate = Carbon::parse($h->date)->addYears($diffYears)->toDateString();
                if (in_array($newDate, $existingDatesInTarget, true)) {
                    $skipped++;
                    continue;
                }
                Holiday::create([
                    'user_id' => $userId,
                    'date'    => $newDate,
                    'name'    => $h->name,
                    'type'    => $h->type,
                ]);
                $inserted++;
            }
        });

        return $this->successResponse([
            'inserted' => $inserted,
            'skipped'  => $skipped,
            'from'     => $data['from'],
            'to'       => $data['to'],
        ], "Yıl kopyalama: {$inserted} eklendi, {$skipped} atlandı.");
    }

    private function authorize(Request $request, Holiday $holiday): void
    {
        if ($holiday->user_id !== $request->user()->id) {
            abort(response()->json([
                'success' => false,
                'message' => 'Bu kayda erişim yetkiniz yok.',
            ], 403));
        }
    }

    private function transform(Holiday $h): array
    {
        return [
            'id'   => $h->id,
            'date' => $h->date instanceof Carbon ? $h->date->toDateString() : $h->date,
            'name' => $h->name,
            'type' => $h->type,
        ];
    }
}
