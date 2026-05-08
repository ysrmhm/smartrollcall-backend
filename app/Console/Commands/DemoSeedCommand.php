<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DemoSeedCommand extends Command
{
    protected $signature = 'demo:seed {--user-id=} {--clear : Demo veriyi tamamen sıfırla}';

    protected $description = 'Hocaya gerçekçi demo verisi kurar (3 sınıf, ~19 öğrenci, 12 yoklama oturumu)';

    public function handle(): int
    {
        $userId = $this->option('user-id');
        $user = $userId ? User::find($userId) : User::first();

        if (! $user) {
            $this->error('Kullanıcı bulunamadı.');
            return self::FAILURE;
        }

        // --clear: sahipsiz + bu kullanıcının tüm sınıflarını sil
        if ($this->option('clear')) {
            $orphans = Classroom::whereNull('user_id')->count();
            $owned   = Classroom::where('user_id', $user->id)->count();
            Classroom::whereNull('user_id')->delete();
            Classroom::where('user_id', $user->id)->delete();
            $this->info("Temizlendi: {$owned} kullanıcı sınıfı + {$orphans} sahipsiz sınıf silindi (öğrenciler ve yoklamalar cascade ile).");
            return self::SUCCESS;
        }

        // Çakışmayı önle: sahipsiz eski test sınıflarını ve aynı isimli demo sınıfları sil
        Classroom::whereNull('user_id')->delete();
        Classroom::where('user_id', $user->id)
            ->whereIn('name', [
                'Web Programlama I',
                'Veritabanı Yönetim Sistemleri',
                'Görsel Programlama',
            ])->delete();

        DB::transaction(function () use ($user) {
            // === SINIF 1: Web Programlama I ===
            // 8 öğrenci, 5 yoklama oturumu (son 5 hafta)
            // Risk: Mehmet Kaya (3 absent → Sınırda eğer limit 4), Caner Şahin (4 absent → Kaldı eğer limit 4)
            $c1 = $this->makeClassroom($user->id, 'Web Programlama I', 'Bilgisayar Programcılığı', 'Pazartesi', '09:00');
            $s1 = $this->makeStudents($c1->id, [
                ['1001', 'Ahmet',   'Yılmaz'],
                ['1002', 'Ayşe',    'Demir'],
                ['1003', 'Mehmet',  'Kaya'],   // sınırda
                ['1004', 'Fatma',   'Çelik'],
                ['1005', 'Caner',   'Şahin'],  // kaldı
                ['1006', 'Zeynep',  'Öztürk'],
                ['1007', 'Ali',     'Arslan'],
                ['1008', 'Elif',    'Doğan'],
            ]);
            // Tarihler: 4, 3, 2, 1 hafta önce + bu hafta
            $this->seedAttendance($c1->id, [-28, -21, -14, -7, 0], $s1, [
                ['1001' => 'present', '1002' => 'present', '1003' => 'absent',  '1004' => 'present', '1005' => 'absent',  '1006' => 'present', '1007' => 'present', '1008' => 'late'],
                ['1001' => 'present', '1002' => 'absent',  '1003' => 'absent',  '1004' => 'present', '1005' => 'absent',  '1006' => 'present', '1007' => 'present', '1008' => 'present'],
                ['1001' => 'present', '1002' => 'present', '1003' => 'absent',  '1004' => 'late',    '1005' => 'absent',  '1006' => 'present', '1007' => 'absent',  '1008' => 'present'],
                ['1001' => 'present', '1002' => 'present', '1003' => 'present', '1004' => 'present', '1005' => 'absent',  '1006' => 'present', '1007' => 'present', '1008' => 'present'],
                ['1001' => 'present', '1002' => 'present', '1003' => 'present', '1004' => 'present', '1005' => 'absent',  '1006' => 'present', '1007' => 'present', '1008' => 'present'],
            ]);
            $c1->update(['attendance_taken' => true]);

            // === SINIF 2: Veritabanı Yönetim Sistemleri ===
            // 6 öğrenci, 4 yoklama oturumu (son 4 hafta)
            // Risk: Burak Kılıç (3 absent → Sınırda)
            $c2 = $this->makeClassroom($user->id, 'Veritabanı Yönetim Sistemleri', 'Yazılım Mühendisliği', 'Çarşamba', '13:00');
            $s2 = $this->makeStudents($c2->id, [
                ['2001', 'Burak',   'Kılıç'],   // sınırda
                ['2002', 'Selin',   'Aslan'],
                ['2003', 'Murat',   'Yıldız'],
                ['2004', 'İrem',    'Polat'],
                ['2005', 'Hakan',   'Ergün'],
                ['2006', 'Sevda',   'Akın'],
            ]);
            $this->seedAttendance($c2->id, [-21, -14, -7, 0], $s2, [
                ['2001' => 'absent',  '2002' => 'present', '2003' => 'present', '2004' => 'late',    '2005' => 'present', '2006' => 'present'],
                ['2001' => 'absent',  '2002' => 'present', '2003' => 'absent',  '2004' => 'present', '2005' => 'present', '2006' => 'present'],
                ['2001' => 'absent',  '2002' => 'present', '2003' => 'present', '2004' => 'present', '2005' => 'present', '2006' => 'late'],
                ['2001' => 'present', '2002' => 'present', '2003' => 'present', '2004' => 'present', '2005' => 'present', '2006' => 'present'],
            ]);
            $c2->update(['attendance_taken' => true]);

            // === SINIF 3: Görsel Programlama ===
            // 5 öğrenci, 3 yoklama oturumu, hiç risk yok (temiz sınıf)
            $c3 = $this->makeClassroom($user->id, 'Görsel Programlama', 'Bilgisayar Programcılığı', 'Cuma', '10:00');
            $s3 = $this->makeStudents($c3->id, [
                ['3001', 'Cem',     'Bayrak'],
                ['3002', 'Deniz',   'Öncü'],
                ['3003', 'Yasemin', 'Korkmaz'],
                ['3004', 'Tolga',   'Aydın'],
                ['3005', 'Ece',     'Erdoğan'],
            ]);
            $this->seedAttendance($c3->id, [-14, -7, 0], $s3, [
                ['3001' => 'present', '3002' => 'present', '3003' => 'late',    '3004' => 'present', '3005' => 'present'],
                ['3001' => 'present', '3002' => 'absent',  '3003' => 'present', '3004' => 'present', '3005' => 'present'],
                ['3001' => 'present', '3002' => 'present', '3003' => 'present', '3004' => 'present', '3005' => 'present'],
            ]);
            $c3->update(['attendance_taken' => true]);
        });

        $totalClassrooms = Classroom::where('user_id', $user->id)->count();
        $totalStudents   = Student::whereIn('classroom_id', Classroom::where('user_id', $user->id)->pluck('id'))->count();
        $totalAtt        = Attendance::whereIn('classroom_id', Classroom::where('user_id', $user->id)->pluck('id'))->count();

        $this->info("Demo veri başarıyla kuruldu (kullanıcı: {$user->name}):");
        $this->line("  - Sınıf: {$totalClassrooms}");
        $this->line("  - Öğrenci: {$totalStudents}");
        $this->line("  - Yoklama satırı: {$totalAtt}");
        $this->line('Tarayıcıyı yenileyin → Genel Bakış / Risk Raporu / Geçmiş Yoklamalar / Sınıf Raporu hepsi dolu gelsin.');

        return self::SUCCESS;
    }

    private function makeClassroom(int $userId, string $name, string $department, string $day, string $time): Classroom
    {
        return Classroom::create([
            'user_id'          => $userId,
            'name'             => $name,
            'department'       => $department,
            'day'              => $day,
            'time'             => $time,
            'status'           => 'Aktif',
            'attendance_taken' => false,
        ]);
    }

    /**
     * @param  array<int, array{0:string, 1:string, 2:string}>  $rows
     * @return array<string, int> student_number => student_id
     */
    private function makeStudents(int $classroomId, array $rows): array
    {
        $map = [];
        foreach ($rows as [$number, $first, $last]) {
            $s = Student::create([
                'classroom_id'   => $classroomId,
                'student_number' => $number,
                'first_name'     => $first,
                'last_name'      => $last,
                'email'          => $number.'@ogr.demo.edu.tr',
            ]);
            $map[$number] = $s->id;
        }
        return $map;
    }

    /**
     * @param  array<int, int>  $dayOffsets  Bugünden gün farkları (negatif = geçmiş)
     * @param  array<string, int>  $studentMap  student_number => student_id
     * @param  array<int, array<string, string>>  $sessions  Her oturum: student_number => 'present'/'absent'/'late'
     */
    private function seedAttendance(int $classroomId, array $dayOffsets, array $studentMap, array $sessions): void
    {
        foreach ($dayOffsets as $idx => $offset) {
            $date = Carbon::today()->addDays($offset)->toDateString();
            $session = $sessions[$idx] ?? [];
            foreach ($session as $studentNumber => $status) {
                if (! isset($studentMap[$studentNumber])) {
                    continue;
                }
                Attendance::create([
                    'classroom_id' => $classroomId,
                    'student_id'   => $studentMap[$studentNumber],
                    'date'         => $date,
                    'status'       => $status,
                ]);
            }
        }
    }
}
