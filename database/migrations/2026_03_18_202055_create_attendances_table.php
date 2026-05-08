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
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->onDelete('cascade'); // Kimin yoklaması?
            $table->foreignId('schedule_id')->constrained('schedules')->onDelete('cascade'); // Hangi programın yoklaması?
            $table->date('date'); // Yoklama tarihi
            $table->enum('status', ['present', 'absent', 'late']); // Geldi, Gelmedi, Geç Kaldı
            $table->timestamps();

            // Aynı gün, aynı derse, aynı öğrenciye 2 kere yoklama girilmesini engelliyoruz (Veri tutarlılığı!)
            $table->unique(['student_id', 'schedule_id', 'date']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
