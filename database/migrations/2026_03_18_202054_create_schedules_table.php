<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade'); // Hangi Hoca?
            $table->foreignId('classroom_id')->constrained('classrooms')->onDelete('cascade'); // Hangi Sınıf?
            $table->foreignId('course_id')->constrained('courses')->onDelete('cascade'); // Hangi Ders?
            $table->integer('day_of_week'); // 1: Pazartesi, 2: Salı ... 5: Cuma
            $table->time('start_time'); // Başlangıç saati (Örn: 09:00)
            $table->time('end_time'); // Bitiş saati (Örn: 10:30)
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};
