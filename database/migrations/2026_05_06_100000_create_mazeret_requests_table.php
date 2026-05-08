<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mazeret_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->text('reason')->nullable();
            $table->string('file_path', 500);
            $table->string('file_original_name', 255);
            $table->string('file_mime', 100);
            $table->unsignedInteger('file_size');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            // Aynı (öğrenci, sınıf, tarih) için tek aktif mazeret olabilir.
            // Reddedilen tekrar yüklenebilsin diye unique constraint controller-side kontrol edilir.
            $table->index(['student_id', 'classroom_id', 'date']);
            $table->index(['classroom_id', 'status']);
            $table->index(['student_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mazeret_requests');
    }
};
