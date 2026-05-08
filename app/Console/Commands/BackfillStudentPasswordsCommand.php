<?php

namespace App\Console\Commands;

use App\Models\Student;
use Illuminate\Console\Command;

class BackfillStudentPasswordsCommand extends Command
{
    protected $signature = 'students:backfill-passwords';
    protected $description = 'Mevcut öğrencilere ilk şifre olarak student_number atar (must_change_password=true).';

    public function handle(): int
    {
        $students = Student::whereNull('password')->get();
        $this->info("Toplam {$students->count()} öğrenciye şifre atanacak.");

        $updated = 0;
        foreach ($students as $student) {
            $student->forceFill([
                'password'             => $student->student_number,
                'must_change_password' => true,
            ])->save();
            $updated++;
        }

        $this->info("{$updated} öğrenci güncellendi.");

        return self::SUCCESS;
    }
}
