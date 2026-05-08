<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_showcase_badges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('badge_code', 60); // örn. 'flawless'
            $table->unsignedTinyInteger('position'); // 1, 2 veya 3 (avatar etrafında orbital sıra)
            $table->timestamps();

            $table->unique(['student_id', 'position']);
            $table->unique(['student_id', 'badge_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_showcase_badges');
    }
};
