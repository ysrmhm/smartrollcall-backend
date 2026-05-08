<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\StudentInboxMessage;
use App\Traits\ApiResponseTrait;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentInboxController extends Controller
{
    use ApiResponseTrait;

    /**
     * GET /api/student/inbox
     * Aynı student_number altındaki tüm Student kayıtlarına gelen bildirimler birleştirilir.
     */
    public function index(Request $request): JsonResponse
    {
        $studentIds = $this->ownedStudentIds($request);
        $limit = max(1, min(100, (int) $request->query('limit', 30)));

        $items = StudentInboxMessage::whereIn('student_id', $studentIds)
            ->orderByRaw('read_at IS NULL DESC')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (StudentInboxMessage $m) => $this->transform($m));

        $unread = StudentInboxMessage::whereIn('student_id', $studentIds)
            ->whereNull('read_at')
            ->count();

        return $this->successResponse([
            'items'  => $items,
            'unread' => $unread,
        ]);
    }

    /**
     * GET /api/student/inbox/unread-count
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $count = StudentInboxMessage::whereIn('student_id', $this->ownedStudentIds($request))
            ->whereNull('read_at')
            ->count();

        return $this->successResponse(['unread' => $count]);
    }

    /**
     * POST /api/student/inbox/{message}/read
     */
    public function markRead(Request $request, StudentInboxMessage $message): JsonResponse
    {
        $this->authorize($request, $message);

        if (! $message->read_at) {
            $message->update(['read_at' => Carbon::now()]);
        }

        return $this->successResponse($this->transform($message->fresh()));
    }

    /**
     * POST /api/student/inbox/read-all
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $count = StudentInboxMessage::whereIn('student_id', $this->ownedStudentIds($request))
            ->whereNull('read_at')
            ->update(['read_at' => Carbon::now()]);

        return $this->successResponse(['marked' => $count], "$count bildirim okundu olarak işaretlendi.");
    }

    /**
     * DELETE /api/student/inbox/{message}
     */
    public function destroy(Request $request, StudentInboxMessage $message): JsonResponse
    {
        $this->authorize($request, $message);
        $message->delete();
        return $this->successResponse(null, 'Bildirim silindi.');
    }

    /**
     * DELETE /api/student/inbox  (hepsini temizle)
     */
    public function clearAll(Request $request): JsonResponse
    {
        $count = StudentInboxMessage::whereIn('student_id', $this->ownedStudentIds($request))->delete();
        return $this->successResponse(['deleted' => $count], "$count bildirim silindi.");
    }

    /**
     * Aynı student_number altındaki tüm Student kayıtlarının id'leri.
     * Öğrenci 4 sınıfta varsa 4 id döner; tüm bunlara gelen bildirimler ortak inbox'ta toplanır.
     */
    private function ownedStudentIds(Request $request): array
    {
        /** @var Student $student */
        $student = $request->user();
        return Student::where('student_number', $student->student_number)
            ->pluck('id')
            ->all();
    }

    private function authorize(Request $request, StudentInboxMessage $message): void
    {
        if (! in_array($message->student_id, $this->ownedStudentIds($request), true)) {
            abort(response()->json([
                'success' => false,
                'message' => 'Bu kayda erişim yetkiniz yok.',
            ], 403));
        }
    }

    private function transform(StudentInboxMessage $m): array
    {
        return [
            'id'         => $m->id,
            'type'       => $m->type,
            'title'      => $m->title,
            'body'       => $m->body,
            'link'       => $m->link,
            'is_read'    => $m->read_at !== null,
            'read_at'    => $m->read_at?->toIso8601String(),
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }
}
