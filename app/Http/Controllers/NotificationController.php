<?php

namespace App\Http\Controllers;

use App\Mail\StudentNotificationMail;
use App\Models\Student;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotificationController extends Controller
{
    use ApiResponseTrait;

    /**
     * POST /api/notify-student
     * Hocadan öğrenciye e-posta gönderir.
     * EmailJS yerine artık backend Laravel Mail facade'ı üzerinden çalışır.
     */
    public function notifyStudent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer'],
            'subject'    => ['required', 'string', 'max:200'],
            'message'    => ['required', 'string', 'max:5000'],
        ]);

        $student = Student::with('classroom')->find($data['student_id']);
        if (! $student) {
            return $this->notFoundResponse('Öğrenci bulunamadı.');
        }

        // Sahiplik kontrolü: bu öğrencinin sınıfı request user'a ait olmalı
        if ($student->classroom?->user_id !== $request->user()->id) {
            return $this->forbiddenResponse('Bu öğrenciye mesaj gönderme yetkiniz yok.');
        }

        if (empty($student->email)) {
            return $this->errorResponse('Bu öğrencinin e-posta adresi yok.', 422);
        }

        try {
            Mail::to($student->email)->send(new StudentNotificationMail(
                studentName:   $student->name,
                teacherName:   $request->user()->name ?? $request->user()->username,
                classroomName: $student->classroom?->name ?? '-',
                body:          $data['message'],
                mailSubject:   $data['subject'],
            ));
        } catch (\Throwable $e) {
            Log::error('Student notification mail failed', [
                'student_id' => $student->id,
                'error'      => $e->getMessage(),
            ]);
            return $this->serverErrorResponse('E-posta gönderilemedi. Sunucu kayıtlarını kontrol edin.');
        }

        return $this->successResponse([
            'sent_to' => $student->email,
        ], "{$student->name} adlı öğrenciye e-posta gönderildi.");
    }
}
