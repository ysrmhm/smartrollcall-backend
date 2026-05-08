<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Önce giriş yapacağımız öğretmeni oluşturalım (factory'yi kaldırdık)
        \App\Models\User::create([
            'name' => 'Yaşar Muhammed Topal', 
            'username' => 'yasarhoca',
            'password' => bcrypt('123456'), 
            'is_admin' => true,
        ]);

        // 2. Test için sınıflarımızı oluşturalım
        \App\Models\Classroom::create(['name' => 'Bilgisayar Programcılığı 1. Sınıf', 'code' => 'BP-1']);
        \App\Models\Classroom::create(['name' => 'Bilgisayar Programcılığı 2. Sınıf', 'code' => 'BP-2']);

        // 3. Dersleri oluşturalım
        \App\Models\Course::create(['name' => 'Görsel Programlama', 'code' => 'GRP101']);
        \App\Models\Course::create(['name' => 'Veri Yapıları', 'code' => 'VYP201']);
    }
}
