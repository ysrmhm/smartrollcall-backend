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
        Schema::create('classrooms', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Örn: 1. Sınıf - A Şubesi, Bilgisayar Prog. 1 vb.
            $table->string('code')->unique(); // Örn: BP-1A (Hızlı arama için)
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('classrooms');
    }
};
