<?php

namespace App\Http\Controllers;

use App\Models\Interest;
use App\Models\Student;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StudentInterestsController extends Controller
{
    use ApiResponseTrait;

    /**
     * GET /api/student/interests
     * Hem öğrencinin seçtiklerini hem tam katalogu döner.
     * Aynı student_number'lı tüm Student kayıtlarındaki interests birleştirilir
     * (her satır için aynı interest seçimi olduğu varsayımıyla; çakışırsa max level alınır).
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Student $student */
        $student = $request->user();

        $studentIds = Student::where('student_number', $student->student_number)->pluck('id');

        // Mevcut seçimler — interest_id => max(level)
        $rows = DB::table('student_interests')
            ->whereIn('student_id', $studentIds)
            ->select('interest_id', DB::raw('MAX(level) as level'))
            ->groupBy('interest_id')
            ->get()
            ->keyBy('interest_id');

        $catalog = Interest::orderBy('category')->orderBy('name')->get();

        $items = $catalog->map(function (Interest $i) use ($rows) {
            $row = $rows[$i->id] ?? null;
            return [
                'id'       => $i->id,
                'name'     => $i->name,
                'category' => $i->category,
                'icon'     => $i->icon,
                'selected' => $row !== null,
                'level'    => $row ? (int) $row->level : null,
            ];
        });

        // Kategori meta
        $categories = [
            ['key' => 'tech',  'label' => 'Teknoloji & Yazılım'],
            ['key' => 'sport', 'label' => 'Spor'],
            ['key' => 'media', 'label' => 'Film & Kitap'],
            ['key' => 'music', 'label' => 'Müzik'],
            ['key' => 'hobby', 'label' => 'Hobi'],
        ];

        return $this->successResponse([
            'categories' => $categories,
            'items'      => $items,
            'selected_count' => $items->where('selected', true)->count(),
        ]);
    }

    /**
     * PUT /api/student/interests
     * Body: { interests: [{ interest_id, level }, ...] }
     * Tüm student kayıtlarına aynı seçimleri yazar (tam-replace mantığı).
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'interests'                => ['required', 'array'],
            'interests.*.interest_id'  => ['required', 'integer', 'exists:interests,id'],
            'interests.*.level'        => ['required', 'integer', 'min:1', 'max:5'],
        ]);

        /** @var Student $student */
        $student = $request->user();

        $studentIds = Student::where('student_number', $student->student_number)->pluck('id')->all();

        // Pivot map: interest_id => ['level' => N]
        $pivot = [];
        foreach ($data['interests'] as $row) {
            $pivot[(int) $row['interest_id']] = ['level' => (int) $row['level']];
        }

        DB::transaction(function () use ($studentIds, $pivot) {
            foreach ($studentIds as $sid) {
                Student::find($sid)?->interests()->sync($pivot);
            }
        });

        return $this->successResponse(
            ['saved' => count($pivot)],
            'İlgi alanlarınız güncellendi.'
        );
    }
}
