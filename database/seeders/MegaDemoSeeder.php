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
use Illuminate\Support\Facades\Storage;

/**
 * SmartRollCall — MEGA DEMO SEED (sunum için "gerçek dershane" görünümü)
 *
 * Ölçek tek yerden ayarlanabilir (aşağıdaki sabitler). Sunum öncesi
 * Render free tier'da hız ölçümüne göre rakamlar büyütülüp küçültülür.
 *
 *   php artisan db:seed --class=MegaDemoSeeder
 *
 * Mevcut DemoSeeder dosyasına dokunulmaz; bu ayrı bir seeder'dır.
 * Takvim çakışma kuralı (aynı bölüm + gün + saatte tek ders) korunur:
 * gün(5) × saat(8) = 40 slot/bölüm, 3 hoca × CLASSES_PER_TEACHER sınıf
 * bu kapasiteyi aşamaz (CLASSES_PER_TEACHER ≤ 13).
 */
class MegaDemoSeeder extends Seeder
{
    // ---- ÖLÇEK AYARLARI (tek noktadan) -------------------------------
    // Render free tier (512MB RAM, paylaşımlı CPU) için optimize edildi:
    // tek HTTP isteğinde (~maks 60-120s) bitecek + grafikler akıcı kalacak ölçek.
    /** Her hocaya kaç sınıf. (3 hoca × bu sayı ≤ 40 olmalı — slot kapasitesi) */
    private const CLASSES_PER_TEACHER = 5;
    /** Sınıf başına öğrenci alt/üst sınırı (her sınıf bu aralıkta rastgele). */
    private const STUDENTS_MIN = 25;
    private const STUDENTS_MAX = 38;
    /** Geriye dönük kaç haftalık yoklama üretilsin. */
    private const ATTENDANCE_WEEKS = 6;
    /** Toplu insert chunk boyutu (free tier RAM dostu). */
    private const CHUNK = 1000;
    // ------------------------------------------------------------------

    private array $departments = [
        'Bilgisayar Teknolojileri Bölümü',
        'Elektrik ve Enerji Bölümü',
        'Elektronik ve Otomasyon Bölümü',
        'Gıda İşleme Bölümü',
        'İnşaat Bölümü',
        'Makine ve Metal Teknolojileri Bölümü',
        'Tekstil Giyim, Ayakkabı ve Deri Bölümü',
    ];

    /** Bölüm bazlı ders havuzu — sınıf adlandırmada döngüsel kullanılır. */
    private array $coursesByDept = [
        'Bilgisayar Teknolojileri Bölümü'        => ['Web Programlama', 'Veritabanı Yönetimi', 'Yapay Zeka Temelleri', 'Mobil Programlama', 'Ağ Sistemleri', 'Siber Güvenlik', 'Nesne Yön. Programlama', 'İşletim Sistemleri'],
        'Elektrik ve Enerji Bölümü'              => ['Elektrik Devre Analizi', 'Elektrik Makineleri', 'Yenilenebilir Enerji', 'Güç Elektroniği', 'Aydınlatma Tekniği', 'Elektrik Tesisatı', 'Ölçme Tekniği', 'Enerji Dağıtımı'],
        'Elektronik ve Otomasyon Bölümü'         => ['Sayısal Elektronik', 'PLC ile Otomasyon', 'Robotik Sistemler', 'Mikrodenetleyiciler', 'Analog Elektronik', 'Sensör Teknolojileri', 'Endüstriyel Otomasyon', 'Gömülü Sistemler'],
        'Gıda İşleme Bölümü'                     => ['Gıda Mikrobiyolojisi', 'Gıda Güvenliği ve HACCP', 'Süt Ürünleri Teknolojisi', 'Et Ürünleri Teknolojisi', 'Gıda Kimyası', 'Tahıl İşleme', 'Gıda Katkı Maddeleri', 'Kalite Kontrol'],
        'İnşaat Bölümü'                          => ['Statik', 'Beton Teknolojisi', 'Yapı Bilgisi', 'Yapı Statiği', 'Zemin Mekaniği', 'Mesleki Çizim', 'Yapı Malzemeleri', 'Şantiye Tekniği'],
        'Makine ve Metal Teknolojileri Bölümü'   => ['Teknik Resim', 'CNC Tezgah Programlama', 'Bilgisayar Destekli Tasarım', 'Malzeme Bilgisi', 'Talaşlı Üretim', 'Kaynak Teknolojisi', 'Pnömatik-Hidrolik', 'Ölçme ve Kontrol'],
        'Tekstil Giyim, Ayakkabı ve Deri Bölümü' => ['Tekstil Lifleri', 'Dokuma Teknolojisi', 'Konfeksiyon Üretimi', 'Örme Teknolojisi', 'Tekstil Boyacılığı', 'Model Tasarımı', 'Kalıp Hazırlama', 'Deri Teknolojisi'],
    ];

