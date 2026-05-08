<?php

namespace App\Console\Commands;

use App\Mail\AttendanceNotificationMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class TestAttendanceMailCommand extends Command
{
    protected $signature = 'test:attendance-mail
                            {--to= : Mailin gideceği adres (zorunlu)}
                            {--status=absent : absent | late | excused}
                            {--name=Test Öğrenci : Öğrenci adı}
                            {--class=Web Programlama I : Sınıf adı}
                            {--time=09:00 : Ders saati}
                            {--absent-count=2 : Toplam devamsızlık}
                            {--limit=3 : Devamsızlık sınırı}';

    protected $description = 'Yoklama bildirimi mailinin görsel önizlemesini gönderir.';

    public function handle(): int
    {
        $to = $this->option('to');
        if (! $to) {
            $this->error('--to=email@example.com şart.');
            return self::FAILURE;
        }

        $statusLabels = ['absent' => 'Yok', 'late' => 'Geç Geldi', 'excused' => 'Mazeretli'];
        $status = $this->option('status');

        try {
            Mail::to($to)->send(new AttendanceNotificationMail(
                studentName:   $this->option('name'),
                teacherName:   'Demo Hoca',
                classroomName: $this->option('class'),
                dateLabel:     now()->locale('tr')->translatedFormat('d F Y l'),
                timeLabel:     $this->option('time'),
                status:        $status,
                statusLabel:   $statusLabels[$status] ?? '—',
                absentCount:   (int) $this->option('absent-count'),
                absenceLimit:  (int) $this->option('limit'),
            ));
            $this->info("✓ Mail gönderildi → {$to}  (status={$status})");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Hata: '.$e->getMessage());
            return self::FAILURE;
        }
    }
}
