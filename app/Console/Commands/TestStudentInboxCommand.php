<?php

namespace App\Console\Commands;

use App\Models\Student;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

class TestStudentInboxCommand extends Command
{
    protected $signature = 'test:student-inbox {--student_number=9001}';
    protected $description = 'Öğrenci inbox endpoint zincirini test eder.';

    public function handle(): int
    {
        $sNum = $this->option('student_number');
        $student = Student::where('student_number', $sNum)->first();
        if (! $student) {
            $this->error("Öğrenci bulunamadı: {$sNum}");
            return self::FAILURE;
        }

        $token = $student->createToken('inbox-test')->plainTextToken;

        // 1) /inbox
        $r1 = $this->hit('GET', '/api/student/inbox', $token);
        $this->line("1) GET /inbox → status={$r1['status']} unread={$r1['body']['data']['unread']} items=".count($r1['body']['data']['items'] ?? []));
        foreach (array_slice($r1['body']['data']['items'] ?? [], 0, 5) as $item) {
            $this->line("   [{$item['type']}] {$item['title']}");
        }

        // 2) /inbox/unread-count
        $r2 = $this->hit('GET', '/api/student/inbox/unread-count', $token);
        $this->line("2) GET /inbox/unread-count → status={$r2['status']} unread={$r2['body']['data']['unread']}");

        // 3) markRead bir mesaj
        $firstId = $r1['body']['data']['items'][0]['id'] ?? null;
        if ($firstId) {
            $r3 = $this->hit('POST', "/api/student/inbox/{$firstId}/read", $token);
            $this->line("3) POST /inbox/{$firstId}/read → status={$r3['status']} is_read={$r3['body']['data']['is_read']}");
        }

        // 4) markAllRead
        $r4 = $this->hit('POST', '/api/student/inbox/read-all', $token);
        $this->line("4) POST /inbox/read-all → marked={$r4['body']['data']['marked']}");

        // 5) son sayım: 0 olmalı
        $r5 = $this->hit('GET', '/api/student/inbox/unread-count', $token);
        $this->line("5) Son unread sayım: {$r5['body']['data']['unread']}");

        $student->tokens()->where('name', 'inbox-test')->delete();

        $allOk = $r1['status'] === 200 && $r2['status'] === 200 && $r5['body']['data']['unread'] === 0;
        $this->newLine();
        $allOk ? $this->info('Inbox testleri tamam.') : $this->error('Beklenmedik sonuç.');
        return $allOk ? self::SUCCESS : self::FAILURE;
    }

    private function hit(string $method, string $path, string $token): array
    {
        $req = Request::create($path, $method);
        $req->headers->set('Authorization', 'Bearer '.$token);
        $req->headers->set('Accept', 'application/json');
        $resp = app()->handle($req);
        return [
            'status' => $resp->getStatusCode(),
            'body'   => json_decode($resp->getContent(), true) ?? [],
        ];
    }
}
