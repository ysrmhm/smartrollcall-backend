<?php

namespace App\Console\Commands;

use App\Models\Classroom;
use App\Models\Student;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

class TestAnnouncementsFlowCommand extends Command
{
    protected $signature = 'test:announcements-flow';
    protected $description = 'Duyuru gönderim ve öğrenci tarafı görüntüleme akışını test eder.';

    public function handle(): int
    {
        $teacher = User::where('username', 'demo.ahmet')->first();
        $student = Student::where('student_number', '9001')->first();
        if (! $teacher || ! $student) {
            $this->error('demo.ahmet veya 9001 yok.');
            return self::FAILURE;
        }

        $classroom = Classroom::where('user_id', $teacher->id)->first();
        if (! $classroom) {
            $this->error('Hocanın sınıfı yok.');
            return self::FAILURE;
        }

        $tToken = $teacher->createToken('ann-test')->plainTextToken;
        $sToken = $student->createToken('ann-test')->plainTextToken;

        // 1) Hoca → preview
        $r1 = $this->hit('POST', "/api/classrooms/{$classroom->id}/announcements/preview", $tToken, ['audience' => 'all']);
        $this->line("1) preview (all) → status={$r1['status']} recipient_count=".($r1['body']['data']['recipient_count'] ?? '?'));

        // 2) Hoca → store (duyuru gönder)
        $r2 = $this->hit('POST', "/api/classrooms/{$classroom->id}/announcements", $tToken, [
            'audience' => 'all',
            'title'    => 'Yarınki ders iptal',
            'body'     => 'Sayın öğrenciler, yarınki dersimiz mücbir sebepten iptal edilmiştir. İyi günler dilerim.',
        ]);
        $this->line("2) store → status={$r2['status']} recipients=".($r2['body']['data']['recipients_count'] ?? '?'));

        // 3) Hoca → list (history)
        $r3 = $this->hit('GET', '/api/announcements', $tToken);
        $this->line("3) hoca history → items=".count($r3['body']['data']['items'] ?? []));

        // 4) Öğrenci → /student/messages
        $r4 = $this->hit('GET', '/api/student/messages', $sToken);
        $body4 = $r4['body']['data'] ?? [];
        $this->line("4) öğrenci messages → status={$r4['status']} items=".count($body4['items'] ?? []).' unread='.($body4['unread'] ?? 0));
        if (! empty($body4['items'])) {
            $first = $body4['items'][0];
            $this->line("   ilk mesaj: '{$first['title']}' from {$first['sender_name']} ({$first['classroom_name']})");
        }

        // 5) Öğrenci inbox bell — duyuru oraya da düşmüş olmalı
        $r5 = $this->hit('GET', '/api/student/inbox', $sToken);
        $bellCount = count($r5['body']['data']['items'] ?? []);
        $this->line("5) inbox bell → items={$bellCount}");

        // 6) Hoca → empty audience (absent_today, hiç yoklama yok)
        $r6 = $this->hit('POST', "/api/classrooms/{$classroom->id}/announcements", $tToken, [
            'audience' => 'absent_today',
            'title'    => 'Test',
            'body'     => 'Bu mesaj kimseye gitmemeli.',
        ]);
        $this->line("6) absent_today (boş hedef) → status={$r6['status']} (422 beklenir)");

        // Cleanup
        $teacher->tokens()->where('name', 'ann-test')->delete();
        $student->tokens()->where('name', 'ann-test')->delete();

        $allOk = $r1['status'] === 200 && $r2['status'] === 201 && $r4['status'] === 200 && $r6['status'] === 422;
        $this->newLine();
        $allOk ? $this->info('Announcement flow testi BAŞARILI.') : $this->error('Beklenmeyen sonuç.');
        return $allOk ? self::SUCCESS : self::FAILURE;
    }

    private function hit(string $method, string $path, string $token, array $payload = []): array
    {
        if ($method === 'POST' || $method === 'PUT') {
            $req = Request::create($path, $method, [], [], [], [], json_encode($payload));
            $req->headers->set('Content-Type', 'application/json');
        } else {
            $req = Request::create($path, $method);
        }
        $req->headers->set('Authorization', 'Bearer '.$token);
        $req->headers->set('Accept', 'application/json');
        $resp = app()->handle($req);
        return [
            'status' => $resp->getStatusCode(),
            'body'   => json_decode($resp->getContent(), true) ?? [],
        ];
    }
}
