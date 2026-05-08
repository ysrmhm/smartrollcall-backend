<?php

namespace App\Console\Commands;

use App\Models\Interest;
use Illuminate\Console\Command;

class SeedInterestsCommand extends Command
{
    protected $signature = 'seed:interests';
    protected $description = 'İlgi alanı kataloğunu (5 kategori, ~35 öğe) doldurur. Idempotent.';

    private array $catalog = [
        // Tech
        ['name' => 'Java',          'category' => 'tech',   'icon' => 'Code'],
        ['name' => 'Python',        'category' => 'tech',   'icon' => 'Code'],
        ['name' => 'JavaScript',    'category' => 'tech',   'icon' => 'Code'],
        ['name' => 'React',         'category' => 'tech',   'icon' => 'Atom'],
        ['name' => 'C#',            'category' => 'tech',   'icon' => 'Code'],
        ['name' => 'Yapay Zeka',    'category' => 'tech',   'icon' => 'Brain'],
        ['name' => 'Siber Güvenlik','category' => 'tech',   'icon' => 'Shield'],
        ['name' => 'Veri Tabanı',   'category' => 'tech',   'icon' => 'Database'],
        ['name' => 'Mobil Uygulama','category' => 'tech',   'icon' => 'Smartphone'],
        ['name' => 'Oyun Geliştirme','category' => 'tech',  'icon' => 'Gamepad2'],
        ['name' => 'Web Tasarımı',  'category' => 'tech',   'icon' => 'Layout'],

        // Sport
        ['name' => 'Futbol',        'category' => 'sport',  'icon' => 'Goal'],
        ['name' => 'Basketbol',     'category' => 'sport',  'icon' => 'CircleDot'],
        ['name' => 'Voleybol',      'category' => 'sport',  'icon' => 'Volleyball'],
        ['name' => 'Yüzme',         'category' => 'sport',  'icon' => 'Waves'],
        ['name' => 'Koşu',          'category' => 'sport',  'icon' => 'Footprints'],
        ['name' => 'Bisiklet',      'category' => 'sport',  'icon' => 'Bike'],
        ['name' => 'Fitness',       'category' => 'sport',  'icon' => 'Dumbbell'],

        // Media
        ['name' => 'Film',          'category' => 'media',  'icon' => 'Film'],
        ['name' => 'Dizi',          'category' => 'media',  'icon' => 'Tv'],
        ['name' => 'Anime',         'category' => 'media',  'icon' => 'Sparkles'],
        ['name' => 'Kitap',         'category' => 'media',  'icon' => 'BookOpen'],
        ['name' => 'Belgesel',      'category' => 'media',  'icon' => 'Camera'],
        ['name' => 'Çizgi Roman',   'category' => 'media',  'icon' => 'BookMarked'],

        // Music
        ['name' => 'Pop',           'category' => 'music',  'icon' => 'Music'],
        ['name' => 'Rock',          'category' => 'music',  'icon' => 'Music2'],
        ['name' => 'Rap / Hip-Hop', 'category' => 'music',  'icon' => 'Mic'],
        ['name' => 'Türk Halk Müziği','category' => 'music','icon' => 'Music3'],
        ['name' => 'Klasik',        'category' => 'music',  'icon' => 'Music4'],
        ['name' => 'Elektronik',    'category' => 'music',  'icon' => 'Disc'],

        // Hobby
        ['name' => 'Fotoğrafçılık', 'category' => 'hobby',  'icon' => 'Camera'],
        ['name' => 'Resim',         'category' => 'hobby',  'icon' => 'Palette'],
        ['name' => 'Yemek Yapma',   'category' => 'hobby',  'icon' => 'ChefHat'],
        ['name' => 'Seyahat',       'category' => 'hobby',  'icon' => 'Plane'],
        ['name' => 'Satranç',       'category' => 'hobby',  'icon' => 'Crown'],
        ['name' => 'Bahçecilik',    'category' => 'hobby',  'icon' => 'Sprout'],
    ];

    public function handle(): int
    {
        $count = 0;
        foreach ($this->catalog as $item) {
            Interest::updateOrCreate(
                ['name' => $item['name'], 'category' => $item['category']],
                ['icon' => $item['icon']]
            );
            $count++;
        }

        $this->info("{$count} ilgi alanı kataloğa eklendi/güncellendi.");
        $this->table(
            ['Kategori', 'Toplam'],
            collect($this->catalog)->groupBy('category')->map->count()->map(fn ($c, $k) => [$k, $c])->values()
        );
        return self::SUCCESS;
    }
}
