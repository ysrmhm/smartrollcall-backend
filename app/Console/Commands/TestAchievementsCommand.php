<?php

namespace App\Console\Commands;

use App\Models\Student;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

class TestAchievementsCommand extends Command
{
    protected $signature = 'test:achievements {--student_number=9001}';
    protected $description = '/api/student/achievements endpoint sonuçlarını yazdırır.';

    public function handle(): int
    {
        $sNum = $this->option('student_number');
        $student = Student::where('student_number', $sNum)->first();
        if (! $student) {
            $this->error("Öğrenci bulunamadı: {$sNum}");
            return self::FAILURE;
        }

        $token = $student->createToken('achievements-test')->plainTextToken;

        $req = Request::create('/api/student/achievements', 'GET');
        $req->headers->set('Authorization', 'Bearer '.$token);
        $req->headers->set('Accept', 'application/json');
        $resp = app()->handle($req);
        $body = json_decode($resp->getContent(), true);

        $this->line("status={$resp->getStatusCode()}");
        $this->line('--- Stats ---');
        foreach ($body['data']['stats'] ?? [] as $k => $v) {
            $this->line("  {$k} = ".(is_scalar($v) ? $v : json_encode($v)));
        }
        $this->line('--- Badges ---');
        foreach ($body['data']['badges'] ?? [] as $b) {
            $earned = $b['earned'] ? '✓' : '✗';
            $pct = round($b['progress'] * 100);
            $this->line("  [{$earned}] {$b['name']} — {$b['detail']} (%{$pct})");
        }

        $student->tokens()->where('name', 'achievements-test')->delete();
        return self::SUCCESS;
    }
}