    /** Her bölüm için 3 hoca (ad, soyad, username). Şifre: 123456. */
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

    private array $studentFirstNames = [
        'Ahmet', 'Mehmet', 'Mustafa', 'Ali', 'Hüseyin', 'İbrahim', 'Hasan', 'Murat',
        'Emre', 'Burak', 'Cem', 'Onur', 'Eren', 'Berk', 'Kerem', 'Tolga', 'Cenk', 'Ozan',
        'Furkan', 'Yusuf', 'Enes', 'Kaan', 'Arda', 'Deniz', 'Barış', 'Serkan', 'Volkan',
        'Zeynep', 'Ayşe', 'Fatma', 'Elif', 'Selin', 'Esra', 'Aylin', 'Pınar', 'Defne',
        'Yasemin', 'Pelin', 'Merve', 'Beyza', 'Ceren', 'Damla', 'Ece', 'Gizem', 'Hilal',
        'Buse', 'İrem', 'Sıla', 'Nehir', 'Melis', 'Dilara', 'Sude', 'Ebru', 'Tuğçe',
    ];

    private array $studentLastNames = [
        'Yılmaz', 'Kaya', 'Demir', 'Şahin', 'Çelik', 'Aydın', 'Öztürk', 'Arslan', 'Doğan',
        'Polat', 'Erdem', 'Yıldız', 'Koç', 'Aksoy', 'Türkmen', 'Bozkurt', 'Sarı', 'Acar',
        'Tunç', 'Avcı', 'Yalçın', 'Aktaş', 'Korkmaz', 'Güneş', 'Çetin', 'Aslan', 'Tekin',
        'Kara', 'Bulut', 'Erdoğan', 'Toprak', 'Güler', 'Yavuz', 'Kurt', 'Yıldırım',
        'Özdemir', 'Şen', 'Kılıç', 'Aydoğan', 'Çakır', 'Şimşek', 'Köse', 'Taş', 'Duman',
    ];

    private array $weekDays = ['Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma'];
    private array $timeSlots = ['08:00', '09:00', '10:00', '11:00', '13:00', '14:00', '15:00', '16:00'];

    /**
     * Tüm öğrencilere ortak şifre: "123456". Hash TEK SEFER hesaplanır ve
     * her öğrenciye kopyalanır — 5000+ ayrı bcrypt yerine 1 bcrypt (free tier'da
     * en büyük hızlanma). Öğrenci girişi: numara + "123456".
     */
    private string $studentPasswordHash = '';

    public function run(): void
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        // Öğrenci şifre hash'ini TEK SEFER hesapla (hepsi "123456").
        $this->studentPasswordHash = Hash::make('123456');

        $this->command->info('SmartRollCall MEGA demo verileri yükleniyor...');
        $this->command->info(sprintf(
            '  Ölçek: %d hoca × %d sınıf = %d sınıf | %d-%d öğrenci/sınıf | %d hafta yoklama',
            count($this->departments) * 3,
            self::CLASSES_PER_TEACHER,
            count($this->departments) * 3 * self::CLASSES_PER_TEACHER,
            self::STUDENTS_MIN, self::STUDENTS_MAX, self::ATTENDANCE_WEEKS
        ));

        $t0 = microtime(true);

        $this->truncate();

        $teachers = $this->createTeachers();
        $totalTeachers = array_sum(array_map(fn ($l) => count($l), $teachers));
        $this->command->info("  ✓ {$totalTeachers} hoca oluşturuldu");

        // Sınıf + öğrenci + (sınıf başına benzersiz takvim slotu)
        [$classroomMap, $totalClasses, $totalStudents] = $this->createClassroomsAndStudents($teachers);
        $this->command->info("  ✓ {$totalClasses} sınıf oluşturuldu (takvim çakışmasız dağıtıldı)");
        $this->command->info("  ✓ {$totalStudents} öğrenci kaydı oluşturuldu");

        $attCount = $this->seedAttendances($classroomMap);
        $this->command->info("  ✓ {$attCount} yoklama satırı üretildi (".self::ATTENDANCE_WEEKS." haftalık geçmiş)");

