<?php

namespace App\Console\Commands;

use App\Models\Student;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class DiagnoseStudent9001Command extends Command
{
    protected $signature = 'diagnose:9001';
    protected $description = '9001 öğrencisinin tüm Student kayıtlarını ve şifre kontrolünü inceler.';

    public function handle(): int
    {
        $rows = Student::where('student_number', '9001')->get();
        $this->line("Toplam kayıt: {$rows->count()}");

        $rows = $rows->map(function ($s) {
            return [
                'id'           => $s->id,
                'classroom_id' => $s->classroom_id,
                'first_name'   => $s->first_name,
                'pwd_set'      => $s->password ? 'OK' : 'NULL',
                'pwd_check'    => $s->password ? (Hash::check('9001', $s->password) ? 'TRUE' : 'FALSE') : '—',
                'must_change'  => $s->must_change_password ? 'Y' : 'N',
            ];
        });

        $this->table(
            ['id', 'classroom_id', 'first_name', 'pwd_set', 'pwd_check(9001)', 'must_change'],
            $rows->toArray()
        );

        return self::SUCCESS;
    }
}
