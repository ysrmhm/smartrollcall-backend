<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

class TestScheduleConflictCommand extends Command
{
    protected $signature = 'test:schedule-conflict';
    protected $description = 'ClassroomController çakışma kontrolünü test eder.';

    public function handle(): int
    {
        $teacher = User::where('username', 'demo.ahmet')->first();
        if (! $teacher) {
            $this->error('demo.ahmet bulunamadı. Önce seed:bilgisayar-prog çalıştırın.');
            return self::FAILURE;
        }

        $token = $teacher->createToken('conflict-test')->plainTextToken;

        // 1) Çakışan slot — Pzt 09:00 zaten var (BP101)
        $r1 = $this->callApi($token, 'POST', '/api/classrooms', [
            'name'       => 'Çakışma Test Dersi',
            'department' => 'Bilgisayar Teknolojileri Bölümü',
            'day'        => 'Pazartesi',
            'time'       => '09:00',
        ]);
        $this->line("1) Çakışan slot (Pzt 09:00) → status={$r1['status']}");
        $this->line('   message: '.($r1['body']['message'] ?? '-'));

        // 2) Müsait slot — Cuma 14:00
        $r2 = $this->callApi($token, 'POST', '/api/classrooms', [
            'name'       => 'Müsait Slot Testi',
            'department' => 'Bilgisayar Teknolojileri Bölümü',
            'day'        => 'Cuma',
            'time'       => '14:00',
        ]);
        $this->line("2) Müsait slot (Cuma 14:00) → status={$r2['status']}");

        // Cleanup
        if (isset($r2['body']['data']['id'])) {
            $createdId = $r2['body']['data']['id'];
            $this->callApi($token, 'DELETE', "/api/classrooms/{$createdId}");
            $this->line("   cleanup → silindi (id={$createdId})");
        }

        // 3) Geçersiz bölüm
        $r3 = $this->callApi($token, 'POST', '/api/classrooms', [
            'name'       => 'Geçersiz Bölüm Testi',
            'department' => 'Olmayan Bölüm',
            'day'        => 'Pazartesi',
            'time'       => '08:00',
        ]);
        $this->line("3) Geçersiz bölüm → status={$r3['status']}");
        $this->line('   errors.department: '.json_encode($r3['body']['errors']['department'] ?? null, JSON_UNESCAPED_UNICODE));

        // Token temizle
        $teacher->tokens()->where('name', 'conflict-test')->delete();

        $allOk = $r1['status'] === 422 && $r2['status'] === 201 && $r3['status'] === 422;
        $this->newLine();
        $allOk ? $this->info('Çakışma kontrol testleri tamam.') : $this->error('Beklenmeyen sonuç.');
        return $allOk ? self::SUCCESS : self::FAILURE;
    }

    private function callApi(string $token, string $method, string $path, array $payload = []): array
    {
        $req = Request::create($path, $method, [], [], [], [], json_encode($payload));
        $req->headers->set('Authorization', 'Bearer '.$token);
        $req->headers->set('Accept', 'application/json');
        $req->headers->set('Content-Type', 'application/json');
        $resp = app()->handle($req);
        return [
            'status' => $resp->getStatusCode(),
            'body'   => json_decode($resp->getContent(), true) ?? [],
        ];
    }
}
