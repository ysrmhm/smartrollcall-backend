<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\Attendance;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\StudentInboxMessage;
use App\Services\AuthService;
use App\Traits\ApiResponseTrait;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AnnouncementController extends Controller
{
    use ApiResponseTrait;

    /**
     * POST /api/classrooms/{classroom}/announcements
     * Body: { audience: 'all'|'absent_today'|'risk', title, body }
     * Hedef kitleyi çözer, her öğrenciye StudentInboxMessage oluşturur.
     */
    public function store(Request $request, Classroom $classroom): JsonResponse
    {
        $this->authorizeClassroom($request, $classroom);

        $data = $request->validate([
            'audience' => ['required', Rule::in(['all', 'absent_today', 'risk'])],
            'title'    => ['required', 'string', 'max:200'],
            'body'     => ['required', 'string', 'max:2000'],
        ]);

        $recipients = $this->resolveRecipients($classroom, $data['audience'], $request->user());

        if ($recipients->isEmpty()) {
            return $this->errorResponse(
                $this->emptyAudienceMessage($data['audience']),
                422
            );
        }

        $sender = $request->user();
        $senderName = trim(($sender->first_name ?? '').' '.($sender->last_name ?? '')) ?: $sender->name;

        $announcement = DB::transaction(function () use ($classroom, $sender, $senderName, $data, $recipients) {
            $a = Announcement::create([
                'classroom_id'     => $classroom->id,
                'sender_id'        => $sender->id,
                'audience'         => $data['audience'],
                'title'            => $data['title'],
                'body'             => $data['body'],
                'recipients_count' => $recipients->count(),
            ]);

            $now = Carbon::now();
            $rows = $recipients->map(fn (Student $s) => [
                'student_id'      => $s->id,
                'announcement_id' => $a->id,
                'type'            => 'info',
                'title'           => "📣 {$classroom->name} — {$data['title']}",
                'body'            => $data['body'],
                'link'            => '/student/messages',
                'read_at'         => null,
                'created_at'      => $now,
                'updated_at'      => $now,
            ])->all();

            // Toplu insert (chunk'lara bölmeden, sınıf max 200 öğrenci varsayımı)
            StudentInboxMessage::insert($rows);

            return $a;
        });

        return $this->createdResponse([
            'id'                => $announcement->id,
            'classroom_id'      => $classroom->id,
            'audience'          => $data['audience'],
            'title'             => $data['title'],
            'recipients_count'  => $recipients->count(),
            'sender_name'       => $senderName,
            'created_at'        => $announcement->created_at?->toIso8601String(),
        ], "Duyuru {$recipients->count()} öğrenciye gönderildi.");
    }

    /**
     * GET /api/announcements
     * Hocanın tüm sınıflarındaki duyuru geçmişi.
     */
    public function index(Request $request): JsonResponse
    {
        $classroomIds = Classroom::where('user_id', $request->user()->id)->pluck('id');

        $items = Announcement::with('classroom:id,name,code')
            ->whereIn('classroom_id', $classroomIds)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Announcement $a) => $this->transform($a));

        $classrooms = Classroom::where('user_id', $request->user()->id)
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'code' => $c->code]);

        return $this->successResponse([
            'items'      => $items,
            'classrooms' => $classrooms,
        ]);
    }

    /**
     * DELETE /api/announcements/{announcement}
     * Geri çekme: hem duyuru kaydı hem öğrencilerin inbox'lardaki kopyaları silinir.
     */
    public function destroy(Request $request, Announcement $announcement): JsonResponse
    {
        $owns = Classroom::where('id', $announcement->classroom_id)
            ->where('user_id', $request->user()->id)
            ->exists();

        if (! $owns) {
            return $this->forbiddenResponse('Bu duyuruya erişim yetkiniz yok.');
        }

        DB::transaction(function () use ($announcement) {
            // student_inbox_messages.announcement_id FK nullOnDelete olduğu için
            // duyuru silinince öğrenci tarafındaki mesajlar genel inbox'ta kalır.
            // Tamamen kaldırmak için açıkça siliyoruz.
            StudentInboxMessage::where('announcement_id', $announcement->id)->delete();
            $announcement->delete();
        });

        return $this->successResponse(null, 'Duyuru geri çekildi.');
    }

    /**
     * POST /api/classrooms/{classroom}/announcements/preview
     * Body: { audience }
     * Hedef kitle sayısını gönderim öncesi gösterir.
     */
    public function preview(Request $request, Classroom $classroom): JsonResponse
    {
        $this->authorizeClassroom($request, $classroom);

        $data = $request->validate([
            'audience' => ['required', Rule::in(['all', 'absent_today', 'risk'])],
        ]);

        $recipients = $this->resolveRecipients($classroom, $data['audience'], $request->user());

        return $this->successResponse([
            'audience'        => $data['audience'],
            'recipient_count' => $recipients->count(),
            'sample'          => $recipients->take(5)->pluck('name')->values(),
        ]);
    }

    private function resolveRecipients(Classroom $classroom, string $audience, $teacher)
    {
        if ($audience === 'all') {
            return Student::where('classroom_id', $classroom->id)->get();
        }

        if ($audience === 'absent_today') {
            $today = Carbon::now()->toDateString();
            $absentIds = Attendance::where('classroom_id', $classroom->id)
                ->where('date', $today)
                ->where('status', 'absent')
                ->pluck('student_id');
            return Student::where('classroom_id', $classroom->id)
                ->whereIn('id', $absentIds)
                ->get();
        }

        if ($audience === 'risk') {
            $limit = $this->absenceLimitFor($teacher);
            $riskIds = Attendance::where('classroom_id', $classroom->id)
                ->where('status', 'absent')
                ->select('student_id', DB::raw('COUNT(*) as cnt'))
                ->groupBy('student_id')
                ->havingRaw('COUNT(*) >= ?', [max(1, $limit - 1)])
                ->pluck('student_id');
            return Student::where('classroom_id', $classroom->id)
                ->whereIn('id', $riskIds)
                ->get();
        }

        return collect();
    }

    private function emptyAudienceMessage(string $audience): string
    {
        return match ($audience) {
            'absent_today' => 'Bugün için yoklama alınmamış veya hiç yok öğrenci yok.',
            'risk'         => 'Devamsızlık sınırına yaklaşan öğrenci yok — duyuru gönderilmedi.',
            default        => 'Sınıfta öğrenci yok.',
        };
    }

    private function absenceLimitFor($user): int
    {
        $defaults = AuthService::defaultPreferences();
        $prefs = $user->preferences ?? $defaults;
        $val = (int) ($prefs['defaultAbsenceLimit'] ?? $defaults['defaultAbsenceLimit']);
        return max(1, min(20, $val));
    }

    private function authorizeClassroom(Request $request, Classroom $classroom): void
    {
        if ($classroom->user_id !== $request->user()->id) {
            abort(response()->json([
                'success' => false,
                'message' => 'Bu sınıfa erişim yetkiniz yok.',
            ], 403));
        }
    }

    private function transform(Announcement $a): array
    {
        $sender = $a->sender;
        $senderName = $sender
            ? (trim(($sender->first_name ?? '').' '.($sender->last_name ?? '')) ?: $sender->name)
            : null;

        return [
            'id'               => $a->id,
            'classroom_id'     => $a->classroom_id,
            'classroom_name'   => $a->classroom?->name,
            'classroom_code'   => $a->classroom?->code,
            'sender_name'      => $senderName,
            'audience'         => $a->audience,
            'title'            => $a->title,
            'body'             => $a->body,
            'recipients_count' => $a->recipients_count,
            'created_at'       => $a->created_at?->toIso8601String(),
        ];
    }
}
