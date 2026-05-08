<?php

namespace Database\Seeders;

use App\Models\Announcement;
use App\Models\Attendance;
use App\Models\Classroom;
use App\Models\Holiday;
use App\Models\InboxMessage;
use App\Models\Interest;
use App\Models\MakeupSession;
use App\Models\MazeretRequest;
use App\Models\Student;
use App\Models\StudentInboxMessage;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * SmartRollCall — KAPSAMLI DEMO SEED
 *
 * 7 bölüm × 3 hoca = 21 hoca
 * 7 bölüm × 2 grup (1.sınıf, 2.sınıf) = 14 öğrenci grubu
 * Her grupta 10 öğrenci, her hoca grubuna 1 ders → 7×3×2 = 42 sınıf
 * Tek bir öğrenci (student_number) aynı bölümdeki 3 farklı dersle ilişkilenir.
 *
 * Tüm yoklama, mazeret, duyuru, telafi, ilgi alanı, rozet ve tatil verisi seed edilir.
 *
 * php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    private array $departments = [
        'Bilgisayar Teknolojileri Bölümü',
        'Elektrik ve Enerji Bölümü',
        'Elektronik ve Otomasyon Bölümü',
        'Gıda İşleme Bölümü',
        'İnşaat Bölümü',
        'Makine ve Metal Teknolojileri Bölümü',
        'Tekstil Giyim, Ayakkabı ve Deri Bölümü',
    ];

    /** Bölüm bazlı 3 ders (her hocaya 1 ders düşer). */
    private array $coursesByDept = [
        'Bilgisayar Teknolojileri Bölümü'        => ['Web Programlama I', 'Veritabanı Yönetim Sistemleri', 'Yapay Zeka Temelleri'],
        'Elektrik ve Enerji Bölümü'              => ['Elektrik Devre Analizi', 'Elektrik Makineleri', 'Yenilenebilir Enerji Sistemleri'],
        'Elektronik ve Otomasyon Bölümü'         => ['Sayısal Elektronik', 'PLC ile Otomasyon', 'Robotik Sistemler'],
        'Gıda İşleme Bölümü'                     => ['Gıda Mikrobiyolojisi', 'Gıda Güvenliği ve HACCP', 'Süt ve Süt Ürünleri Teknolojisi'],
        'İnşaat Bölümü'                          => ['Statik', 'Beton Teknolojisi', 'Yapı Bilgisi'],
        'Makine ve Metal Teknolojileri Bölümü'   => ['Teknik Resim', 'CNC Tezgah Programlama', 'Bilgisayar Destekli Tasarım (CAD)'],
        'Tekstil Giyim, Ayakkabı ve Deri Bölümü' => ['Tekstil Lifleri', 'Dokuma Teknolojisi', 'Konfeksiyon Üretim Teknikleri'],
    ];

    /** Her bölüm için 3 hoca (ad, soyad, username). Şifre: tüm hocalarda aynı. */
    private array $teachersByDept = [
        'Bilgisayar Teknolojileri Bölümü' => [
            ['Ahmet',  'Yılmaz',   'demo.bil1'],
            ['Zeynep', 'Kaya',     'demo.bil2'],
            ['Murat',  'Demir',    'demo.bil3'],
        ],
        'Elektrik ve Enerji Bölümü' => [
            ['Mehmet',  'Şahin',  'demo.elk1'],
            ['Elif',    'Çelik',  'demo.elk2'],
            ['Hasan',   'Aydın',  'demo.elk3'],
        ],
        'Elektronik ve Otomasyon Bölümü' => [
            ['Burak',  'Öztürk',   'demo.eot1'],
            ['Selin',  'Arslan',   'demo.eot2'],
            ['Cenk',   'Doğan',    'demo.eot3'],
        ],
        'Gıda İşleme Bölümü' => [
            ['Aylin',   'Polat',  'demo.gid1'],
            ['Tolga',   'Erdem',  'demo.gid2'],
            ['Esra',    'Yıldız', 'demo.gid3'],
        ],
        'İnşaat Bölümü' => [
            ['Kerem',  'Koç',     'demo.ins1'],
            ['Pelin',  'Aksoy',   'demo.ins2'],
            ['Onur',   'Türkmen', 'demo.ins3'],
        ],
        'Makine ve Metal Teknolojileri Bölümü' => [
            ['Cem',    'Bozkurt', 'demo.mak1'],
            ['Defne',  'Sarı',    'demo.mak2'],
            ['Sinan',  'Acar',    'demo.mak3'],
        ],
        'Tekstil Giyim, Ayakkabı ve Deri Bölümü' => [
            ['Berk',   'Tunç',    'demo.tek1'],
            ['Yasemin','Avcı',    'demo.tek2'],
            ['Mert',   'Yalçın',  'demo.tek3'],
        ],
    ];

    /** Öğrenci ad havuzu — bölüme göre rastgele dağıtılır. */
    private array $studentFirstNames = [
        'Ahmet', 'Mehmet', 'Mustafa', 'Ali', 'Hüseyin', 'İbrahim', 'Hasan', 'Murat',
        'Emre', 'Burak', 'Cem', 'Onur', 'Eren', 'Berk', 'Kerem', 'Tolga', 'Cenk', 'Ozan',
        'Zeynep', 'Ayşe', 'Fatma', 'Elif', 'Selin', 'Esra', 'Aylin', 'Pınar', 'Defne',
        'Yasemin', 'Pelin', 'Merve', 'Beyza', 'Ceren', 'Damla', 'Ece', 'Gizem', 'Hilal',
    ];

    private array $studentLastNames = [
        'Yılmaz', 'Kaya', 'Demir', 'Şahin', 'Çelik', 'Aydın', 'Öztürk', 'Arslan', 'Doğan',
        'Polat', 'Erdem', 'Yıldız', 'Koç', 'Aksoy', 'Türkmen', 'Bozkurt', 'Sarı', 'Acar',
        'Tunç', 'Avcı', 'Yalçın', 'Aktaş', 'Korkmaz', 'Güneş', 'Çetin', 'Aslan', 'Tekin',
        'Kara', 'Bulut', 'Erdoğan', 'Toprak', 'Güler', 'Yavuz', 'Kurt', 'Yıldırım',
    ];

    private array $weekDays = ['Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma'];
    private array $timeSlots = ['08:00', '09:00', '10:00', '11:00', '13:00', '14:00', '15:00', '16:00'];

    public function run(): void
    {
        $this->command->info('SmartRollCall demo verileri yükleniyor...');

        // 0) Mevcut tabloları temizle (FK sırasına göre)
        $this->truncate();

        // 1) Hocalar
        $teachers = $this->createTeachers();
        $totalTeachers = array_sum(array_map(fn ($l) => count($l), $teachers));
        $this->command->info("  ✓ {$totalTeachers} hoca oluşturuldu");

        // 2) Öğrenci grupları (her bölüm için 2 grup × 10 öğrenci = 20 öğrenci numarası)
        $studentGroups = $this->buildStudentGroups();
        $totalUniqueStudents = array_sum(array_map(fn ($groups) => array_sum(array_map(fn ($g) => count($g), $groups)), $studentGroups));
        $this->command->info("  ✓ {$totalUniqueStudents} eşsiz öğrenci numarası hazırlandı");

        // 3) Sınıf + öğrenci kayıtları
        $classroomMap = $this->createClassroomsAndStudents($teachers, $studentGroups);
        $totalClasses = array_sum(array_map(fn ($cs) => count($cs), $classroomMap));
        $this->command->info("  ✓ {$totalClasses} sınıf ve öğrenci kayıtları oluşturuldu");

        // 4) Yoklama (10 hafta geriye dönük)
        $this->seedAttendances($classroomMap);
        $this->command->info('  ✓ Yoklama kayıtları üretildi (10 haftalık geçmiş)');

        // 5) Mazeretler (bekleyen + onaylanmış + reddedilmiş)
        $this->seedMazerets($classroomMap, $teachers);
        $this->command->info('  ✓ Mazeret talepleri eklendi');

        // 6) Duyurular
        $this->seedAnnouncements($classroomMap);
        $this->command->info('  ✓ Sınıf duyuruları eklendi');

        // 7) Telafi dersleri
        $this->seedMakeupSessions($classroomMap, $teachers);
        $this->command->info('  ✓ Telafi dersleri planlandı');

        // 8) İlgi alanları (catalog + öğrenci seçimleri)
        $this->seedInterests($studentGroups);
        $this->command->info('  ✓ İlgi alanları seed edildi');

        // 9) Showcase rozetleri (rastgele ~30%)
        $this->seedShowcaseBadges();
        $this->command->info('  ✓ Showcase rozetleri atandı');

        // 10) Tatiller (her hocaya 2026 resmi tatilleri)
        $this->seedHolidays($teachers);
        $this->command->info('  ✓ Resmi tatiller eklendi');

        // 11) İnbox: hoca + öğrenci için örnek bildirimler
        $this->seedInbox($teachers);
        $this->command->info('  ✓ Bildirimler eklendi');

        $this->command->info('');
        $this->command->info('============================================================');
        $this->command->info('  DEMO HAZIR! Giriş bilgileri:');
        $this->command->info('  Hoca:     demo.bil1 / 123456  (21 hoca, hepsi 123456)');
        $this->command->info('  Öğrenci:  2401001 / 2401001    (140 öğrenci, ilk girişte');
        $this->command->info('            şifre değişimi ZORUNLU değil — demo modu)');
        $this->command->info('============================================================');
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function truncate(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        } elseif ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF');
        }

        $tables = [
            'student_showcase_badges', 'student_interests', 'interests',
            'mazeret_requests', 'makeup_sessions',
            'student_inbox_messages', 'announcements', 'inbox_messages',
            'holidays', 'attendances', 'students', 'classrooms',
            'password_reset_codes', 'personal_access_tokens', 'users',
        ];
        foreach ($tables as $t) {
            if (\Illuminate\Support\Facades\Schema::hasTable($t)) {
                DB::table($t)->truncate();
            }
        }

        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        } elseif ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }

    /** @return array<string, User[]> bölüm → hoca dizisi */
    private function createTeachers(): array
    {
        $defaultPrefs = [
            'defaultAbsenceLimit'   => 4,
            'emailNotifications'    => true,
            'weeklyReports'         => true,
            'showArchivedInReports' => false,
        ];

        $byDept = [];
        foreach ($this->teachersByDept as $dept => $list) {
            $byDept[$dept] = [];
            foreach ($list as [$first, $last, $username]) {
                $u = User::create([
                    'name'        => "{$first} {$last}",
                    'first_name'  => $first,
                    'last_name'   => $last,
                    'username'    => $username,
                    'email'       => $username.'@smartroll.demo',
                    'phone'       => '0532'.random_int(1000000, 9999999),
                    'institution' => 'Demo Meslek Yüksekokulu — '.$dept,
                    'password'    => Hash::make('123456'),
                    'is_admin'    => true,
                    'preferences' => $defaultPrefs,
                ]);
                $byDept[$dept][] = $u;
            }
        }
        return $byDept;
    }

    /**
     * Öğrenci grupları: bölüm → [grup1=[student_number,first,last], grup2=[...]]
     * Aynı student_number aynı bölümdeki 3 hocanın 3 sınıfında da gözükür.
     */
    private function buildStudentGroups(): array
    {
        $groups = [];
        $deptIndex = 0;
        foreach ($this->departments as $dept) {
            // Her bölüm için 2 grup (1.sınıf ve 2.sınıf)
            $groups[$dept] = [];

            foreach ([1, 2] as $year) {
                $list = [];
                for ($i = 1; $i <= 10; $i++) {
                    // Numara şeması: Y-D-NN  → 24-01-001 → 2401001 (1.sınıf, ilk bölüm, 1. öğrenci)
                    $num = sprintf('24%02d%03d', ($deptIndex * 2) + $year, $i);
                    $first = $this->studentFirstNames[array_rand($this->studentFirstNames)];
                    $last = $this->studentLastNames[array_rand($this->studentLastNames)];
                    $list[] = [
                        'student_number' => $num,
                        'first_name'     => $first,
                        'last_name'      => $last,
                        'email'          => strtolower($this->ascii($first).'.'.$this->ascii($last)).$num.'@smartroll.demo',
                        'phone'          => '0533'.random_int(1000000, 9999999),
                    ];
                }
                $groups[$dept][$year] = $list;
            }
            $deptIndex++;
        }
        return $groups;
    }

    /**
     * Her hoca, kendi bölümündeki 2 gruba 1 ders verir → 2 sınıf yaratır.
     * Aynı öğrenci aynı bölümdeki 3 sınıfta gözükür (3 farklı Student row).
     *
     * @return array<int, array<int>>  classroom IDs by [teacher_id => [classroom_id, ...]]
     */
    private function createClassroomsAndStudents(array $teachersByDept, array $studentGroups): array
    {
        $byTeacher = [];

        foreach ($this->departments as $deptIdx => $dept) {
            $teachers = $teachersByDept[$dept];
            $courses  = $this->coursesByDept[$dept];

            foreach ($teachers as $tIdx => $teacher) {
                $course = $courses[$tIdx];
                $byTeacher[$teacher->id] = [];

                foreach ([1, 2] as $year) {
                    // Gün ve saat dağıt: aynı bölümdeki 3 hoca 3 farklı gün
                    $day = $this->weekDays[($tIdx + ($year - 1) * 3) % 5];
                    $time = $this->timeSlots[($deptIdx + $tIdx + $year) % 8];

                    $classroom = Classroom::create([
                        'user_id'    => $teacher->id,
                        'name'       => "{$course} ({$year}. Sınıf)",
                        'department' => $dept,
                        'day'        => $day,
                        'time'       => $time,
                        'status'     => 'Aktif',
                        'attendance_taken' => true,
                    ]);

                    foreach ($studentGroups[$dept][$year] as $s) {
                        Student::create([
                            'classroom_id'         => $classroom->id,
                            'student_number'       => $s['student_number'],
                            'first_name'           => $s['first_name'],
                            'last_name'            => $s['last_name'],
                            'email'                => $s['email'],
                            'phone'                => $s['phone'],
                            'password'             => Hash::make($s['student_number']), // ilk şifre = numara
                            'must_change_password' => false, // demo modu — değiştirmek zorunda değil
                        ]);
                    }

                    $byTeacher[$teacher->id][] = $classroom->id;
                }
            }
        }
        return $byTeacher;
    }

    /**
     * Geriye dönük 10 haftalık yoklama üret.
     * Dağılım: 75% present, 12% absent, 8% late, 5% excused.
     * Her sınıf için "günü" haftada bir tarih → 10 oturum.
     */
    private function seedAttendances(array $classroomMap): void
    {
        $today = Carbon::today();
        $allClassroomIds = array_merge(...array_values($classroomMap));

        $rows = [];
        foreach ($allClassroomIds as $cid) {
            $classroom = Classroom::find($cid);
            $studentIds = Student::where('classroom_id', $cid)->pluck('id')->all();
            if (empty($studentIds)) continue;

            // Sınıfın gününe denk gelen son 10 dersini bul
            $dayMap = [
                'Pazartesi' => Carbon::MONDAY, 'Salı' => Carbon::TUESDAY, 'Çarşamba' => Carbon::WEDNESDAY,
                'Perşembe'  => Carbon::THURSDAY, 'Cuma' => Carbon::FRIDAY,
            ];
            $targetDayOfWeek = $dayMap[$classroom->day] ?? Carbon::MONDAY;

            // 10 hafta geriye, her hafta o güne denk gelen tarih
            $sessions = [];
            $cursor = $today->copy()->subDays(70);
            while (count($sessions) < 10 && $cursor->lte($today)) {
                if ($cursor->dayOfWeekIso === $targetDayOfWeek) {
                    $sessions[] = $cursor->copy()->toDateString();
                }
                $cursor->addDay();
            }

            foreach ($sessions as $date) {
                foreach ($studentIds as $sid) {
                    $rand = random_int(1, 100);
                    if ($rand <= 75)        $status = 'present';
                    elseif ($rand <= 87)    $status = 'absent';
                    elseif ($rand <= 95)    $status = 'late';
                    else                    $status = 'excused';

                    $rows[] = [
                        'student_id'   => $sid,
                        'classroom_id' => $cid,
                        'date'         => $date,
                        'status'       => $status,
                        'created_at'   => now(),
                        'updated_at'   => now(),
                    ];
                }
            }

            // Toplu insert: chunk by 500
            foreach (array_chunk($rows, 500) as $chunk) {
                Attendance::insert($chunk);
            }
            $rows = [];
        }
    }

    /**
     * Mazeret talepleri: bekleyen 1, onaylanmış 1, reddedilmiş 1 — her bölümün ilk hocasının
     * ilk sınıfı için. Toplam ~21 mazeret (yeterli demo zenginliği).
     */
    private function seedMazerets(array $classroomMap, array $teachersByDept): void
    {
        $reasons = [
            'Hastane raporu — grip',
            'Yüksek ateş, doktor raporu mevcut',
            'Diş tedavisi (acil)',
            'Ailevi kaza, polis raporu var',
            'Tez savunması başka şehirde',
        ];

        $statusFlow = ['pending', 'approved', 'rejected'];

        foreach ($this->departments as $dept) {
            $teacher = $teachersByDept[$dept][0];
            $cids = $classroomMap[$teacher->id];
            $cid = $cids[0];

            $studentRows = Student::where('classroom_id', $cid)->take(3)->get();
            foreach ($studentRows as $i => $sRow) {
                $status = $statusFlow[$i % 3];
                $date = Carbon::today()->subDays(random_int(3, 35))->toDateString();
                $m = MazeretRequest::create([
                    'student_id'         => $sRow->id,
                    'classroom_id'       => $cid,
                    'date'               => $date,
                    'reason'             => $reasons[array_rand($reasons)],
                    'file_path'          => 'mazeret/'.$cid.'/demo-'.uniqid().'.pdf',
                    'file_original_name' => 'rapor.pdf',
                    'file_mime'          => 'application/pdf',
                    'file_size'          => random_int(120_000, 1_400_000),
                    'status'             => $status,
                    'reviewer_id'        => $status !== 'pending' ? $teacher->id : null,
                    'reviewed_at'        => $status !== 'pending' ? now()->subDays(random_int(0, 5)) : null,
                    'review_note'        => $status === 'rejected' ? 'Rapor okunaklı değil, lütfen yeniden yükleyin.' : null,
                ]);

                // Onaylananlar için Attendance excused'e çevir
                if ($status === 'approved') {
                    Attendance::updateOrCreate(
                        ['student_id' => $sRow->id, 'classroom_id' => $cid, 'date' => $date],
                        ['status' => 'excused']
                    );
                }
            }
        }
    }

    /** Her sınıfın ilkine 1 duyuru bırak (bölüm başına 3 → 21 toplam). */
    private function seedAnnouncements(array $classroomMap): void
    {
        $samples = [
            ['Hafta Sonu Telafi Hatırlatması', 'Sevgili öğrenciler, bu hafta cuma günü kaçıran arkadaşlarımız için cumartesi 10:00\'da telafi dersi yapacağız. Konum sınıfımızda. Görüşmek üzere.'],
            ['Vize Sınavı Konu Listesi',       'Vize sınavı yaklaşıyor. Konu listesi ve örnek soru kümesini sınıf grubuna yükledim. Lütfen önce kendiniz çözün, sonra sorularla derste karşılaşın.'],
            ['Dönem Projesi Teslim Tarihi',    'Dönem projesi teslim tarihi 2 hafta uzatıldı. Yeni son tarih: 28 Mayıs 2026. Geç teslimde -10 puan uygulanacak.'],
            ['Sektör Konuğu Etkinliği',        'Önümüzdeki çarşamba sektörden bir konuğumuz olacak. Katılım önerilir, soru hazırlayın.'],
            ['Devamsızlık Hatırlatması',       'Bazı arkadaşlarımız sınıra yaklaştı. Mazeretiniz varsa öğrenci panelinden rapor yüklemeyi unutmayın.'],
        ];

        foreach ($classroomMap as $teacherId => $cids) {
            $cid = $cids[0]; // ilk sınıf
            [$title, $body] = $samples[array_rand($samples)];

            $recipients = Student::where('classroom_id', $cid)->pluck('id')->all();

            $a = Announcement::create([
                'classroom_id'     => $cid,
                'sender_id'        => $teacherId,
                'audience'         => 'all',
                'title'            => $title,
                'body'             => $body,
                'recipients_count' => count($recipients),
            ]);

            foreach ($recipients as $sid) {
                StudentInboxMessage::create([
                    'student_id'      => $sid,
                    'announcement_id' => $a->id,
                    'type'            => 'info',
                    'title'           => $title,
                    'body'            => $body,
                    'link'            => '/student/messages',
                ]);
            }
        }
    }

    /** Her bölümün 2. hocasının ilk sınıfına 1 telafi dersi → 7 telafi. */
    private function seedMakeupSessions(array $classroomMap, array $teachersByDept): void
    {
        foreach ($this->departments as $dept) {
            $teacher = $teachersByDept[$dept][1]; // 2. hoca
            $cid = $classroomMap[$teacher->id][0];
            $classroom = Classroom::find($cid);

            $futureDate = Carbon::today()->addDays(random_int(3, 14));
            MakeupSession::create([
                'classroom_id' => $cid,
                'created_by'   => $teacher->id,
                'date'         => $futureDate->toDateString(),
                'time'         => $classroom->time,
                'day'          => $this->dayName($futureDate),
                'note'         => 'Önceki haftadan kaçırılan dersin telafisi.',
            ]);
        }
    }

    /** İlgi alanları: 5 kategori × ~7 öğe + her öğrenciye rastgele 3-6 atama. */
    private function seedInterests(array $studentGroups): void
    {
        $catalog = [
            'tech'  => ['Yapay Zeka', 'Web Geliştirme', 'Mobil Uygulamalar', 'Siber Güvenlik', 'Veri Bilimi', 'Oyun Geliştirme', 'Robotik'],
            'sport' => ['Futbol', 'Basketbol', 'Voleybol', 'Yüzme', 'Koşu', 'Bisiklet', 'Fitness'],
            'media' => ['Sinema', 'Diziler', 'Belgesel', 'Anime', 'YouTube'],
            'music' => ['Pop', 'Rock', 'Klasik', 'Jazz', 'Türk Halk Müziği', 'Rap'],
            'hobby' => ['Fotoğrafçılık', 'Resim', 'Bahçecilik', 'Yemek Yapma', 'Seyahat', 'Kitap Okuma', 'Satranç'],
        ];

        // Catalog ekle
        $iconByCat = ['tech' => 'Cpu', 'sport' => 'Trophy', 'media' => 'Film', 'music' => 'Music', 'hobby' => 'Sparkles'];
        $interestIds = [];
        foreach ($catalog as $cat => $items) {
            foreach ($items as $name) {
                $i = Interest::create([
                    'name'     => $name,
                    'category' => $cat,
                    'icon'     => $iconByCat[$cat],
                ]);
                $interestIds[] = $i->id;
            }
        }

        // Her öğrenci numarası için seçim üret (sadece ilk Student row'una bağlı, diğerleri aynı paylaşır mantığı yok burada — backend index endpoint'i her satıra bakar)
        $allStudents = Student::all();
        // Performance için student_number bazında grupla, aynı number için tek seçim üret + tüm satırlara uygula
        $byNumber = $allStudents->groupBy('student_number');

        $rows = [];
        $now = now();
        foreach ($byNumber as $studentRows) {
            // %70 olasılıkla ilgi alanı seçer
            if (random_int(1, 100) > 70) continue;

            $count = random_int(3, 6);
            $picks = collect($interestIds)->shuffle()->take($count);

            foreach ($studentRows as $sRow) {
                foreach ($picks as $iid) {
                    $rows[] = [
                        'student_id'  => $sRow->id,
                        'interest_id' => $iid,
                        'level'       => random_int(2, 5),
                        'created_at'  => $now,
                        'updated_at'  => $now,
                    ];
                }
            }
        }

        // chunked insert
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('student_interests')->insert($chunk);
        }
    }

    /** Showcase rozetleri: %30 öğrenciye rastgele 1-3 rozet. */
    private function seedShowcaseBadges(): void
    {
        $badgeCodes = [
            'first_step', 'rookie_5', 'half_way_10', 'punctual', 'diligent_90',
            'flawless', 'consistency_4w', 'comeback', 'centurion',
        ];

        $allStudents = Student::all();
        $byNumber = $allStudents->groupBy('student_number');

        $rows = [];
        $now = now();
        foreach ($byNumber as $studentRows) {
            if (random_int(1, 100) > 30) continue;

            $count = random_int(1, 3);
            $picks = collect($badgeCodes)->shuffle()->take($count)->values();

            foreach ($studentRows as $sRow) {
                foreach ($picks as $i => $code) {
                    $rows[] = [
                        'student_id' => $sRow->id,
                        'badge_code' => $code,
                        'position'   => $i + 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('student_showcase_badges')->insert($chunk);
        }
    }

    /** Tüm hocalara 2026 resmi tatilleri (özet liste). */
    private function seedHolidays(array $teachersByDept): void
    {
        $holidays = [
            ['2026-01-01', 'Yılbaşı', 'public'],
            ['2026-04-23', 'Ulusal Egemenlik ve Çocuk Bayramı', 'public'],
            ['2026-05-01', 'Emek ve Dayanışma Günü', 'public'],
            ['2026-05-19', 'Atatürk\'ü Anma, Gençlik ve Spor Bayramı', 'public'],
            ['2026-07-15', 'Demokrasi ve Milli Birlik Günü', 'public'],
            ['2026-08-30', 'Zafer Bayramı', 'public'],
            ['2026-10-29', 'Cumhuriyet Bayramı', 'public'],
        ];

        foreach ($teachersByDept as $teachers) {
            foreach ($teachers as $t) {
                foreach ($holidays as [$date, $name, $type]) {
                    Holiday::create([
                        'user_id' => $t->id,
                        'date'    => $date,
                        'name'    => $name,
                        'type'    => $type,
                    ]);
                }
            }
        }
    }

    /** Her hocaya 2 örnek bildirim + her öğrenciye 1 sample bildirim. */
    private function seedInbox(array $teachersByDept): void
    {
        // Hoca tarafı
        foreach ($teachersByDept as $teachers) {
            foreach ($teachers as $t) {
                InboxMessage::create([
                    'user_id' => $t->id,
                    'type'    => 'success',
                    'title'   => 'Hoş Geldiniz!',
                    'body'    => 'SmartRollCall demo ortamına hoş geldiniz. Tüm sayfaları gerçek veri ile inceleyebilirsiniz.',
                    'link'    => '/dashboard',
                ]);
                InboxMessage::create([
                    'user_id' => $t->id,
                    'type'    => 'warning',
                    'title'   => 'Riskli Öğrenci Sayısı Artıyor',
                    'body'    => 'Sınıflarınızda devamsızlık sınırına yaklaşan birkaç öğrenci var. Risk Raporu sayfasından detayları görebilirsiniz.',
                    'link'    => '/dashboard/risk-report',
                ]);
            }
        }
    }

    private function dayName(Carbon $d): string
    {
        $map = [1 => 'Pazartesi', 2 => 'Salı', 3 => 'Çarşamba', 4 => 'Perşembe', 5 => 'Cuma', 6 => 'Cumartesi', 7 => 'Pazar'];
        return $map[$d->dayOfWeekIso] ?? 'Pazartesi';
    }

    private function ascii(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $tr = ['ı','ğ','ü','ş','ö','ç','İ','Ğ','Ü','Ş','Ö','Ç'];
        $en = ['i','g','u','s','o','c','i','g','u','s','o','c'];
        $s = str_replace($tr, $en, $s);
        return preg_replace('/[^a-z0-9]/', '', $s) ?? '';
    }
}
