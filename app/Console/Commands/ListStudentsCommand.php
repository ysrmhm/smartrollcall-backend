<?php

namespace App\Console\Commands;

use App\Models\Student;
use Illuminate\Console\Command;

class ListStudentsCommand extends Command
{
    protected $signature = 'students:list {--limit=10}';
    protected $description = 'Test için öğrenci numarası + sınıf bilgisini listeler.';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        $students = Student::with('classroom')
            ->whereNotNull('classroom_id')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $this->table(
            ['student_number (=şifre)', 'Ad Soyad', 'Sınıf', 'Şifre Değiştirildi mi?'],
            $students->map(fn ($s) => [
                $s->student_number,
                $s->name,
                $s->classroom?->name ?? '—',
                $s->must_change_password ? 'Hayır (ilk giriş)' : 'Evet',
            ])->all()
        );

        return self::SUCCESS;
    }
}
