<?php

namespace App\Console\Commands;

use App\Models\Classroom;
use App\Models\MakeupSession;
use App\Models\Student;
use App\Models\StudentInboxMessage;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

class TestMakeupSessionCommand extends Command
{
    protected $signature = 'test:makeup-session';
    protected $description = 'Telafi dersi oluşturma uçtan-uca akışını test eder.';

    public function handle(): int
    {
        // Cleanup eski test verisi
        MakeupSession::where('note', 'like', '[E2E]%')->delete();

        $teacher = User::where('username', 'demo.ahmet')->first();
        $student = Student::where('student_number', '9001')->first();
        $classroom = Classroom::where('user_id', $teacher->id)->first();
        if (! $teacher || ! $student || ! $classroom) {
            $this->error('Demo veri eksik.');
            return self::FAILURE;
        }

        $tToken = $teacher->createToken('makeup-session-test')->plainTextToken;
        $sToken = $student->createToken('makeup-session-test')->plainTextToken;

        // Bildirim sayısı (öncesi)
        $beforeNotifs = StudentInboxMessage::where('student_id', $student->id)->count();

        // 1) Hoca → POST telafi oluştur
        $futureDate = now()->addDays(3)->toDateString();
        $r1 = $this->hit('POST', "/api/classrooms/{$classroom->id}/makeup-sessions", $tToken, [
            'date' => $futureDate,
            'time' => '14:00',
            'note' => '[E2E] test oluşturma',
        ]);
        $this->line("1) POST /makeup-sessions → status={$r1['status']}");
        if ($r1['status'] !== 201) {
            $this->error('Oluşturulamadı: '.json_encode($r1['body']));
            return self::FAILURE;
        }
        $createdId = $r1['body']['data']['id'];
        $this->line("   id={$createdId} day={$r1['body']['data']['day']} date={$r1['body']['data']['date']}");

        // 2) Aynı slotu tekrar oluşturmayı dene (422 beklenir)
        $r2 = $this->hit('POST', "/api/classrooms/{$classroom->id}/makeup-sessions", $tToken, [
            'date' => $futureDate,
            'time' => '14:00',
        ]);
        $this->line("2) Duplicate → status={$r2['status']} (422 beklenir): {$r2['body']['message']}");

        // 3) Hoca → list (kendi sınıfı için)
        $r3 = $this->hit('GET', "/api/classrooms/{$classroom->id}/makeup-sessions", $tToken);
        $this->line("3) Hoca list → count=".count($r3['body']['data'] ?? []));

        // 4) Öğrenci → /student/schedule (makeups field'ı var mı?)
        $r4 = $this->hit('GET', '/api/student/schedule', $sToken);
        $makeups = $r4['body']['data']['makeups'] ?? [];
        $this->line("4) Öğrenci schedule.makeups count=".count($makeups));
        if (! empty($makeups)) {
            $first = $makeups[0];
            $this->line("   ilk: {$first['day']} {$first['date_label']} {$first['time']} - {$first['classroom_name']} (hoca: {$first['teacher_name']})");
        }

        // 5) Öğrenciye bildirim düştü mü?
        $afterNotifs = StudentInboxMessage::where('student_id', $student->id)->count();
        $this->line("5) Öğrenci bildirim sayısı: {$beforeNotifs} → {$afterNotifs} (artmış olmalı)");

        // 6) İptal et
        $r6 = $this->hit('DELETE', "/api/makeup-sessions/{$createdId}", $tToken);
        $this->line("6) DELETE → status={$r6['status']}");

        // 7) Kontrol: artık yok
        $r7 = $this->hit('GET', "/api/classrooms/{$classroom->id}/makeup-sessions", $tToken);
        $this->line("7) İptal sonrası list count=".count($r7['body']['data'] ?? []));

        $teacher->tokens()->where('name', 'makeup-session-test')->delete();
        $student->tokens()->where('name', 'makeup-session-test')->delete();

        $allOk = $r1['status'] === 201 && $r2['status'] === 422 && count($makeups) > 0
            && $afterNotifs > $beforeNotifs && $r6['status'] === 200;
        $this->newLine();
        $allOk ? $this->info('Telafi session E2E BAŞARILI.') : $this->error('Beklenmedik sonuç.');
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
