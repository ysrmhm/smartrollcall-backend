<?php

namespace App\Console\Commands;

use App\Models\Classroom;
use App\Models\Student;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeedBilgisayarProgramcilikCommand extends Command
{
    protected $signature = 'seed:bilgisayar-prog {--reset : Önce mevcut demo verisini temizle}';
    protected $description = 'Bilgisayar Teknolojileri Bölümü için 5 hoca + 8 ders + 1 demo öğrenci üretir.';

    private const DEPT = 'Bilgisayar Teknolojileri Bölümü';
    private const DEMO_TAG = '[demo-bilgprog]';

    private array $teachers = [
        ['username' => 'demo.ahmet',   'first' => 'Ahmet',   'last' => 'Demir',    'email' => 'ahmet.demir@demo.smartroll'],
        ['username' => 'demo.ayse',    'first' => 'Ayşe',    'last' => 'Yılmaz',   'email' => 'ayse.yilmaz@demo.smartroll'],
        ['username' => 'demo.mehmet',  'first' => 'Mehmet',  'last' => 'Kaya',     'email' => 'mehmet.kaya@demo.smartroll'],
        ['username' => 'demo.zeynep',  'first' => 'Zeynep',  'last' => 'Öztürk',   'email' => 'zeynep.ozturk@demo.smartroll'],
        ['username' => 'demo.caner',   'first' => 'Caner',   'last' => 'Şahin',    'email' => 'caner.sahin@demo.smartroll'],
    ];

    // 8 ders, çakışmayan zaman dilimleri (5 hoca arasında dağıtılmış)
    private array $courses = [
        ['name' => 'Web Programlama I',             'code' => 'BP101', 'day' => 'Pazartesi', 'time' => '09:00', 'teacher_idx' => 0],
        ['name' => 'Veritabanı Yönetim Sistemleri', 'code' => 'BP102', 'day' => 'Pazartesi', 'time' => '13:00', 'teacher_idx' => 1],
        ['name' => 'Yapay Zeka Temelleri',          'code' => 'BP103', 'day' => 'Salı',      'time' => '09:00', 'teacher_idx' => 2],
        ['name' => 'Görsel Programlama',            'code' => 'BP104', 'day' => 'Salı',      'time' => '14:00', 'teacher_idx' => 3],
        ['name' => 'Web Programlama II',            'code' => 'BP201', 'day' => 'Çarşamba',  'time' => '10:00', 'teacher_idx' => 0],
        ['name' => 'Mobil Uygulama Geliştirme',     'code' => 'BP202', 'day' => 'Çarşamba',  'time' => '15:00', 'teacher_idx' => 4],
        ['name' => 'Bilgisayar Ağları',             'code' => 'BP203', 'day' => 'Perşembe',  'time' => '11:00', 'teacher_idx' => 1],
        ['name' => 'Yazılım Test Mühendisliği',     'code' => 'BP301', 'day' => 'Cuma',      'time' => '09:00', 'teacher_idx' => 2],
    ];

    public function handle(): int
    {
        if ($this->option('reset')) {
            $this->cleanup();
        }

        DB::transaction(function () {
            // 1) Hocalar
            $userIds = [];
            foreach ($this->teachers as $t) {
                $user = User::updateOrCreate(
                    ['username' => $t['username']],
                    [
                        'first_name'  => $t['first'],
                        'last_name'   => $t['last'],
                        'name'        => $t['first'].' '.$t['last'],
                        'email'       => $t['email'],
                        'institution' => self::DEMO_TAG.' Demo Üniversitesi',
                        'password'    => $t['username'], // hashed cast otomatik
                        'is_admin'    => false,
                    ]
                );
                $userIds[] = $user->id;
            }

            // 2) Sınıflar
            $createdClassrooms = [];
            foreach ($this->courses as $c) {
                $teacherId = $userIds[$c['teacher_idx']];

                $classroom = Classroom::updateOrCreate(
                    ['code' => $c['code']],
                    [
                        'user_id'          => $teacherId,
                        'name'             => $c['name'],
                        'department'       => self::DEPT,
                        'day'              => $c['day'],
                        'time'             => $c['time'],
                        'status'           => 'Aktif',
                        'attendance_taken' => false,
                        'archived_at'      => null,
                    ]
                );
                $createdClassrooms[] = $classroom;
            }

            // 3) Demo öğrenci — 4 derse kayıt (numara her sınıfta aynı: 9001)
            $demoStudent = [
                'student_number' => '9001',
                'first_name'     => 'Emre',
                'last_name'      => 'Yılmaz',
                'email'          => 'emre.yilmaz@demo.smartroll',
                'phone'          => '5551234567',
            ];

            // İlk 4 dersi seç
            $enrollIn = array_slice($createdClassrooms, 0, 4);

            foreach ($enrollIn as $classroom) {
                Student::updateOrCreate(
                    [
                        'classroom_id'   => $classroom->id,
                        'student_number' => $demoStudent['student_number'],
                    ],
                    [
                        'first_name'           => $demoStudent['first_name'],
                        'last_name'            => $demoStudent['last_name'],
                        'email'                => $demoStudent['email'],
                        'phone'                => $demoStudent['phone'],
                        'password'             => $demoStudent['student_number'],
                        'must_change_password' => true,
                    ]
                );
            }
        });

        $this->info('Demo veri hazır.');
        $this->newLine();

        $this->line('5 Demo Hocası (giriş: kullanıcı adı = şifre):');
        $this->table(
            ['Kullanıcı Adı', 'Ad Soyad', 'Şifre'],
            collect($this->teachers)->map(fn ($t) => [$t['username'], $t['first'].' '.$t['last'], $t['username']])->all()
        );

        $this->newLine();
        $this->line('1 Demo Öğrencisi:');
        $this->table(
            ['Öğrenci No', 'Ad Soyad', 'Şifre', 'Kayıtlı Ders Sayısı'],
            [['9001', 'Emre Yılmaz', '9001', 4]]
        );

        $this->newLine();
        $this->line('Haftalık ders dağılımı (Bilgisayar Teknolojileri Bölümü):');
        $rows = [];
        foreach ($this->courses as $c) {
            $teacher = $this->teachers[$c['teacher_idx']];
            $rows[] = [$c['day'], $c['time'], $c['code'], $c['name'], $teacher['first'].' '.$teacher['last']];
        }
        $this->table(['Gün', 'Saat', 'Kod', 'Ders', 'Hoca'], $rows);

        return self::SUCCESS;
    }

    private function cleanup(): void
    {
        $this->warn('Demo verisi siliniyor...');

        // Hocaların user_id'lerini al
        $userIds = User::whereIn('username', collect($this->teachers)->pluck('username'))->pluck('id');

        // Bu hocalara ait demo sınıfları sil (cascade öğrencileri ve yoklamaları siler)
        Classroom::whereIn('user_id', $userIds)->where('department', self::DEPT)->delete();

        // Hocaları sil
        User::whereIn('id', $userIds)->delete();

        $this->info('Demo verisi temizlendi.');
    }
}
