<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE attendances MODIFY status ENUM('present','absent','late','excused') NOT NULL");
    }

    public function down(): void
    {
        // 'excused' satırlarını 'absent' olarak geri çevir, sonra eski enum'a dön
        DB::statement("UPDATE attendances SET status='absent' WHERE status='excused'");
        DB::statement("ALTER TABLE attendances MODIFY status ENUM('present','absent','late') NOT NULL");
    }
};
