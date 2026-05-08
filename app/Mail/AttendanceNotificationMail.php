<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AttendanceNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $studentName,
        public string $teacherName,
        public string $classroomName,
        public string $dateLabel,     // örn: "5 Mayıs 2026 Perşembe"
        public ?string $timeLabel,    // örn: "09:00" ya da null
        public string $status,        // 'absent' | 'late' | 'excused'
        public string $statusLabel,   // 'Yok' | 'Geç Geldi' | 'Mazeretli'
        public int $absentCount,      // toplam devamsızlık sayısı
        public int $absenceLimit,     // sınır (örn. 3)
    ) {}

    public function envelope(): Envelope
    {
        $emoji = match ($this->status) {
            'absent'  => '⚠️',
            'late'    => '⏰',
            'excused' => 'ℹ️',
            default   => '📝',
        };

        return new Envelope(
            subject: "{$emoji} Yoklama Bildirimi: {$this->classroomName} ({$this->dateLabel})",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.attendance-notification',
            with: [
                'studentName'    => $this->studentName,
                'teacherName'    => $this->teacherName,
                'classroomName'  => $this->classroomName,
                'dateLabel'      => $this->dateLabel,
                'timeLabel'      => $this->timeLabel,
                'status'         => $this->status,
                'statusLabel'    => $this->statusLabel,
                'absentCount'    => $this->absentCount,
                'absenceLimit'   => $this->absenceLimit,
            ],
        );
    }
}
