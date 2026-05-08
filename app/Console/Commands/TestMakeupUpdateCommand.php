<?php

namespace App\Console\Commands;

use App\Models\Classroom;
use App\Models\MakeupSession;
use App\Models\Student;
use App\Models\StudentInboxMessage;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

class TestMakeupUpdateCommand extends Command
{
    protected $signature = 'test:makeup-update';
    protected $description = 'Telafi update + cancel akışı + bildirim üretimi.';

    public function handle(): int
    {
        MakeupSession::where('note', 'like', '[E2E-UPDATE]%')->delete();

        $teacher = User::where('username', 'demo.ahmet')->first();
        $classroom = Classroom::where('user_id', $teacher->id)->first();
        $student = Student::where('classroom_id', $classroom->id)->first() ?: Student::where('student_number', '9001')->first();
        if (! $teacher || ! $classroom || ! $student) {
            $this->error('Demo veri eksik.');
            return self::FAILURE;
        }

        $tToken = $teacher->createToken('mu-test')->plainTextToken;
        $beforeCnt = StudentInboxMessage::where('student_id', $student->id)->count();

        // 1) Oluştur
        $createDate = now()->addDays(5)->toDateString();
        $r1 = $this->hit('POST', "/api/classrooms/{$classroom->id}/makeup-sessions", $tToken, [
            'date' => $createDate, 'time' => '14:00', 'note' => '[E2E-UPDATE] orjinal',
        ]);
        $this->line("1) create → status={$r1['status']} id=".($r1['body']['data']['id'] ?? '?'));
        $sid = $r1['body']['data']['id'] ?? null;

        // 2) Update — saati değiştir
        $r2 = $this->hit('PUT', "/api/makeup-sessions/{$sid}", $tToken, [
            'date' => $createDate, 'time' => '15:30', 'note' => '[E2E-UPDATE] saat değişti',
        ]);
        $this->line("2) update (15:30) → status={$r2['status']} new_time={$r2['body']['data']['time']}");

        // 3) Update — başka bir telafi'yle çakıştır (önce 2. bir tane oluşturup aynı slota gitmeyi dene)
        $r3a = $this->hit('POST', "/api/classrooms/{$classroom->id}/makeup-sessions", $tToken, [
            'date' => $createDate, 'time' => '16:00', 'note' => '[E2E-UPDATE] çakışma için',
        ]);
        $sid2 = $r3a['body']['data']['id'] ?? null;
        // Şimdi sid'i 16:00'a çekmeyi dene — sid2 ile çakışmalı
        $r3 = $this->hit('PUT', "/api/makeup-sessions/{$sid}", $tToken, [
            'date' => $createDate, 'time' => '16:00', 'note' => 'çakışma denemesi',
        ]);
        $this->line("3) çakışan update → status={$r3['status']} (422 beklenir)");

        // 4) Cancel
        $r4 = $this->hit('DELETE', "/api/makeup-sessions/{$sid}", $tToken);
        $this->line("4) cancel → status={$r4['status']}");

        // 5) Bildirim sayısı artmış mı? (create + update + cancel = 3 bildirim)
        $afterCnt = StudentInboxMessage::where('student_id', $student->id)->count();
        $this->line("5) Bildirim: {$beforeCnt} → {$afterCnt} (en az 3 artış beklenir)");

        // Cleanup
        if ($sid2) MakeupSession::where('id', $sid2)->delete();
        $teacher->tokens()->where('name', 'mu-test')->delete();

        $allOk = $r1['status'] === 201 && $r2['status'] === 200 && $r3['status'] === 422 && $r4['status'] === 200 && ($afterCnt - $beforeCnt) >= 3;
        $this->newLine();
        $allOk ? $this->info('Update + cancel akışı BAŞARILI.') : $this->error('Beklenmedik sonuç.');
        return $allOk ? self::SUCCESS : self::FAILURE;
    }

    private function hit(string $method, string $path, string $token, array $payload = []): array
    {
        if (in_array($method, ['POST', 'PUT'], true)) {
            $req = Request::create($path, $method, [], [], [], [], json_encode($payload));
            $req->headers->set('Content-Type', 'application/json');
        } else {
            $req = Request::create($path, $method);
        }
        $req->headers->set('Authorization', 'Bearer '.$token);
        $req->headers->set('Accept', 'application/json');
        $resp = app()->handle($req);
        return ['status' => $resp->getStatusCode(), 'body' => json_decode($resp->getContent(), true) ?? []];
    }
}
