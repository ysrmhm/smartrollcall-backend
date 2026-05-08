<?php

namespace App\Console\Commands;

use App\Models\Student;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class StudentAuthE2ECommand extends Command
{
    protected $signature = 'student:e2e {--student_number=}';
    protected $description = 'Student login + me + dashboard akışını test eder.';

    public function handle(): int
    {
        $sNum = $this->option('student_number');
        $student = $sNum
            ? Student::where('student_number', $sNum)->first()
            : Student::whereNotNull('password')->whereNotNull('classroom_id')->first();

        if (! $student) {
            $this->error('Test edecek öğrenci bulunamadı.');
            return self::FAILURE;
        }

        $this->line("Test öğrencisi: id={$student->id} student_number={$student->student_number} (".$student->name.")");

        // 1) Şifre kontrolü
        $passwordOk = Hash::check($student->student_number, $student->password ?? '');
        $this->line('1) Hash::check(student_number) → '.($passwordOk ? 'OK' : 'FAIL'));
        if (! $passwordOk) {
            $this->error('Şifre eşleşmiyor! Backfill çalıştırıldı mı?');
            return self::FAILURE;
        }

        // 2) Token üret
        $token = $student->createToken('e2e-test')->plainTextToken;
        $this->line('2) Token üretildi: '.substr($token, 0, 25).'...');

        // 3) /api/student/me
        $req = Request::create('/api/student/me', 'GET');
        $req->headers->set('Authorization', 'Bearer '.$token);
        $req->headers->set('Accept', 'application/json');
        $resp = app()->handle($req);
        $this->line("3) GET /api/student/me → status={$resp->getStatusCode()}");
        $body = json_decode($resp->getContent(), true);
        if ($resp->getStatusCode() !== 200) {
            $this->error('me endpoint başarısız: '.$resp->getContent());
            return self::FAILURE;
        }
        $this->line('   data.name = '.($body['data']['name'] ?? '?'));
        $this->line('   data.classroom = '.($body['data']['classroom']['name'] ?? '?'));
        $this->line('   data.must_change_password = '.($body['data']['must_change_password'] ? 'true' : 'false'));

        // 4) /api/student/dashboard
        $req2 = Request::create('/api/student/dashboard', 'GET');
        $req2->headers->set('Authorization', 'Bearer '.$token);
        $req2->headers->set('Accept', 'application/json');
        $resp2 = app()->handle($req2);
        $this->line("4) GET /api/student/dashboard → status={$resp2->getStatusCode()}");
        $body2 = json_decode($resp2->getContent(), true);
        if ($resp2->getStatusCode() !== 200) {
            $this->error('dashboard endpoint başarısız: '.$resp2->getContent());
            return self::FAILURE;
        }
        $s = $body2['data']['summary'] ?? [];
        $this->line('   sınıf = '.($body2['data']['classroom']['name'] ?? '?'));
        $this->line("   limit={$s['limit']} present={$s['present']} absent={$s['absent']} excused={$s['excused']} status={$s['status']}");
        $this->line('   today.isClassDay='.(($body2['data']['today']['isClassDay'] ?? false) ? 'true' : 'false'));

        // 5) /api/student/attendances
        $req3 = Request::create('/api/student/attendances', 'GET');
        $req3->headers->set('Authorization', 'Bearer '.$token);
        $req3->headers->set('Accept', 'application/json');
        $resp3 = app()->handle($req3);
        $this->line("5) GET /api/student/attendances → status={$resp3->getStatusCode()}");
        $body3 = json_decode($resp3->getContent(), true);
        $this->line('   '.count($body3['data'] ?? []).' yoklama kaydı');

        // 6) Token temizliği
        $student->tokens()->where('name', 'e2e-test')->delete();
        $this->line('6) Test tokenları silindi.');

        // 7) HTTP POST /api/student/login (gerçek route)
        $loginReq = Request::create('/api/student/login', 'POST', [
            'student_number' => $student->student_number,
            'password'       => $student->student_number,
        ]);
        $loginReq->headers->set('Accept', 'application/json');
        $loginResp = app()->handle($loginReq);
        $loginBody = json_decode($loginResp->getContent(), true);
        $this->line("7) POST /api/student/login → status={$loginResp->getStatusCode()}");
        if ($loginResp->getStatusCode() !== 200) {
            $this->error('Login endpoint başarısız: '.$loginResp->getContent());
            return self::FAILURE;
        }
        $this->line('   token alındı: '.substr($loginBody['data']['access_token'] ?? '', 0, 25).'...');
        $this->line('   must_change_password = '.($loginBody['data']['must_change_password'] ? 'true' : 'false'));

        // 8) Yanlış şifre testi
        $badReq = Request::create('/api/student/login', 'POST', [
            'student_number' => $student->student_number,
            'password'       => 'definitely-wrong-pwd',
        ]);
        $badReq->headers->set('Accept', 'application/json');
        $badResp = app()->handle($badReq);
        $this->line("8) POST /api/student/login (yanlış şifre) → status={$badResp->getStatusCode()}");
        if ($badResp->getStatusCode() !== 401) {
            $this->error('Yanlış şifre 401 dönmedi: '.$badResp->getContent());
            return self::FAILURE;
        }

        // Test sonrası tüm test tokenlarını temizle
        $student->tokens()->where('name', 'student-auth')->delete();

        $this->info('E2E test başarılı.');
        return self::SUCCESS;
    }
}
