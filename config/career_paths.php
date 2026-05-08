<?php

/**
 * Bölüme özel kariyer yolları — her bölüm için 5 seviye unvan + XP eşiği.
 * Üniversite akademik tonu: gerçek meslek hiyerarşisi yansıtılır,
 * "oyuncak" rütbeler değil.
 *
 * Anahtar: bölümün config/departments.php'deki tam adı.
 * Bilinmeyen bölüm için 'default' fallback'i kullanılır.
 */

return [
    // Toplam XP eşikleri (5 seviye için 4 sınır + üst için sınırsız)
    'thresholds' => [
        1 => 0,      // Lvl 1 başlangıç
        2 => 100,    // Lvl 2'ye ulaşmak için
        3 => 300,
        4 => 600,
        5 => 1000,
    ],

    // XP katsayıları (her attendance kayıtı için)
    'xp_rules' => [
        'present' => 10,
        'late'    => 5,
        'excused' => 3,
        'absent'  => 0,
        'streak_4weeks_bonus' => 50, // 4 hafta üst üste kusursuz katılım
    ],

    'paths' => [
        'Bilgisayar Teknolojileri Bölümü' => [
            'theme'  => 'Yazılım Kariyeri',
            'icon'   => 'Code2',
            'color'  => 'violet',
            'titles' => [
                1 => 'Stajyer Geliştirici',
                2 => 'Junior Geliştirici',
                3 => 'Yazılım Geliştirici',
                4 => 'Senior Yazılımcı',
                5 => 'Tech Lead',
            ],
        ],
        'Elektrik ve Enerji Bölümü' => [
            'theme'  => 'Elektrik & Enerji Kariyeri',
            'icon'   => 'Zap',
            'color'  => 'amber',
            'titles' => [
                1 => 'Saha Stajyeri',
                2 => 'Elektrik Teknisyeni',
                3 => 'Enerji Mühendisi',
                4 => 'Sistem Mühendisi',
                5 => 'Baş Mühendis',
            ],
        ],
        'Elektronik ve Otomasyon Bölümü' => [
            'theme'  => 'Otomasyon Kariyeri',
            'icon'   => 'Cpu',
            'color'  => 'sky',
            'titles' => [
                1 => 'Devre Çırağı',
                2 => 'Otomasyon Teknisyeni',
                3 => 'PLC Programcısı',
                4 => 'Otomasyon Mühendisi',
                5 => 'Endüstriyel Sistem Uzmanı',
            ],
        ],
        'Gıda İşleme Bölümü' => [
            'theme'  => 'Gıda Üretim Kariyeri',
            'icon'   => 'ChefHat',
            'color'  => 'rose',
            'titles' => [
                1 => 'Üretim Asistanı',
                2 => 'Üretim Operatörü',
                3 => 'Kalite Kontrol Uzmanı',
                4 => 'Gıda Teknoloğu',
                5 => 'Üretim Müdürü',
            ],
        ],
        'İnşaat Bölümü' => [
            'theme'  => 'İnşaat Kariyeri',
            'icon'   => 'Building2',
            'color'  => 'orange',
            'titles' => [
                1 => 'Şantiye Stajyeri',
                2 => 'İnşaat Teknikeri',
                3 => 'Saha Mühendisi',
                4 => 'Şantiye Şefi',
                5 => 'Proje Yöneticisi',
            ],
        ],
        'Makine ve Metal Teknolojileri Bölümü' => [
            'theme'  => 'Makine Üretim Kariyeri',
            'icon'   => 'Cog',
            'color'  => 'slate',
            'titles' => [
                1 => 'Atölye Çırağı',
                2 => 'CNC Operatörü',
                3 => 'Üretim Teknikeri',
                4 => 'Makine Mühendisi',
                5 => 'Üretim Müdürü',
            ],
        ],
        'Tekstil Giyim, Ayakkabı ve Deri Bölümü' => [
            'theme'  => 'Moda & Tasarım Kariyeri',
            'icon'   => 'Scissors',
            'color'  => 'fuchsia',
            'titles' => [
                1 => 'Atölye Stajyeri',
                2 => 'Tekstil Teknisyeni',
                3 => 'Tasarım Uzmanı',
                4 => 'Üretim Mühendisi',
                5 => 'Marka Müdürü',
            ],
        ],
    ],

    // Bilinmeyen bölüm için (örn. eski kayıt) varsayılan
    'default' => [
        'theme'  => 'Akademik Yolculuk',
        'icon'   => 'GraduationCap',
        'color'  => 'primary',
        'titles' => [
            1 => 'Yeni Öğrenci',
            2 => '2. Sınıf Öğrencisi',
            3 => 'Mezuniyet Adayı',
            4 => 'Onur Öğrencisi',
            5 => 'Üstün Başarılı Mezun',
        ],
    ],
];
