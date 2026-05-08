<?php

namespace App\Http\Controllers;

use App\Models\Classroom;
use App\Models\Student;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StudentController extends Controller
{
    use ApiResponseTrait;

    /**
     * GET /api/classrooms/{classroom}/students
     */
    public function index(Request $request, Classroom $classroom): JsonResponse
    {
        $this->authorize($request, $classroom);

        $students = $classroom->students()
            ->orderBy('student_number')
            ->get()
            ->map(fn (Student $s) => $this->transform($s));

        return $this->successResponse($students);
    }

    /**
     * POST /api/classrooms/{classroom}/students
     * Tek öğrenci ekleme.
     */
    public function store(Request $request, Classroom $classroom): JsonResponse
    {
        $this->authorize($request, $classroom);

        $data = $request->validate([
            'student_number' => [
                'required', 'string', 'max:30',
                Rule::unique('students')->where(fn ($q) => $q->where('classroom_id', $classroom->id)),
            ],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name'  => ['nullable', 'string', 'max:100'],
            'email'      => ['nullable', 'email', 'max:255'],
            'phone'      => ['nullable', 'string', 'max:20'],
        ]);

        // Öğrencinin ilk şifresi öğrenci numarası — ilk girişte değiştirilmesi zorunlu
        $data['password'] = $data['student_number'];
        $data['must_change_password'] = true;

        $student = $classroom->students()->create($data);

        return $this->createdResponse($this->transform($student));
    }

    /**
     * POST /api/classrooms/{classroom}/students/bulk
     * Excel'den okunmuş öğrenci listesini toplu ekler.
     * Aynı (sınıf, öğrenci numarası) varsa atlar.
     *
     * Body: { students: [{ student_number, first_name, last_name?, email?, phone? }, ...] }
     */
    public function bulkStore(Request $request, Classroom $classroom): JsonResponse
    {
        $this->authorize($request, $classroom);

        $data = $request->validate([
            'students'                  => ['required', 'array', 'min:1', 'max:500'],
            'students.*.student_number' => ['required', 'string', 'max:30'],
            'students.*.first_name'     => ['required', 'string', 'max:100'],
            'students.*.last_name'      => ['nullable', 'string', 'max:100'],
            'students.*.email'          => ['nullable', 'email', 'max:255'],
            'students.*.phone'          => ['nullable', 'string', 'max:20'],
            'file_name'                 => ['nullable', 'string', 'max:255'],
        ]);

        $existingNumbers = $classroom->students()->pluck('student_number')->all();
        $inserted = 0;
        $skipped = 0;

        DB::transaction(function () use ($classroom, $data, $existingNumbers, &$inserted, &$skipped) {
            foreach ($data['students'] as $row) {
                if (in_array($row['student_number'], $existingNumbers, true)) {
                    $skipped++;
                    continue;
                }

                $classroom->students()->create([
                    'student_number'       => $row['student_number'],
                    'first_name'           => $row['first_name'],
                    'last_name'            => $row['last_name'] ?? null,
                    'email'                => $row['email'] ?? null,
                    'phone'                => $row['phone'] ?? null,
                    'password'             => $row['student_number'], // ilk şifre = öğrenci no
                    'must_change_password' => true,
                ]);
                $existingNumbers[] = $row['student_number'];
                $inserted++;
            }

            if (!empty($data['file_name'])) {
                $classroom->update(['file_name' => $data['file_name']]);
            }
        });

        return $this->successResponse([
            'inserted'        => $inserted,
            'skipped'         => $skipped,
            'students_count'  => $classroom->students()->count(),
        ], "Toplu yükleme tamam: {$inserted} eklendi, {$skipped} atlandı.");
    }

    /**
     * PUT /api/students/{student}
     */
    public function update(Request $request, Student $student): JsonResponse
    {
        $this->authorize($request, $student->classroom);

        $data = $request->validate([
            'student_number' => [
                'required', 'string', 'max:30',
                Rule::unique('students')
                    ->where(fn ($q) => $q->where('classroom_id', $student->classroom_id))
                    ->ignore($student->id),
            ],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name'  => ['nullable', 'string', 'max:100'],
            'email'      => ['nullable', 'email', 'max:255'],
            'phone'      => ['nullable', 'string', 'max:20'],
        ]);

        $student->update($data);

        return $this->successResponse($this->transform($student->fresh()), 'Öğrenci güncellendi.');
    }

    /**
     * DELETE /api/students/{student}
     */
    public function destroy(Request $request, Student $student): JsonResponse
    {
        $this->authorize($request, $student->classroom);

        $student->delete();

        return $this->successResponse(null, 'Öğrenci silindi.');
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

    private function transform(Student $s): array
    {
        return [
            'id'             => $s->id,
            'classroom_id'   => $s->classroom_id,
            'student_number' => $s->student_number,
            'first_name'     => $s->first_name,
            'last_name'      => $s->last_name,
            'name'           => $s->name,
            'email'          => $s->email,
            'phone'          => $s->phone,
        ];
    }
}
