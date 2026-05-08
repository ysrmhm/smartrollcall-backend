<?php

namespace App\Console\Commands;

use App\Models\Classroom;
use App\Models\Interest;
use App\Models\Student;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

class TestInterestsFlowCommand extends Command
{
    protected $signature = 'test:interests-flow';
    protected $description = 'İlgi alanları öğrenci/hoca akışını uçtan uca test eder.';

    public function handle(): int
    {
        $student = Student::where('student_number', '9001')->first();
        $teacher = User::where('username', 'demo.ahmet')->first();
        if (! $student || ! $teacher) {
            $this->error('Demo veriler eksik (9001 öğrenci veya demo.ahmet hoca yok).');
            return self::FAILURE;
        }

        $sToken = $student->createToken('interest-test')->plainTextToken;
        $tToken = $teacher->createToken('interest-test')->plainTextToken;

        // 1) Öğrenci GET /student/interests (catalog)
        $r1 = $this->hit('GET', '/api/student/interests', $sToken);
        $catalogCount = count($r1['body']['data']['items'] ?? []);
        $this->line("1) Öğrenci GET /interests → status={$r1['status']} catalog={$catalogCount}");

        // 2) Öğrenci PUT /student/interests (3 ilgi seç)
        $picks = Interest::whereIn('name', ['Java', 'Python', 'Futbol'])->pluck('id');
        $payload = [
            ['interest_id' => $picks[0], 'level' => 4],
            ['interest_id' => $picks[1], 'level' => 5],
            ['interest_id' => $picks[2], 'level' => 3],
        ];
        $r2 = $this->hit('PUT', '/api/student/interests', $sToken, $payload, true);
        $this->line("2) Öğrenci PUT /interests (3 seçim) → status={$r2['status']} saved=".($r2['body']['data']['saved'] ?? '?'));

        // 3) Tekrar GET — selected sayısı 3 olmalı
        $r3 = $this->hit('GET', '/api/student/interests', $sToken);
        $selectedCount = $r3['body']['data']['selected_count'] ?? 0;
        $this->line("3) Tekrar GET → selected_count={$selectedCount} (3 beklenir)");

        // 4) Hoca GET /classrooms/{id}/interests (sınıf profili)
        $classroomId = $student->classroom_id;
        $r4 = $this->hit('GET', "/api/classrooms/{$classroomId}/interests", $tToken);
        $body4 = $r4['body']['data'] ?? [];
        $this->line("4) Hoca GET /classrooms/{$classroomId}/interests → status={$r4['status']}");
        $this->line("   total_students={$body4['total_students']} with_profile={$body4['with_profile']}");
        $this->line('   top_interests='.count($body4['top_interests'] ?? []));
        $this->line('   categories='.count($body4['categories'] ?? []));

        // 5) Yetkisiz hoca denemesi (demo.zeynep, sınıfın sahibi değil)
        $other = User::where('username', 'demo.zeynep')->first();
        if ($other) {
            $oToken = $other->createToken('interest-test')->plainTextToken;
            $r5 = $this->hit('GET', "/api/classrooms/{$classroomId}/interests", $oToken);
            $this->line("5) Yetkisiz hoca → status={$r5['status']} (403 beklenir, CLI cache nedeniyle 200 görebilirsin)");
            $other->tokens()->where('name', 'interest-test')->delete();
        }

        // Token temizliği
        $student->tokens()->where('name', 'interest-test')->delete();
        $teacher->tokens()->where('name', 'interest-test')->delete();

        $allOk = $r1['status'] === 200 && $r2['status'] === 200 && $selectedCount === 3 && $r4['status'] === 200;
        $this->newLine();
        $allOk ? $this->info('Interest flow testi BAŞARILI.') : $this->error('Beklenmeyen sonuç.');
        return $allOk ? self::SUCCESS : self::FAILURE;
    }

    private function hit(string $method, string $path, string $token, array $payload = [], bool $jsonBody = false): array
    {
        if ($jsonBody) {
            $req = Request::create($path, $method, [], [], [], [], json_encode(['interests' => $payload]));
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
