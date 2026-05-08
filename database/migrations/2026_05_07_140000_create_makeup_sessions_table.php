<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('makeup_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->date('date');                    // YYYY-MM-DD — telafi tarihi
            $table->string('time', 10);              // HH:MM
            $table->string('day', 20)->nullable();   // Pazartesi vb. (cache)
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['classroom_id', 'date', 'time']);
            $table->index(['date', 'classroom_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('makeup_sessions');
    }
};
