<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_inbox_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['info', 'success', 'warning', 'danger'])->default('info');
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->string('link', 300)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'read_at']);
            $table->index(['student_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_inbox_messages');
    }
};
