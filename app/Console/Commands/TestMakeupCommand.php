<?php

namespace App\Console\Commands;

use App\Models\Classroom;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

class TestMakeupCommand extends Command
{
    protected $signature = 'test:makeup';
    protected $description = '/api/classrooms/{id}/makeup-suggestions endpoint çıktısını yazdırır.';

    public function handle(): int
    {
        $teacher = User::where('username', 'demo.ahmet')->first();
        if (! $teacher) {
            $this->error('demo.ahmet yok.');
            return self::FAILURE;
        }

        $classroom = Classroom::where('user_id', $teacher->id)->first();
        if (! $classroom) {
            $this->error('Hocanın sınıfı yok.');
            return self::FAILURE;
        }

        $token = $teacher->createToken('makeup-test')->plainTextToken;
        $req = Request::create("/api/classrooms/{$classroom->id}/makeup-suggestions", 'GET');
        $req->headers->set('Authorization', 'Bearer '.$token);
        $req->headers->set('Accept', 'application/json');
        $resp = app()->handle($req);
        $body = json_decode($resp->getContent(), true);

        $this->line("status={$resp->getStatusCode()}");
        $this->line("Sınıf: {$body['data']['classroom']['name']} ({$body['data']['classroom']['day']} {$body['data']['classroom']['time']})");
        $this->line("Öğrenci: {$body['data']['students_count']}");
        $this->line("Pencere: {$body['data']['window']['from']} → {$body['data']['window']['to']}");
        $this->line("Tatiller: ".count($body['data']['holiday_dates'] ?? []));
        $this->newLine();

        $this->line('=== En İyi 3 Öneri ===');
        foreach ($body['data']['suggestions'] as $i => $s) {
            $rank = $i + 1;
            $this->line("{$rank}) {$s['day']} {$s['time']} — %{$s['score']} ({$s['rating']['label']}) · {$s['free_count']}/{$s['students_count']} müsait");
            if ($s['busy_count'] > 0) {
                $names = collect($s['busy_students'])->pluck('name')->implode(', ');
                $this->line("   Çakışan: {$names}");
            }
            $dates = collect($s['next_dates'])->pluck('label')->implode(' · ');
            $this->line("   Sonraki tarihler: {$dates}");
            $this->newLine();
        }

        $teacher->tokens()->where('name', 'makeup-test')->delete();
        return self::SUCCESS;
    }
}
