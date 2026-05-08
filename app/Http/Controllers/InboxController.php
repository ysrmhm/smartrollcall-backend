<?php

namespace App\Http\Controllers;

use App\Models\InboxMessage;
use App\Traits\ApiResponseTrait;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InboxController extends Controller
{
    use ApiResponseTrait;

    /**
     * GET /api/inbox
     * Son N bildirimi döner (default 30). Okunmamışlar üstte (read_at NULL).
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $limit = (int) $request->query('limit', 30);
        $limit = max(1, min(100, $limit));

        $items = InboxMessage::where('user_id', $userId)
            ->orderByRaw('read_at IS NULL DESC')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (InboxMessage $m) => $this->transform($m));

        $unread = InboxMessage::where('user_id', $userId)->whereNull('read_at')->count();

        return $this->successResponse([
            'items'  => $items,
            'unread' => $unread,
        ]);
    }

    /**
     * GET /api/inbox/unread-count
     * Hızlı polling için sadece okunmamış sayısı.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $count = InboxMessage::where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->count();

        return $this->successResponse(['unread' => $count]);
    }

    /**
     * POST /api/inbox/{message}/read
     */
    public function markRead(Request $request, InboxMessage $message): JsonResponse
    {
        $this->authorize($request, $message);

        if (! $message->read_at) {
            $message->update(['read_at' => Carbon::now()]);
        }

        return $this->successResponse($this->transform($message->fresh()));
    }

    /**
     * POST /api/inbox/read-all
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $count = InboxMessage::where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => Carbon::now()]);

        return $this->successResponse(['marked' => $count], "$count bildirim okundu olarak işaretlendi.");
    }

    /**
     * DELETE /api/inbox/{message}
     */
    public function destroy(Request $request, InboxMessage $message): JsonResponse
    {
        $this->authorize($request, $message);
        $message->delete();
        return $this->successResponse(null, 'Bildirim silindi.');
    }

    /**
     * DELETE /api/inbox  (tümünü temizle)
     */
    public function clearAll(Request $request): JsonResponse
    {
        $count = InboxMessage::where('user_id', $request->user()->id)->delete();
        return $this->successResponse(['deleted' => $count], "$count bildirim silindi.");
    }

    private function authorize(Request $request, InboxMessage $message): void
    {
        if ($message->user_id !== $request->user()->id) {
            abort(response()->json([
                'success' => false,
                'message' => 'Bu kayda erişim yetkiniz yok.',
            ], 403));
        }
    }

    private function transform(InboxMessage $m): array
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
