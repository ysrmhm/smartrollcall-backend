<?php

namespace App\Http\Controllers;

use App\Models\Classroom;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClassroomController extends Controller
{
    use ApiResponseTrait;

    /**
     * GET /api/classrooms
     * Default: aktif sınıflar (archived_at IS NULL).
     * ?archived=true ile arşivlenmiş sınıflar listelenir.
     */
    public function index(Request $request): JsonResponse
    {
        $archived = $request->boolean('archived');

        $classrooms = Classroom::query()
            ->where('user_id', $request->user()->id)
            ->when($archived, fn ($q) => $q->whereNotNull('archived_at'),
                              fn ($q) => $q->whereNull('archived_at'))
            ->withCount('students')
            ->orderByDesc('archived_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Classroom $c) => $this->transform($c));

        return $this->successResponse($classrooms);
    }

    /**
     * POST /api/classrooms/{classroom}/archive
     */
    public function archive(Request $request, Classroom $classroom): JsonResponse
    {
        $this->authorize($request, $classroom);

        if ($classroom->archived_at) {
            return $this->errorResponse('Bu sınıf zaten arşivde.', 422);
        }

        $classroom->update(['archived_at' => now()]);

        return $this->successResponse(
            $this->transform($classroom->fresh()->loadCount('students')),
            'Sınıf arşivlendi.'
        );
    }

    /**
     * POST /api/classrooms/{classroom}/restore
     */
    public function restore(Request $request, Classroom $classroom): JsonResponse
    {
        $this->authorize($request, $classroom);

        if (! $classroom->archived_at) {
            return $this->errorResponse('Bu sınıf zaten aktif.', 422);
        }

        $classroom->update(['archived_at' => null]);

        return $this->successResponse(
            $this->transform($classroom->fresh()->loadCount('students')),
            'Sınıf arşivden geri alındı.'
        );
    }

    /**
     * POST /api/classrooms
     */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);

        if ($conflict = $this->findScheduleConflict($data['department'] ?? null, $data['day'] ?? null, $data['time'] ?? null)) {
            return $this->errorResponse(
                "Bu zaman diliminde aynı bölümde başka bir ders var: {$conflict->name} ({$conflict->day} {$conflict->time}).",
                422
            );
        }

        $classroom = Classroom::create([
            'user_id'          => $request->user()->id,
            'name'             => $data['name'],
            'department'       => $data['department'] ?? null,
            'day'              => $data['day'] ?? null,
            'time'             => $data['time'] ?? null,
            'status'           => $data['status'] ?? 'Aktif',
            'attendance_taken' => false,
            'file_name'        => $data['file_name'] ?? null,
        ]);

        return $this->createdResponse($this->transform($classroom->loadCount('students')));
    }

    /**
     * GET /api/classrooms/{classroom}
     * Tek sınıf + öğrencileri.
     */
    public function show(Request $request, Classroom $classroom): JsonResponse
    {
        $this->authorize($request, $classroom);

        $classroom->load('students');

        $payload = $this->transform($classroom);
        $payload['students'] = $classroom->students->map(fn ($s) => [
            'id'             => $s->id,
            'student_number' => $s->student_number,
            'first_name'     => $s->first_name,
            'last_name'      => $s->last_name,
            'name'           => $s->name,
            'email'          => $s->email,
            'phone'          => $s->phone,
        ]);
        $payload['students_count'] = $classroom->students->count();

        return $this->successResponse($payload);
    }

    /**
     * PUT /api/classrooms/{classroom}
     */
    public function update(Request $request, Classroom $classroom): JsonResponse
    {
        $this->authorize($request, $classroom);

        $data = $this->validatePayload($request, $classroom->id);

        $newDept = array_key_exists('department', $data) ? $data['department'] : $classroom->department;
        $newDay  = array_key_exists('day',        $data) ? $data['day']        : $classroom->day;
        $newTime = array_key_exists('time',       $data) ? $data['time']       : $classroom->time;

        if ($conflict = $this->findScheduleConflict($newDept, $newDay, $newTime, $classroom->id)) {
            return $this->errorResponse(
                "Bu zaman diliminde aynı bölümde başka bir ders var: {$conflict->name} ({$conflict->day} {$conflict->time}).",
                422
            );
        }

        $classroom->update($data);

        return $this->successResponse(
            $this->transform($classroom->fresh()->loadCount('students')),
            'Sınıf güncellendi.'
        );
    }

    /**
     * DELETE /api/classrooms/{classroom}
     */
    public function destroy(Request $request, Classroom $classroom): JsonResponse
    {
        $this->authorize($request, $classroom);

        $classroom->delete();

        return $this->successResponse(null, 'Sınıf silindi.');
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

    private function validatePayload(Request $request, ?int $id = null): array
    {
        $validDepartments = config('departments.list', []);

        return $request->validate([
            'name'             => ['required', 'string', 'max:150'],
            'department'       => ['nullable', 'string', 'max:150', Rule::in($validDepartments)],
            'day'              => ['nullable', Rule::in(['Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma'])],
            'time'             => ['nullable', 'string', 'max:10'],
            'status'           => ['nullable', Rule::in(['Aktif', 'Tamamlandı'])],
            'file_name'        => ['nullable', 'string', 'max:255'],
            'attendance_taken' => ['sometimes', 'boolean'],
        ]);
    }

    /**
     * Aynı bölümde aynı gün+saat'te başka bir aktif sınıf var mı?
     * Varsa Classroom döner, yoksa null. Update senaryosunda kendini hariç tutar.
     */
    private function findScheduleConflict(?string $department, ?string $day, ?string $time, ?int $excludeId = null): ?Classroom
    {
        if (! $department || ! $day || ! $time) {
            return null;
        }

        return Classroom::query()
            ->where('department', $department)
            ->where('day', $day)
            ->where('time', $time)
            ->whereNull('archived_at')
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->first();
    }

    private function transform(Classroom $c): array
    {
        return [
            'id'               => $c->id,
            'name'             => $c->name,
            'department'       => $c->department,
            'day'              => $c->day,
            'time'             => $c->time,
            'time_display'     => trim(($c->day ?? '').' '.($c->time ?? '')),
            'status'           => $c->status ?? 'Aktif',
            'attendance_taken' => (bool) $c->attendance_taken,
            'file_name'        => $c->file_name,
            'students_count'   => $c->students_count ?? 0,
            'archived_at'      => $c->archived_at?->toIso8601String(),
            'is_archived'      => $c->archived_at !== null,
            'created_at'       => $c->created_at?->toIso8601String(),
        ];
    }
}
