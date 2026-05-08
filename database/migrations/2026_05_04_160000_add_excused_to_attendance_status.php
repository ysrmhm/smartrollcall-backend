<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            // MySQL: ENUM'a yeni değer ekle
            DB::statement("ALTER TABLE attendances MODIFY status ENUM('present','absent','late','excused') NOT NULL");
        } elseif ($driver === 'pgsql') {
            // PostgreSQL: Laravel enum() VARCHAR + CHECK constraint olarak yaratır.
            // Önce eski CHECK constraint'i bul ve sil, sonra yeni değerleri içeren CHECK ekle.
            DB::statement("ALTER TABLE attendances DROP CONSTRAINT IF EXISTS attendances_status_check");
            DB::statement("ALTER TABLE attendances ADD CONSTRAINT attendances_status_check CHECK (status IN ('present','absent','late','excused'))");
        } elseif ($driver === 'sqlite') {
            // SQLite: enum diye bir tip yok, CHECK constraint'i pratikte sürükleyemez.
            // Yeni 'excused' değerine kabul ediyor olabilir; bir şey yapma.
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        // 'excused' satırlarını 'absent' olarak geri çevir
        DB::statement("UPDATE attendances SET status='absent' WHERE status='excused'");

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE attendances MODIFY status ENUM('present','absent','late') NOT NULL");
        } elseif ($driver === 'pgsql') {
            DB::statement("ALTER TABLE attendances DROP CONSTRAINT IF EXISTS attendances_status_check");
            DB::statement("ALTER TABLE attendances ADD CONSTRAINT attendances_status_check CHECK (status IN ('present','absent','late'))");
        }
    }
};
