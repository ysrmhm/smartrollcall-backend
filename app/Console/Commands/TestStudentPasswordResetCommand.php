<?php

namespace App\Console\Commands;

use App\Models\Student;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TestStudentPasswordResetCommand extends Command
{
    protected $signature = 'test:student-pw-reset {--student=9001 : Test edilecek öğrenci numarası}';
    protected $description = 'Öğrenci şifremi unuttum + reset akışını test eder.';

    public function handle(): int
    {
        $sNum = $this->option('student');

        // 1) Forgot — kod üret
        $r1 = $this->hit('POST', '/api/student/forgot-password', ['student_number' => $sNum]);
        $this->line("1) forgot-password → status={$r1['status']} message={$r1['body']['message']}");

        if ($r1['status'] !== 200) return self::FAILURE;

        // 2) DB'den kodu manuel oku (test için — gerçek hayatta öğrenci e-postadan alır)
        $row = DB::table('password_reset_codes')->where('username', "student:{$sNum}")->first();
        if (! $row) {
            $this->error('Kod kaydedilmedi.');
            return self::FAILURE;
        }
        $this->line("2) DB'de kod kaydı var (hash). Test için sahte kod denemesi:");

        // 3) Yanlış kod denemesi
        $r3 = $this->hit('POST', '/api/student/reset-password', [
            'student_number' => $sNum,
            'code'           => '000000',
            'password'       => 'newpass123',
            'password_confirmation' => 'newpass123',
        ]);
        $this->line("3) Yanlış kod → status={$r3['status']} (422 beklenir): {$r3['body']['message']}");

        // 4) Doğru kod — kodu yeniden üret + reset
        // (gerçek kullanımda öğrenci kodu mailden alır; test için sahte kod ile fonksiyonel akış görmek için yeni bir kod üretmem lazım)
        // Mevcut hash bilinmiyor, yeni bir forgot çağrısı yap + log'dan kod oku
        // Daha basit: doğrudan DB'ye yeni bir kod ekle, hash bilelim
        $testCode = '123456';
        DB::table('password_reset_codes')->where('username', "student:{$sNum}")->update([
            'code_hash'  => \Illuminate\Support\Facades\Hash::make($testCode),
            'expires_at' => now()->addMinutes(15),
            'updated_at' => now(),
        ]);
        $r4 = $this->hit('POST', '/api/student/reset-password', [
            'student_number' => $sNum,
            'code'           => $testCode,
            'password'       => 'newpass123',
            'password_confirmation' => 'newpass123',
        ]);
        $this->line("4) Doğru kod → status={$r4['status']}: {$r4['body']['message']}");

        // 5) Yeni şifreyle login dene
        $r5 = $this->hit('POST', '/api/student/login', [
            'student_number' => $sNum,
            'password'       => 'newpass123',
        ]);
        $this->line("5) Yeni şifreyle login → status={$r5['status']}");
        if ($r5['status'] === 200) {
            $this->line("   must_change_password={$r5['body']['data']['must_change_password']} (false beklenir)");
        }

        // 6) Cleanup — şifreyi student_number'a geri çevir
        $student = Student::where('student_number', $sNum)->first();
        if ($student) {
            foreach (Student::where('student_number', $sNum)->get() as $s) {
                $s->forceFill(['password' => $sNum, 'must_change_password' => true])->save();
            }
            $this->line("6) Cleanup: şifre öğrenci numarasına geri çevrildi.");
        }

        $allOk = $r1['status'] === 200 && $r3['status'] === 422 && $r4['status'] === 200 && $r5['status'] === 200;
        $this->newLine();
        $allOk ? $this->info('Şifre sıfırlama akışı BAŞARILI.') : $this->error('Beklenmeyen sonuç.');
        return $allOk ? self::SUCCESS : self::FAILURE;
    }

    private function hit(string $method, string $path, array $payload = []): array
    {
        $req = Request::create($path, $method, [], [], [], [], json_encode($payload));
        $req->headers->set('Content-Type', 'application/json');
        $req->headers->set('Accept', 'application/json');
        $resp = app()->handle($req);
        return ['status' => $resp->getStatusCode(), 'body' => json_decode($resp->getContent(), true) ?? []];
    }
}
