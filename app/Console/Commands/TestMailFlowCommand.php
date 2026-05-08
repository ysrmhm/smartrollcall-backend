<?php

namespace App\Console\Commands;

use App\Mail\PasswordResetCodeMail;
use App\Mail\StudentNotificationMail;
use App\Models\Student;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;

class TestMailFlowCommand extends Command
{
    protected $signature = 'test:mail-flow
                            {--username=demo.ahmet : Şifre sıfırlama kodu için kullanıcı adı}
                            {--student=9001 : Öğrenci bildirimi için öğrenci numarası}
                            {--to= : Tüm test maillerini doğrudan bu adrese gönder (öğrenci/hoca email\'ini bypass eder)}
                            {--show-log : Tetiklenen mailin log içeriğini göster (driver=log ise)}';

    protected $description = 'Tüm mail akışlarını tetikler ve log driver çıktısını gösterir.';

    public function handle(): int
    {
        $driver = config('mail.default');
        $host = config('mail.mailers.'.$driver.'.host', '—');
        $logPath = storage_path('logs/laravel.log');
        $to = $this->option('to');

        $this->info("Mail driver: {$driver} (host: {$host})");
        if ($to) {
            $this->info("Override alıcı: {$to}");
        }
        if ($driver === 'log') {
            $this->info("Log path:    {$logPath}");
        }
        $this->newLine();

        // === 1) Şifre sıfırlama kodu maili ===
        $this->line('=== 1) Şifre Sıfırlama Kodu Maili ===');
        $username = $this->option('username');
        $user = User::where('username', $username)->first();

        if (! $user) {
            $this->warn("Kullanıcı bulunamadı: {$username} — bu adımı atlıyorum.");
        } else {
            try {
                if ($to) {
                    // Override: --to varsa direkt o adrese, sahte 6-haneli kod ile
                    $code = (string) random_int(100000, 999999);
                    Mail::to($to)->send(new PasswordResetCodeMail(
                        name: $user->name ?: $user->username,
                        username: $user->username,
                        code: $code,
                        expiresInMinutes: 15,
                    ));
                    $this->info("✓ Mail tetiklendi → {$to}  (subject: 'SmartRollCall — Şifre Sıfırlama Kodu', kod: {$code})");
                } elseif (empty($user->email)) {
                    $this->warn("Kullanıcı email yok: {$username}. --to=<adres> ile override edebilirsin.");
                } else {
                    $service = app(AuthService::class);
                    $sent = $service->sendPasswordResetCode($username);
                    $this->info($sent
                        ? "✓ Mail tetiklendi → {$user->email}  (subject: 'SmartRollCall — Şifre Sıfırlama Kodu')"
                        : '✗ Gönderilemedi (kullanıcı yok ya da mail boş).'
                    );
                }
            } catch (\Throwable $e) {
                $this->error('Hata: '.$e->getMessage());
            }
        }

        $this->newLine();

        // === 2) Öğrenci bildirim maili ===
        $this->line('=== 2) Öğrenci Bildirim Maili ===');
        $studentNumber = $this->option('student');
        $student = Student::where('student_number', $studentNumber)->first();

        if (! $student) {
            $this->warn("Öğrenci bulunamadı: {$studentNumber}.");
        } else {
            $recipient = $to ?: $student->email;
            if (empty($recipient)) {
                $this->warn("Öğrenci email yok: {$studentNumber}. --to=<adres> ile override edebilirsin.");
            } else {
                try {
                    Mail::to($recipient)->send(new StudentNotificationMail(
                        studentName: $student->name,
                        teacherName: 'Demo Hoca',
                        classroomName: $student->classroom?->name ?? 'Test Sınıfı',
                        body: "Sayın {$student->name},\n\nBu bir test bildirimidir. Mail sisteminin çalıştığını gösterir.\n\nİyi çalışmalar.",
                        mailSubject: 'Test Bildirimi — SmartRollCall',
                    ));
                    $this->info("✓ Mail tetiklendi → {$recipient}  (subject: 'Test Bildirimi — SmartRollCall')");
                } catch (\Throwable $e) {
                    $this->error('Hata: '.$e->getMessage());
                }
            }
        }

        $this->newLine();

        // === 3) Log içeriğinden son maili oku ===
        if ($driver === 'log' && $this->option('show-log')) {
            $this->line('=== 3) Log Çıktısı (son 80 satır) ===');
            if (! File::exists($logPath)) {
                $this->warn('Log dosyası bulunamadı.');
            } else {
                $content = File::get($logPath);
                $lines = explode("\n", $content);
                $tail = array_slice($lines, -80);
                $this->line(implode("\n", $tail));
            }
        } elseif ($driver === 'log') {
            $this->newLine();
            $this->comment("Mailler log dosyasına yazıldı (gerçek gönderim yok).");
            $this->comment("İçeriği görmek için:");
            $this->comment("  php artisan test:mail-flow --show-log");
            $this->comment("veya:");
            $this->comment("  Get-Content {$logPath} -Tail 100");
        } else {
            $this->info('Driver gerçek mail gönderiyor — log dosyası kontrolüne gerek yok.');
        }

        return self::SUCCESS;
    }
}
