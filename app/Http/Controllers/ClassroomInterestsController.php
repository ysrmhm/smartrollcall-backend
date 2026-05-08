<?php

namespace App\Http\Controllers;

use App\Models\Classroom;
use App\Models\Interest;
use App\Models\Student;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClassroomInterestsController extends Controller
{
    use ApiResponseTrait;

    /**
     * GET /api/classrooms/{classroom}/interests
     * Bir sınıfın "Sınıf Profili": öğrencilerin ilgi alanları + ortalama seviye.
     */
    public function show(Request $request, Classroom $classroom): JsonResponse
    {
        $this->authorize($request, $classroom);

        $studentIds = Student::where('classroom_id', $classroom->id)->pluck('id');
        $totalStudents = $studentIds->count();

        if ($totalStudents === 0) {
            return $this->successResponse([
                'classroom'      => $this->classroomMeta($classroom),
                'total_students' => 0,
                'with_profile'   => 0,
                'categories'     => [],
                'top_interests'  => [],
                'by_category'    => [],
            ]);
        }

        // Profili olan (en az 1 ilgi seçmiş) öğrenci sayısı
        $withProfile = DB::table('student_interests')
            ->whereIn('student_id', $studentIds)
            ->distinct('student_id')
            ->count('student_id');

        // İlgi başına: kaç öğrenci seçmiş + ortalama level
        $rows = DB::table('student_interests as si')
            ->join('interests as i', 'i.id', '=', 'si.interest_id')
            ->whereIn('si.student_id', $studentIds)
            ->select(
                'i.id', 'i.name', 'i.category', 'i.icon',
                DB::raw('COUNT(DISTINCT si.student_id) as student_count'),
                DB::raw('ROUND(AVG(si.level), 2) as avg_level'),
            )
            ->groupBy('i.id', 'i.name', 'i.category', 'i.icon')
            ->orderByDesc('student_count')
            ->orderByDesc('avg_level')
            ->get();

        // Kategori başına popülarite
        $byCategory = $rows->groupBy('category')->map(function ($items, $cat) use ($totalStudents) {
            $totalSelections = $items->sum('student_count');
            return [
                'key'              => $cat,
                'student_count'    => $items->sum('student_count'),
                'unique_interests' => $items->count(),
                'percent_of_class' => $totalStudents > 0
                    ? round(($items->sum('student_count') / $totalStudents) * 100, 1)
                    : 0,
            ];
        })->values();

        // Top 10 ilgi (pasta için)
        $topInterests = $rows->take(10)->map(fn ($r) => [
            'id'             => (int) $r->id,
            'name'           => $r->name,
            'category'       => $r->category,
            'icon'           => $r->icon,
            'student_count'  => (int) $r->student_count,
            'avg_level'      => (float) $r->avg_level,
            'percent_of_class' => $totalStudents > 0
                ? round(((int) $r->student_count / $totalStudents) * 100, 1)
                : 0,
        ])->values();

        // Tüm ilgi alanları (ham data — bar chart frontend'de filtrelenebilir)
        $allInterests = $rows->map(fn ($r) => [
            'id'             => (int) $r->id,
            'name'           => $r->name,
            'category'       => $r->category,
            'icon'           => $r->icon,
            'student_count'  => (int) $r->student_count,
            'avg_level'      => (float) $r->avg_level,
            'percent_of_class' => $totalStudents > 0
                ? round(((int) $r->student_count / $totalStudents) * 100, 1)
                : 0,
        ])->values();

        return $this->successResponse([
            'classroom'      => $this->classroomMeta($classroom),
            'total_students' => $totalStudents,
            'with_profile'   => $withProfile,
            'categories'     => $byCategory,
            'top_interests'  => $topInterests,
            'all_interests'  => $allInterests,
        ]);
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
