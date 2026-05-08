<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interests', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('category', 40); // tech | sport | media | music | hobby
            $table->string('icon', 40)->nullable(); // lucide icon name
            $table->timestamps();

            $table->unique(['name', 'category']);
            $table->index('category');
        });

        Schema::create('student_interests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('interest_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('level')->default(3); // 1-5
            $table->timestamps();

            $table->unique(['student_id', 'interest_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_interests');
        Schema::dropIfExists('interests');
    }
};
