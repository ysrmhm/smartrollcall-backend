<?php

namespace App\Http\Controllers;

use App\Models\Classroom;
use App\Models\MakeupSession;
use App\Models\Student;
use App\Models\StudentInboxMessage;
use App\Traits\ApiResponseTrait;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MakeupSessionController extends Controller
{
    use ApiResponseTrait;

    /**
     * POST /api/classrooms/{classroom}/makeup-sessions
     * Body: { date: 'YYYY-MM-DD', time: 'HH:MM', note?: string }
     * Telafi dersi oluşturur + sınıftaki tüm öğrencilere bildirim gönderir.
     */
    public function store(Request $request, Classroom $classroom): JsonResponse
    {
        $this->authorize($request, $classroom);

        $data = $request->validate([
            'date' => ['required', 'date', 'after_or_equal:today'],
            'time' => ['required', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $date = Carbon::parse($data['date']);
        $daysTr = ['Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar'];
        $dayName = $daysTr[$date->dayOfWeekIso - 1];

        // Aynı (sınıf, tarih, saat) için ikinci kez oluşturma
        $existing = MakeupSession::where('classroom_id', $classroom->id)
            ->where('date', $date->toDateString())
            ->where('time', $data['time'])
            ->first();

        if ($existing) {
            return $this->errorResponse('Bu tarih ve saat için zaten telafi dersi planlanmış.', 422);
        }

        $session = DB::transaction(function () use ($classroom, $request, $data, $date, $dayName) {
            $s = MakeupSession::create([
                'classroom_id' => $classroom->id,
                'created_by'   => $request->user()->id,
                'date'         => $date->toDateString(),
                'time'         => $data['time'],
                'day'          => $dayName,
                'note'         => $data['note'] ?? null,
            ]);

            // Öğrencilere bildirim
            $studentIds = Student::where('classroom_id', $classroom->id)->pluck('id');
            if ($studentIds->isNotEmpty()) {
                $dateLabel = $date->locale('tr')->translatedFormat('d F Y');
                $now = Carbon::now();
                $rows = $studentIds->map(fn ($sid) => [
                    'student_id'      => $sid,
                    'announcement_id' => null,
                    'type'            => 'info',
                    'title'           => "📅 Telafi Dersi: {$classroom->name}",
                    'body'            => "{$dayName}, {$dateLabel} tarihinde saat {$data['time']}'da telafi dersi yapılacak."
                                       . (!empty($data['note']) ? "\n\nNot: {$data['note']}" : ''),
                    'link'            => '/student/schedule',
                    'read_at'         => null,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ])->all();
                StudentInboxMessage::insert($rows);
            }

            return $s;
        });

        return $this->createdResponse(
            $this->transform($session->fresh(['classroom', 'creator'])),
            'Telafi dersi oluşturuldu ve öğrencilere bildirildi.'
        );
    }

    /**
     * GET /api/classrooms/{classroom}/makeup-sessions
     * Hocanın görmesi için bu sınıfa ait gelecek telafi oturumları.
     */
    public function index(Request $request, Classroom $classroom): JsonResponse
    {
        $this->authorize($request, $classroom);

        $items = MakeupSession::where('classroom_id', $classroom->id)
            ->where('date', '>=', Carbon::today()->toDateString())
            ->with('creator:id,name,first_name,last_name')
            ->orderBy('date')
            ->orderBy('time')
            ->get()
            ->map(fn (MakeupSession $s) => $this->transform($s));

        return $this->successResponse($items);
    }

    /**
     * GET /api/makeup-sessions
     * Hocanın TÜM sınıflarındaki telafiler. Opsiyonel tarih aralığı:
     *   ?from=YYYY-MM-DD&to=YYYY-MM-DD  (varsayılan: bugünden itibaren)
     * Schedule sayfasının haftalık view'ı için kullanılır.
     */
    public function teacherIndex(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to'   => ['nullable', 'date'],
        ]);

        $from = $data['from'] ?? Carbon::today()->toDateString();
        $to   = $data['to']   ?? Carbon::today()->addMonths(2)->toDateString();

        $classroomIds = Classroom::where('user_id', $request->user()->id)->pluck('id');

        $items = MakeupSession::whereIn('classroom_id', $classroomIds)
            ->whereBetween('date', [$from, $to])
            ->with(['classroom:id,name,code,department', 'creator:id,name,first_name,last_name'])
            ->orderBy('date')
            ->orderBy('time')
            ->get()
            ->map(fn (MakeupSession $s) => $this->transform($s));

        return $this->successResponse($items);
    }

    /**
     * PUT /api/makeup-sessions/{session}
     * Telafiyi yeniden planla (tarih/saat/not değiştir). Öğrencilere "değişiklik" bildirimi gider.
     */
    public function update(Request $request, MakeupSession $session): JsonResponse
    {
        $classroom = $session->classroom;
        $this->authorize($request, $classroom);

        $data = $request->validate([
            'date' => ['required', 'date', 'after_or_equal:today'],
            'time' => ['required', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $newDate = Carbon::parse($data['date']);
        $daysTr = ['Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar'];
        $newDayName = $daysTr[$newDate->dayOfWeekIso - 1];

        // Aynı (sınıf, yeni tarih, yeni saat) için BAŞKA bir kayıt var mı?
        $conflict = MakeupSession::where('classroom_id', $classroom->id)
            ->where('date', $newDate->toDateString())
            ->where('time', $data['time'])
            ->where('id', '!=', $session->id)
            ->first();

        if ($conflict) {
            return $this->errorResponse('Bu tarih ve saat için başka bir telafi dersi var.', 422);
        }

        $oldDate    = $session->date->toDateString();
        $oldTime    = $session->time;
        $oldDayName = $session->day;
        $changed    = $oldDate !== $newDate->toDateString() || $oldTime !== $data['time'];

        DB::transaction(function () use ($session, $classroom, $data, $newDate, $newDayName, $oldDate, $oldTime, $oldDayName, $changed) {
            $session->update([
                'date' => $newDate->toDateString(),
                'time' => $data['time'],
                'day'  => $newDayName,
                'note' => $data['note'] ?? null,
            ]);

            // Öğrencilere bildirim sadece tarih veya saat değiştiyse anlamlı.
            // Sadece not değiştiyse de basit bilgi gönder.
            $studentIds = Student::where('classroom_id', $classroom->id)->pluck('id');
            if ($studentIds->isEmpty()) return;

            $oldLabel = Carbon::parse($oldDate)->locale('tr')->translatedFormat('d F Y');
            $newLabel = $newDate->locale('tr')->translatedFormat('d F Y');
            $now = Carbon::now();

            $body = $changed
                ? "Önceki plan: {$oldDayName}, {$oldLabel} {$oldTime}\nYeni plan: {$newDayName}, {$newLabel} {$data['time']}"
                : "{$newDayName}, {$newLabel} {$data['time']} — telafi dersi notu güncellendi.";
            if (! empty($data['note'])) {
                $body .= "\n\nNot: {$data['note']}";
            }

            $rows = $studentIds->map(fn ($sid) => [
                'student_id'      => $sid,
                'announcement_id' => null,
                'type'            => 'warning',
                'title'           => "🔄 Telafi Dersi Güncellendi: {$classroom->name}",
                'body'            => $body,
                'link'            => '/student/schedule',
                'read_at'         => null,
                'created_at'      => $now,
                'updated_at'      => $now,
            ])->all();
            StudentInboxMessage::insert($rows);
        });

        return $this->successResponse(
            $this->transform($session->fresh(['classroom', 'creator'])),
            'Telafi dersi güncellendi ve öğrencilere bildirildi.'
        );
    }

    /**
     * DELETE /api/makeup-sessions/{session}
     * Telafi iptal — öğrencilere de bildirim atılır.
     */
    public function destroy(Request $request, MakeupSession $session): JsonResponse
    {
        $classroom = $session->classroom;
        $this->authorize($request, $classroom);

        DB::transaction(function () use ($session, $classroom) {
            $studentIds = Student::where('classroom_id', $classroom->id)->pluck('id');
            $dateLabel = $session->date->locale('tr')->translatedFormat('d F Y');
            $now = Carbon::now();
            $rows = $studentIds->map(fn ($sid) => [
                'student_id'      => $sid,
                'announcement_id' => null,
                'type'            => 'warning',
                'title'           => "❌ Telafi Dersi İptal: {$classroom->name}",
                'body'            => "{$dateLabel} tarihinde saat {$session->time}'daki telafi dersi iptal edildi.",
                'link'            => '/student/schedule',
                'read_at'         => null,
                'created_at'      => $now,
                'updated_at'      => $now,
            ])->all();

            if (! empty($rows)) {
                StudentInboxMessage::insert($rows);
            }
            $session->delete();
        });

        return $this->successResponse(null, 'Telafi dersi iptal edildi.');
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

    private function transform(MakeupSession $s): array
    {
        $creator = $s->creator;
        $creatorName = $creator
            ? (trim(($creator->first_name ?? '').' '.($creator->last_name ?? '')) ?: $creator->name)
            : null;

        return [
            'id'             => $s->id,
            'classroom_id'   => $s->classroom_id,
            'classroom_name' => $s->classroom?->name,
            'classroom_code' => $s->classroom?->code,
            'date'           => $s->date?->toDateString(),
            'date_label'     => $s->date?->locale('tr')->translatedFormat('d F Y'),
            'time'           => $s->time,
            'day'            => $s->day,
            'note'           => $s->note,
            'creator_name'   => $creatorName,
            'created_at'     => $s->created_at?->toIso8601String(),
        ];
    }
}
