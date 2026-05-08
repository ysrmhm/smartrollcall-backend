<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->enum('audience', ['all', 'absent_today', 'risk'])->default('all');
            $table->string('title', 200);
            $table->text('body');
            $table->unsignedInteger('recipients_count')->default(0);
            $table->timestamps();

            $table->index(['classroom_id', 'created_at']);
            $table->index('sender_id');
        });

        // student_inbox_messages'a duyuru bağlantısı için kolonlar (gruplama+ filtreleme için)
        Schema::table('student_inbox_messages', function (Blueprint $table) {
            $table->foreignId('announcement_id')->nullable()->after('student_id')
                ->constrained('announcements')->nullOnDelete();
            $table->index('announcement_id');
        });
    }

    public function down(): void
    {
        Schema::table('student_inbox_messages', function (Blueprint $table) {
            $table->dropForeign(['announcement_id']);
            $table->dropColumn('announcement_id');
        });
        Schema::dropIfExists('announcements');
    }
};