        $this->seedMazerets($classroomMap, $teachers);
        $this->command->info('  ✓ Mazeret talepleri eklendi');

        $this->seedAnnouncements($classroomMap);
        $this->command->info('  ✓ Sınıf duyuruları eklendi');

        $this->seedMakeupSessions($classroomMap, $teachers);
        $this->command->info('  ✓ Telafi dersleri planlandı');

        $this->seedInterests();
        $this->command->info('  ✓ İlgi alanları seed edildi');

        $this->seedShowcaseBadges();
        $this->command->info('  ✓ Showcase rozetleri atandı');

        $this->seedHolidays($teachers);
        $this->command->info('  ✓ Resmi tatiller eklendi');

        $this->seedInbox($teachers);
        $this->command->info('  ✓ Bildirimler eklendi');

        $elapsed = round(microtime(true) - $t0, 1);
        $this->command->info('');
        $this->command->info('============================================================');
        $this->command->info("  MEGA DEMO HAZIR! ({$elapsed}s)");
        $this->command->info('  Hoca:     demo.bil1 / 123456   (21 hoca, hepsi 123456)');
        $this->command->info('  Öğrenci:  <öğrenci numarası> / 123456   (tüm öğrenciler 123456)');
        $this->command->info('============================================================');
    }

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

        if ($driver === 'pgsql') {
            $existing = array_filter(
                $tables,
                fn ($t) => \Illuminate\Support\Facades\Schema::hasTable($t)
            );
            if (! empty($existing)) {
                $quoted = implode(', ', array_map(fn ($t) => "\"$t\"", $existing));
                DB::statement("TRUNCATE TABLE $quoted RESTART IDENTITY CASCADE");
            }
        } else {
            foreach ($tables as $t) {
                if (\Illuminate\Support\Facades\Schema::hasTable($t)) {
                    DB::table($t)->truncate();
                }
            }
        }

        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        } elseif ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }

    /** @return array<string, User[]> */
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
     * Her hoca CLASSES_PER_TEACHER sınıf alır. Her sınıf benzersiz bir öğrenci
     * grubuna sahiptir (28-40 öğrenci). Takvim slotları bölüm içinde çakışmasız
     * dağıtılır (gün+saat). Öğrenci numaraları global olarak benzersizdir.
     *
     * @return array{0: array<int, int[]>, 1: int, 2: int}
     *               [teacherId => classroomIds[]], toplamSınıf, toplamÖğrenci
     */
    private function createClassroomsAndStudents(array $teachersByDept): array
    {
        $byTeacher = [];
        $totalClasses = 0;
        $totalStudents = 0;

        foreach ($this->departments as $deptIdx => $dept) {
            $teachers = $teachersByDept[$dept];
            $courses  = $this->coursesByDept[$dept];

            // Bölüm için tüm (gün, saat) slotlarını sırala — çakışmasız dağıtım
            $slots = [];
            foreach ($this->weekDays as $d) {
                foreach ($this->timeSlots as $tm) {
                    $slots[] = [$d, $tm];
                }
            }
            // Bölüme özgü deterministik karıştırma (her bölüm farklı düzen) — rand kullanmadan
            $rot = $deptIdx % count($slots);
            $slots = array_merge(array_slice($slots, $rot), array_slice($slots, 0, $rot));
            $slotIdx = 0;

            // Bölüm içi öğrenci numarası sayacı: 24 DD G NNN  → benzersiz
            // DD = bölüm (01..07), tek alanda toplanır
            $studentSeq = 0;

            foreach ($teachers as $tIdx => $teacher) {
                $byTeacher[$teacher->id] = [];

                for ($c = 0; $c < self::CLASSES_PER_TEACHER; $c++) {
                    $course = $courses[$c % count($courses)];
                    $yearLabel = ($c % 2) + 1; // görsel "1./2. Sınıf" etiketi

                    [$day, $time] = $slots[$slotIdx % count($slots)];
                    $slotIdx++;

                    $classroom = Classroom::create([
                        'user_id'    => $teacher->id,
                        'name'       => "{$course} ({$yearLabel}. Sınıf - {$this->groupSuffix($c)})",
                        'department' => $dept,
                        'day'        => $day,
                        'time'       => $time,
                        'status'     => 'Aktif',
                        'attendance_taken' => true,
                    ]);

                    // Bu sınıf için öğrenci üret
                    $studentCount = random_int(self::STUDENTS_MIN, self::STUDENTS_MAX);
                    $rows = [];
                    $now = now();
                    for ($i = 1; $i <= $studentCount; $i++) {
                        $studentSeq++;
                        // Numara: 24 + bölüm(2) + sıra(4) → ör. 2401_0001
                        $num = sprintf('24%02d%04d', $deptIdx + 1, $studentSeq);
                        $first = $this->studentFirstNames[array_rand($this->studentFirstNames)];
                        $last = $this->studentLastNames[array_rand($this->studentLastNames)];
                        $rows[] = [
                            'classroom_id'         => $classroom->id,
                            'student_number'       => $num,
                            'first_name'           => $first,
                            'last_name'            => $last,
                            'email'                => strtolower($this->ascii($first).'.'.$this->ascii($last)).$num.'@smartroll.demo',
                            'phone'                => '0533'.random_int(1000000, 9999999),
                            'password'             => $this->studentPasswordHash,
                            'must_change_password' => false,
                            'created_at'           => $now,
                            'updated_at'           => $now,
                        ];
                    }
                    foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                        Student::insert($chunk);
                    }
                    $totalStudents += $studentCount;

                    $byTeacher[$teacher->id][] = $classroom->id;
                    $totalClasses++;
                }
            }
        }

        return [$byTeacher, $totalClasses, $totalStudents];
    }

    private function groupSuffix(int $c): string
    {
        $letters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M'];
        return $letters[$c % count($letters)] . ' Grubu';
    }

    /**
     * ATTENDANCE_WEEKS haftalık yoklama. Dağılım: 75% present, 12% absent,
     * 8% late, 5% excused. Sınıf bazında işlenir, chunk insert.
     *
     * @return int üretilen toplam satır
     */
    private function seedAttendances(array $classroomMap): int
    {
        $today = Carbon::today();
        $allClassroomIds = array_merge(...array_values($classroomMap));
        $dayMap = [
            'Pazartesi' => Carbon::MONDAY, 'Salı' => Carbon::TUESDAY, 'Çarşamba' => Carbon::WEDNESDAY,
            'Perşembe'  => Carbon::THURSDAY, 'Cuma' => Carbon::FRIDAY,
        ];
        $weeks = self::ATTENDANCE_WEEKS;
        $total = 0;

        foreach ($allClassroomIds as $cid) {
            $classroom = Classroom::find($cid);
            $studentIds = Student::where('classroom_id', $cid)->pluck('id')->all();
            if (empty($studentIds)) continue;

            $targetDow = $dayMap[$classroom->day] ?? Carbon::MONDAY;

            // Son N hafta, sınıfın gününe denk gelen tarihler
            $sessions = [];
            $cursor = $today->copy()->subDays($weeks * 7);
            while (count($sessions) < $weeks && $cursor->lte($today)) {
                if ($cursor->dayOfWeekIso === $targetDow) {
                    $sessions[] = $cursor->copy()->toDateString();
                }
                $cursor->addDay();
            }

            $now = now();
            $rows = [];
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
                        'created_at'   => $now,
                        'updated_at'   => $now,
                    ];

                    if (count($rows) >= self::CHUNK) {
                        Attendance::insert($rows);
                        $total += count($rows);
                        $rows = [];
                    }
                }
            }
            if (! empty($rows)) {
                Attendance::insert($rows);
                $total += count($rows);
                $rows = [];
            }
        }
        return $total;
    }

    /**
     * Her bölümün ilk hocasının ilk sınıfında bekleyen/onaylı/reddedilmiş mazeret.
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
        $demoPdfBytes = $this->buildDemoPdf();

        foreach ($this->departments as $dept) {
            $teacher = $teachersByDept[$dept][0];
            $cid = $classroomMap[$teacher->id][0];

            $studentRows = Student::where('classroom_id', $cid)->take(3)->get();
            foreach ($studentRows as $i => $sRow) {
                $status = $statusFlow[$i % 3];
                $date = Carbon::today()->subDays(random_int(3, 35))->toDateString();
                $filename = 'demo-'.uniqid().'.pdf';
                $filePath = 'mazeret/'.$cid.'/'.$filename;

                try {
                    Storage::disk('local')->put($filePath, $demoPdfBytes);
                } catch (\Throwable $e) {
                    error_log("MegaDemoSeeder: PDF yazilamadi {$filePath}: " . $e->getMessage());
                }

                MazeretRequest::create([
                    'student_id'         => $sRow->id,
                    'classroom_id'       => $cid,
                    'date'               => $date,
                    'reason'             => $reasons[array_rand($reasons)],
                    'file_path'          => $filePath,
                    'file_original_name' => 'saglik-raporu.pdf',
                    'file_mime'          => 'application/pdf',
                    'file_size'          => strlen($demoPdfBytes),
                    'status'             => $status,
                    'reviewer_id'        => $status !== 'pending' ? $teacher->id : null,
                    'reviewed_at'        => $status !== 'pending' ? now()->subDays(random_int(0, 5)) : null,
                    'review_note'        => $status === 'rejected' ? 'Rapor okunaklı değil, lütfen yeniden yükleyin.' : null,
                ]);

                if ($status === 'approved') {
                    Attendance::updateOrCreate(
                        ['student_id' => $sRow->id, 'classroom_id' => $cid, 'date' => $date],
                        ['status' => 'excused']
                    );
                }
            }
        }
    }

    private function buildDemoPdf(): string
    {
        $content = "BT /F1 18 Tf 70 760 Td (SAGLIK RAPORU - DEMO) Tj ET\n"
                 . "BT /F1 11 Tf 70 720 Td (SmartRollCall - Demo Saglik Raporu) Tj ET\n"
                 . "BT /F1 11 Tf 70 700 Td (Bu dosya demo amacli olusturulmustur.) Tj ET\n"
                 . "BT /F1 10 Tf 70 670 Td (Hasta: Ornek Ogrenci) Tj ET\n"
                 . "BT /F1 10 Tf 70 655 Td (Tarih: " . date('d.m.Y') . ") Tj ET\n"
                 . "BT /F1 10 Tf 70 640 Td (Tani: Akut Ust Solunum Yolu Enfeksiyonu) Tj ET\n"
                 . "BT /F1 10 Tf 70 620 Td (Onerilen Istirahat: 3 gun) Tj ET\n"
                 . "BT /F1 9 Tf 70 580 Td (Bu rapor SmartRollCall demo verisidir.) Tj ET\n"
                 . "BT /F1 9 Tf 70 565 Td (Gercek tibbi belge degildir.) Tj ET\n";

        $contentLen = strlen($content);
        $pdf  = "%PDF-1.4\n";
        $offsets = [];

        $offsets[1] = strlen($pdf);
        $pdf .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $offsets[2] = strlen($pdf);
        $pdf .= "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
        $offsets[3] = strlen($pdf);
        $pdf .= "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] "
              . "/Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>\nendobj\n";
        $offsets[4] = strlen($pdf);
        $pdf .= "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";
        $offsets[5] = strlen($pdf);
        $pdf .= "5 0 obj\n<< /Length {$contentLen} >>\nstream\n{$content}endstream\nendobj\n";

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 6\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= 5; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";

        return $pdf;
    }

    /** Her hocanın ilk sınıfına 1 duyuru. */
    private function seedAnnouncements(array $classroomMap): void
    {
        $samples = [
            ['Hafta Sonu Telafi Hatırlatması', 'Sevgili öğrenciler, bu hafta cuma günü kaçıran arkadaşlarımız için cumartesi 10:00\'da telafi dersi yapacağız. Konum sınıfımızda. Görüşmek üzere.'],
            ['Vize Sınavı Konu Listesi',       'Vize sınavı yaklaşıyor. Konu listesi ve örnek soru kümesini sınıf grubuna yükledim. Lütfen önce kendiniz çözün.'],
            ['Dönem Projesi Teslim Tarihi',    'Dönem projesi teslim tarihi 2 hafta uzatıldı. Yeni son tarih: 28 Mayıs 2026. Geç teslimde -10 puan uygulanacak.'],
            ['Sektör Konuğu Etkinliği',        'Önümüzdeki çarşamba sektörden bir konuğumuz olacak. Katılım önerilir, soru hazırlayın.'],
            ['Devamsızlık Hatırlatması',       'Bazı arkadaşlarımız sınıra yaklaştı. Mazeretiniz varsa öğrenci panelinden rapor yüklemeyi unutmayın.'],
        ];

        foreach ($classroomMap as $teacherId => $cids) {
            $cid = $cids[0];
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

            $now = now();
            $rows = [];
            foreach ($recipients as $sid) {
                $rows[] = [
                    'student_id'      => $sid,
                    'announcement_id' => $a->id,
                    'type'            => 'info',
                    'title'           => $title,
                    'body'            => $body,
                    'link'            => '/student/messages',
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ];
            }
            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                StudentInboxMessage::insert($chunk);
            }
        }
    }

    /** Her bölümün 2. hocasının ilk sınıfına 1 telafi dersi. */
    private function seedMakeupSessions(array $classroomMap, array $teachersByDept): void
    {
        foreach ($this->departments as $dept) {
            $teacher = $teachersByDept[$dept][1];
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

    /** İlgi alanları catalog + öğrencilere rastgele atama (~%60). */
    private function seedInterests(): void
    {
        $catalog = [
            'tech'  => ['Yapay Zeka', 'Web Geliştirme', 'Mobil Uygulamalar', 'Siber Güvenlik', 'Veri Bilimi', 'Oyun Geliştirme', 'Robotik'],
            'sport' => ['Futbol', 'Basketbol', 'Voleybol', 'Yüzme', 'Koşu', 'Bisiklet', 'Fitness'],
            'media' => ['Sinema', 'Diziler', 'Belgesel', 'Anime', 'YouTube'],
            'music' => ['Pop', 'Rock', 'Klasik', 'Jazz', 'Türk Halk Müziği', 'Rap'],
            'hobby' => ['Fotoğrafçılık', 'Resim', 'Bahçecilik', 'Yemek Yapma', 'Seyahat', 'Kitap Okuma', 'Satranç'],
        ];
        $iconByCat = ['tech' => 'Cpu', 'sport' => 'Trophy', 'media' => 'Film', 'music' => 'Music', 'hobby' => 'Sparkles'];

        $interestIds = [];
        foreach ($catalog as $cat => $items) {
            foreach ($items as $name) {
                $i = Interest::create(['name' => $name, 'category' => $cat, 'icon' => $iconByCat[$cat]]);
                $interestIds[] = $i->id;
            }
        }

        // Öğrencileri ID bazında akarak işle (memory dostu)
        $now = now();
        $rows = [];
        Student::select('id')->chunkById(2000, function ($students) use (&$rows, $interestIds, $now) {
            foreach ($students as $s) {
                if (random_int(1, 100) > 60) continue;
                $count = random_int(3, 6);
                $picks = collect($interestIds)->shuffle()->take($count);
                foreach ($picks as $iid) {
                    $rows[] = [
                        'student_id'  => $s->id,
                        'interest_id' => $iid,
                        'level'       => random_int(2, 5),
                        'created_at'  => $now,
                        'updated_at'  => $now,
                    ];
                }
                if (count($rows) >= self::CHUNK) {
                    DB::table('student_interests')->insert($rows);
                    $rows = [];
                }
            }
        });
        if (! empty($rows)) {
            DB::table('student_interests')->insert($rows);
        }
    }

    /** Showcase rozetleri: ~%30 öğrenciye 1-3 rozet. */
    private function seedShowcaseBadges(): void
    {
        $badgeCodes = [
            'first_step', 'rookie_5', 'half_way_10', 'punctual', 'diligent_90',
            'flawless', 'consistency_4w', 'comeback', 'centurion',
        ];

        $now = now();
        $rows = [];
        Student::select('id')->chunkById(2000, function ($students) use (&$rows, $badgeCodes, $now) {
            foreach ($students as $s) {
                if (random_int(1, 100) > 30) continue;
                $count = random_int(1, 3);
                $picks = collect($badgeCodes)->shuffle()->take($count)->values();
                foreach ($picks as $i => $code) {
                    $rows[] = [
                        'student_id' => $s->id,
                        'badge_code' => $code,
                        'position'   => $i + 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                if (count($rows) >= self::CHUNK) {
                    DB::table('student_showcase_badges')->insert($rows);
                    $rows = [];
                }
            }
        });
        if (! empty($rows)) {
            DB::table('student_showcase_badges')->insert($rows);
        }
    }

    /** Tüm hocalara 2026 resmi tatilleri. */
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

        $now = now();
        $rows = [];
        foreach ($teachersByDept as $teachers) {
            foreach ($teachers as $t) {
                foreach ($holidays as [$date, $name, $type]) {
                    $rows[] = [
                        'user_id'    => $t->id,
                        'date'       => $date,
                        'name'       => $name,
                        'type'       => $type,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('holidays')->insert($chunk);
        }
    }

    /** Her hocaya 2 örnek bildirim. */
    private function seedInbox(array $teachersByDept): void
    {
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
