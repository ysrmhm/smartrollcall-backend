<?php

namespace App\Console\Commands;

use App\Models\Student;
use App\Models\StudentInboxMessage;
use Illuminate\Console\Command;

class SeedStudentInboxDemoCommand extends Command
{
    protected $signature = 'seed:student-inbox-demo {--student_number=9001}';
    protected $description = 'Test için demo öğrenci inbox\'ına 3-4 bildirim ekler.';

    public function handle(): int
    {
        $sNum = $this->option('student_number');
        $first = Student::where('student_number', $sNum)->first();
        if (! $first) {
            $this->error("Öğrenci bulunamadı: {$sNum}");
            return self::FAILURE;
        }

        StudentInboxMessage::create([
            'student_id' => $first->id,
            'type'       => 'info',
            'title'      => 'SmartRoll Hoşgeldiniz',
            'body'       => 'Yoklama, devamsızlık sınırı ve dersleriniz hakkındaki tüm bildirimler bu panelden ulaşır.',
            'link'       => '/student',
        ]);

        StudentInboxMessage::create([
            'student_id' => $first->id,
            'type'       => 'success',
            'title'      => 'Web Programlama I — Yoklamada Vardınız',
            'body'       => 'Bugün hocanız yoklama aldı, var olarak işaretlendiniz.',
            'link'       => '/student/attendances',
        ]);

        StudentInboxMessage::create([
            'student_id' => $first->id,
            'type'       => 'warning',
            'title'      => 'Devamsızlık Sınırına Yaklaştınız',
            'body'       => 'Veritabanı Yönetim Sistemleri dersinden 2/3 devamsızlığa ulaştınız. Bir sonraki devamsızlığınızda kalırsınız.',
            'link'       => '/student/attendances',
        ]);

        StudentInboxMessage::create([
            'student_id' => $first->id,
            'type'       => 'danger',
            'title'      => 'Devamsızlık Sınırı Aşıldı',
            'body'       => 'Yapay Zeka Temelleri dersinden 3/3 devamsızlığa ulaştınız. Sınıfı geçemezsiniz.',
            'link'       => '/student/attendances',
        ]);

        $this->info("4 demo bildirim eklendi (öğrenci no: {$sNum}).");
        return self::SUCCESS;
    }
}
