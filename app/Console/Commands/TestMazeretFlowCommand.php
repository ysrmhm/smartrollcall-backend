<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\MazeretRequest;
use App\Models\Student;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class TestMazeretFlowCommand extends Command
{
    protected $signature = 'test:mazeret-flow';
    protected $description = 'Mazeret modülünün uçtan-uca akışını test eder.';

    public function handle(): int
    {
        // Önce eski test mazeretlerini temizle
        $existingTest = MazeretRequest::where('reason', 'like', '[E2E TEST]%')->get();
        foreach ($existingTest as $m) {
            Storage::disk('local')->delete($m->file_path);
            $m->delete();
        }

        $student = Student::where('student_number', '9001')->first();
        if (! $student) {
            $this->error('9001 öğrencisi bulunamadı. Önce seed:bilgisayar-prog çalıştır.');
            return self::FAILURE;
        }

        $teacher = User::where('username', 'demo.ahmet')->first();
        if (! $teacher) {
            $this->error('demo.ahmet hocası bulunamadı.');
            return self::FAILURE;
        }

        $studentToken = $student->createToken('mazeret-e2e')->plainTextToken;
        $teacherToken = $teacher->createToken('mazeret-e2e')->plainTextToken;

        // 1) Öğrenci → POST /api/student/mazerets (multipart)
        $tempPath = sys_get_temp_dir().'/test_rapor.pdf';
        file_put_contents($tempPath, "%PDF-1.4\n%E2E test fake PDF content\n%%EOF");
        $upload = new UploadedFile($tempPath, 'test_rapor.pdf', 'application/pdf', null, true);

        $req1 = Request::create('/api/student/mazerets', 'POST', [
            'classroom_id' => $student->classroom_id,
            'date'         => now()->subDays(2)->toDateString(),
            'reason'       => '[E2E TEST] Hastaydım',
        ], [], ['file' => $upload]);
        $req1->headers->set('Authorization', 'Bearer '.$studentToken);
        $req1->headers->set('Accept', 'application/json');
        $resp1 = app()->handle($req1);
        $body1 = json_decode($resp1->getContent(), true);
        $this->line("1) Öğrenci POST /mazerets → status={$resp1->getStatusCode()}");
        if ($resp1->getStatusCode() !== 201) {
            $this->error('Yükleme başarısız: '.$resp1->getContent());
            return self::FAILURE;
        }
        $mazeretId = $body1['data']['id'];
        $this->line("   id={$mazeretId} status={$body1['data']['status']} sınıf={$body1['data']['classroom_name']}");

        // 2) Hoca → GET /api/mazerets?status=pending
        $req2 = Request::create('/api/mazerets', 'GET', ['status' => 'pending']);
        $req2->headers->set('Authorization', 'Bearer '.$teacherToken);
        $req2->headers->set('Accept', 'application/json');
        $resp2 = app()->handle($req2);
        $body2 = json_decode($resp2->getContent(), true);
        $this->line("2) Hoca GET /mazerets?status=pending → status={$resp2->getStatusCode()} count=".count($body2['data']['items'] ?? []));

        // 3) Hoca → GET /api/mazerets/pending-count
        $req3 = Request::create('/api/mazerets/pending-count', 'GET');
        $req3->headers->set('Authorization', 'Bearer '.$teacherToken);
        $req3->headers->set('Accept', 'application/json');
        $resp3 = app()->handle($req3);
        $body3 = json_decode($resp3->getContent(), true);
        $this->line("3) Pending count: {$body3['data']['pending']}");

        // 4) Hoca → POST /api/mazerets/{id}/approve
        $req4 = Request::create("/api/mazerets/{$mazeretId}/approve", 'POST', [
            'review_note' => 'Geçmiş olsun.',
        ]);
        $req4->headers->set('Authorization', 'Bearer '.$teacherToken);
        $req4->headers->set('Accept', 'application/json');
        $resp4 = app()->handle($req4);
        $body4 = json_decode($resp4->getContent(), true);
        $this->line("4) Hoca approve → status={$resp4->getStatusCode()} new_status={$body4['data']['status']}");

        // 5) Attendance otomatik 'excused' olmuş mu?
        $att = Attendance::where('student_id', $body1['data']['student_id'])
            ->where('classroom_id', $body1['data']['classroom_id'])
            ->where('date', $body1['data']['date'])
            ->first();
        $this->line('5) Attendance kontrol: '.($att ? "status={$att->status}" : 'yok'));

        // 6) Dosya download (öğrenci)
        $req6 = Request::create("/api/student/mazerets/{$mazeretId}/file", 'GET');
        $req6->headers->set('Authorization', 'Bearer '.$studentToken);
        $resp6 = app()->handle($req6);
        $this->line("6) Öğrenci dosya indirme → status={$resp6->getStatusCode()} (200 beklenir)");

        // 7) Başka bir hoca'nın dosyaya erişim denemesi (403 beklenir)
        $otherTeacher = User::where('username', 'demo.zeynep')->first();
        if ($otherTeacher) {
            $otherToken = $otherTeacher->createToken('mazeret-e2e')->plainTextToken;
            $req7 = Request::create("/api/mazerets/{$mazeretId}/file", 'GET');
            $req7->headers->set('Authorization', 'Bearer '.$otherToken);
            $resp7 = app()->handle($req7);
            $this->line("7) Yetkisiz hoca dosya indirme → status={$resp7->getStatusCode()} (403 beklenir)");
            $otherTeacher->tokens()->where('name', 'mazeret-e2e')->delete();
        }

        // 8) Aynı tarihte tekrar yükleme denemesi (422 beklenir — zaten approved var)
        $tempPath2 = sys_get_temp_dir().'/test_rapor2.pdf';
        file_put_contents($tempPath2, "%PDF-1.4\n%test 2\n%%EOF");
        $upload2 = new UploadedFile($tempPath2, 'test2.pdf', 'application/pdf', null, true);
        $req8 = Request::create('/api/student/mazerets', 'POST', [
            'classroom_id' => $student->classroom_id,
            'date'         => now()->subDays(2)->toDateString(),
            'reason'       => '[E2E TEST] tekrar deneme',
        ], [], ['file' => $upload2]);
        $req8->headers->set('Authorization', 'Bearer '.$studentToken);
        $req8->headers->set('Accept', 'application/json');
        $resp8 = app()->handle($req8);
        $body8 = json_decode($resp8->getContent(), true);
        $this->line("8) Duplicate yükleme → status={$resp8->getStatusCode()} (422 beklenir): {$body8['message']}");

        // Token temizliği
        $student->tokens()->where('name', 'mazeret-e2e')->delete();
        $teacher->tokens()->where('name', 'mazeret-e2e')->delete();

        // Test verilerini temizle
        $cleanup = MazeretRequest::where('reason', 'like', '[E2E TEST]%')->get();
        foreach ($cleanup as $m) {
            Storage::disk('local')->delete($m->file_path);
            $m->delete();
        }
        Attendance::where('student_id', $body1['data']['student_id'])
            ->where('classroom_id', $body1['data']['classroom_id'])
            ->where('date', $body1['data']['date'])
            ->delete();

        @unlink($tempPath);
        @unlink($tempPath2);

        $allOk = $resp1->getStatusCode() === 201
            && $resp2->getStatusCode() === 200
            && $resp4->getStatusCode() === 200
            && $body4['data']['status'] === 'approved'
            && $att && $att->status === 'excused'
            && $resp6->getStatusCode() === 200
            && $resp8->getStatusCode() === 422;

        $this->newLine();
        $allOk ? $this->info('E2E mazeret testi BAŞARILI.') : $this->error('Beklenmedik sonuç var.');
        return $allOk ? self::SUCCESS : self::FAILURE;
    }
}
