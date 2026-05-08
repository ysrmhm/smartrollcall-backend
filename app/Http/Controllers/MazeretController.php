<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Classroom;
use App\Models\MazeretRequest;
use App\Models\StudentInboxMessage;
use App\Traits\ApiResponseTrait;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MazeretController extends Controller
{
    use ApiResponseTrait;

    /**
     * GET /api/mazerets
     * Hocanın kendi sınıflarındaki mazeretler. ?status=pending|approved|rejected, ?classroom_id=N
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status'       => ['nullable', Rule::in(['pending', 'approved', 'rejected'])],
            'classroom_id' => ['nullable', 'integer'],
        ]);

        $classroomIds = Classroom::where('user_id', $request->user()->id)->pluck('id');

        $query = MazeretRequest::with([
                'student:id,classroom_id,student_number,first_name,last_name,email',
                'classroom:id,name,code',
                'reviewer:id,first_name,last_name,name',
            ])
            ->whereIn('classroom_id', $classroomIds);

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('classroom_id')) {
            $query->where('classroom_id', (int) $request->query('classroom_id'));
        }

        // Bekleyenler önce, sonra en yeni
        $items = $query
            ->orderByRaw("FIELD(status, 'pending', 'approved', 'rejected')")
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (MazeretRequest $m) => $this->transform($m));

        // Filtre dropdown'u için: hocanın sınıfları
        $classrooms = Classroom::where('user_id', $request->user()->id)
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(fn ($c) => [
                'id'   => $c->id,
                'name' => $c->name,
                'code' => $c->code,
            ]);

        return $this->successResponse([
            'items'      => $items,
            'classrooms' => $classrooms,
        ]);
    }

    /**
     * GET /api/mazerets/pending-count
     */
    public function pendingCount(Request $request): JsonResponse
    {
        $classroomIds = Classroom::where('user_id', $request->user()->id)->pluck('id');

        $count = MazeretRequest::whereIn('classroom_id', $classroomIds)
            ->where('status', 'pending')
            ->count();

        return $this->successResponse(['pending' => $count]);
    }

    /**
     * POST /api/mazerets/{mazeret}/approve
     * Onaylar + Attendance kaydını 'excused'e çeker (yoksa oluşturur).
     */
    public function approve(Request $request, MazeretRequest $mazeret): JsonResponse
    {
        $this->authorize($request, $mazeret);

        $data = $request->validate([
            'review_note' => ['nullable', 'string', 'max:500'],
        ]);

        if (! $mazeret->isPending()) {
            return $this->errorResponse('Bu mazeret zaten karara bağlanmış.', 422);
        }

        DB::transaction(function () use ($mazeret, $request, $data) {
            $mazeret->update([
                'status'      => 'approved',
                'reviewer_id' => $request->user()->id,
                'reviewed_at' => Carbon::now(),
                'review_note' => $data['review_note'] ?? null,
            ]);

            // Attendance kaydını excused'e çevir / oluştur
            Attendance::updateOrCreate(
                [
                    'student_id'   => $mazeret->student_id,
                    'classroom_id' => $mazeret->classroom_id,
                    'date'         => $mazeret->date->toDateString(),
                ],
                ['status' => 'excused']
            );

            // Öğrenciye bildirim
            $dateLabel = $mazeret->date->locale('tr')->translatedFormat('d F Y');
            StudentInboxMessage::create([
                'student_id' => $mazeret->student_id,
                'type'       => 'success',
                'title'      => 'Mazeretiniz Onaylandı',
                'body'       => "{$mazeret->classroom?->name} dersinden {$dateLabel} tarihli mazeretiniz onaylandı. Devamsızlık hakkınızdan düşmedi.".
                                ($data['review_note'] ?? '' ? " Hoca notu: {$data['review_note']}" : ''),
                'link'       => '/student/mazerets',
            ]);
        });

        // Achievements cache invalidate (Attendance excused'e döndü → rozetler değişebilir)
        $sNum = optional($mazeret->student)->student_number;
        if ($sNum) {
            StudentAchievementsController::invalidateFor($sNum);
        }

        return $this->successResponse(
            $this->transform($mazeret->fresh(['student', 'classroom', 'reviewer'])),
            'Mazeret onaylandı.'
        );
    }

    /**
     * POST /api/mazerets/{mazeret}/reject
     */
    public function reject(Request $request, MazeretRequest $mazeret): JsonResponse
    {
        $this->authorize($request, $mazeret);

        $data = $request->validate([
            'review_note' => ['nullable', 'string', 'max:500'],
        ]);

        if (! $mazeret->isPending()) {
            return $this->errorResponse('Bu mazeret zaten karara bağlanmış.', 422);
        }

        $mazeret->update([
            'status'      => 'rejected',
            'reviewer_id' => $request->user()->id,
            'reviewed_at' => Carbon::now(),
            'review_note' => $data['review_note'] ?? null,
        ]);

        $dateLabel = $mazeret->date->locale('tr')->translatedFormat('d F Y');
        StudentInboxMessage::create([
            'student_id' => $mazeret->student_id,
            'type'       => 'warning',
            'title'      => 'Mazeretiniz Reddedildi',
            'body'       => "{$mazeret->classroom?->name} dersinden {$dateLabel} tarihli mazeretiniz reddedildi.".
                            ($data['review_note'] ?? '' ? " Hoca notu: {$data['review_note']}" : ''),
            'link'       => '/student/mazerets',
        ]);

        return $this->successResponse(
            $this->transform($mazeret->fresh(['student', 'classroom', 'reviewer'])),
            'Mazeret reddedildi.'
        );
    }

    private function authorize(Request $request, MazeretRequest $mazeret): void
    {
        $owns = Classroom::where('id', $mazeret->classroom_id)
            ->where('user_id', $request->user()->id)
            ->exists();

        if (! $owns) {
            abort(response()->json([
                'success' => false,
                'message' => 'Bu mazerete erişim yetkiniz yok.',
            ], 403));
        }
    }

    private function transform(MazeretRequest $m): array
    {
        $reviewerName = $m->reviewer?->name
            ?? ($m->reviewer ? trim(($m->reviewer->first_name ?? '').' '.($m->reviewer->last_name ?? '')) : null);

        return [
            'id'                 => $m->id,
            'student_id'         => $m->student_id,
            'student_name'       => $m->student?->name
                ?? ($m->student ? trim(($m->student->first_name ?? '').' '.($m->student->last_name ?? '')) : null),
            'student_number'     => $m->student?->student_number,
            'student_email'      => $m->student?->email,
            'classroom_id'       => $m->classroom_id,
            'classroom_name'     => $m->classroom?->name,
            'classroom_code'     => $m->classroom?->code,
            'date'               => $m->date?->toDateString(),
            'reason'             => $m->reason,
            'file_original_name' => $m->file_original_name,
            'file_mime'          => $m->file_mime,
            'file_size'          => $m->file_size,
            'status'             => $m->status,
            'reviewer_name'      => $reviewerName,
            'reviewed_at'        => $m->reviewed_at?->toIso8601String(),
            'review_note'        => $m->review_note,
            'created_at'         => $m->created_at?->toIso8601String(),
        ];
    }
}
