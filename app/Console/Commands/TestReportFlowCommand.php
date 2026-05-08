<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

class TestReportFlowCommand extends Command
{
    protected $signature = 'test:report-flow';
    protected $description = '/api/reports/options ve /api/reports/data endpoint zincirini test eder.';

    public function handle(): int
    {
        $teacher = User::where('username', 'demo.ahmet')->first();
        if (! $teacher) {
            $this->error('demo.ahmet bulunamadı.');
            return self::FAILURE;
        }

        $token = $teacher->createToken('report-test')->plainTextToken;

        // 1) options
        $r1 = $this->hit('GET', '/api/reports/options', $token);
        $this->line("1) GET /reports/options → status={$r1['status']} classroom_count=".count($r1['body']['data']['classrooms'] ?? []));

        // 2) data — son 6 ay tüm sınıflar
        $from = now()->subMonths(6)->toDateString();
        $to   = now()->toDateString();
        $r2 = $this->hit('GET', "/api/reports/data?from={$from}&to={$to}", $token);
        $this->line("2) GET /reports/data?from={$from}&to={$to} → status={$r2['status']}");
        $body2 = $r2['body']['data'] ?? [];
        $this->line('   classrooms='.count($body2['classrooms'] ?? []));
        $this->line('   students='.count($body2['students'] ?? []));
        $this->line('   sessions='.count($body2['sessions'] ?? []));
        $this->line("   totals: total={$body2['totals']['total']} present={$body2['totals']['present']} absent={$body2['totals']['absent']} excused={$body2['totals']['excused']} rate=".($body2['totals']['attendance_rate'] ?? '-'));

        // 3) data — geçersiz aralık (to < from)
        $r3 = $this->hit('GET', '/api/reports/data?from=2026-12-31&to=2026-01-01', $token);
        $this->line("3) Geçersiz aralık → status={$r3['status']} (422 beklenir)");

        // 4) data — sadece tek sınıf filtresi
        $firstClassId = $r1['body']['data']['classrooms'][0]['id'] ?? null;
        if ($firstClassId) {
            $r4 = $this->hit('GET', "/api/reports/data?from={$from}&to={$to}&classroom_ids[]={$firstClassId}", $token);
            $this->line("4) Tek sınıf filtresi (id={$firstClassId}) → classrooms=".count($r4['body']['data']['classrooms'] ?? []).' (1 beklenir)');
        }

        // Token temizle
        $teacher->tokens()->where('name', 'report-test')->delete();

        $allOk = $r1['status'] === 200 && $r2['status'] === 200 && $r3['status'] === 422;
        $this->newLine();
        $allOk ? $this->info('Report endpoint testi BAŞARILI.') : $this->error('Beklenmedik sonuç.');
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
