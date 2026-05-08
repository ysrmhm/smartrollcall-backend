<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\StudentInboxMessage;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentMessagesController extends Controller
{
    use ApiResponseTrait;

    /**
     * GET /api/student/messages
     * Sadece duyuru-kaynaklı mesajlar (announcement_id != null), zenginleştirilmiş:
     *  - sınıf adı, hoca adı
     *  - filtre dropdown'u için hoca/sınıf listesi
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Student $student */
        $student = $request->user();
        $studentIds = Student::where('student_number', $student->student_number)->pluck('id');

        $messages = StudentInboxMessage::with([
                'announcement.classroom:id,name,code',
                'announcement.sender:id,first_name,last_name,name',
            ])
            ->whereIn('student_id', $studentIds)
            ->whereNotNull('announcement_id')
            ->orderByDesc('created_at')
            ->get()
            ->map(function (StudentInboxMessage $m) {
                $a = $m->announcement;
                $sender = $a?->sender;
                $senderName = $sender
                    ? (trim(($sender->first_name ?? '').' '.($sender->last_name ?? '')) ?: $sender->name)
                    : null;

                return [
                    'id'               => $m->id,
                    'announcement_id'  => $m->announcement_id,
                    'title'            => $a?->title ?? $m->title,
                    'body'             => $a?->body ?? $m->body,
                    'audience'         => $a?->audience,
                    'classroom_id'     => $a?->classroom_id,
                    'classroom_name'   => $a?->classroom?->name,
                    'classroom_code'   => $a?->classroom?->code,
                    'sender_name'      => $senderName,
                    'is_read'          => $m->read_at !== null,
                    'read_at'          => $m->read_at?->toIso8601String(),
                    'created_at'       => $m->created_at?->toIso8601String(),
                ];
            });

        // Filtre dropdown verisi: bu öğrencinin tüm sınıfları
        $classrooms = Student::with('classroom:id,name,code')
            ->where('student_number', $student->student_number)
            ->get()
            ->pluck('classroom')
            ->filter()
            ->unique('id')
            ->values()
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'code' => $c->code]);

        return $this->successResponse([
            'items'      => $messages,
            'classrooms' => $classrooms,
            'unread'     => $messages->where('is_read', false)->count(),
        ]);
    }
}
