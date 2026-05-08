<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StudentNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $studentName,
        public string $teacherName,
        public string $classroomName,
        public string $body,
        public string $mailSubject,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mailSubject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.student-notification',
            with: [
                'studentName'   => $this->studentName,
                'teacherName'   => $this->teacherName,
                'classroomName' => $this->classroomName,
                'body'          => $this->body,
            ],
        );
    }
}
