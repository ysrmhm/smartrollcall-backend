<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Faz 1: yoklamayı classroom'a bağlıyoruz. Schedule (Faz 2) opsiyonel kalıyor.
     * Idempotent: önceki yarım çalışmış migration state'iyle de doğru çalışır.
     */
    public function up(): void
    {
        // 0) Mevcut FK ve index isimlerini topla
        $fkNames = collect(Schema::getForeignKeys('attendances'))->pluck('name');
        $indexNames = collect(Schema::getIndexes('attendances'))->pluck('name');

        // 1) FK'leri kaldır (sırasıyla schedule_id ve student_id) — unique index'e bağımlılar
        Schema::table('attendances', function (Blueprint $table) use ($fkNames) {
            if ($fkNames->contains('attendances_schedule_id_foreign')) {
                $table->dropForeign(['schedule_id']);
            }
            if ($fkNames->contains('attendances_student_id_foreign')) {
                $table->dropForeign(['student_id']);
            }
        });

        // 2) Eski compound unique
        Schema::table('attendances', function (Blueprint $table) use ($indexNames) {
            if ($indexNames->contains('attendances_student_id_schedule_id_date_unique')) {
                $table->dropUnique(['student_id', 'schedule_id', 'date']);
            }
        });

        // 3) Sütun değişiklikleri ve yeni classroom_id
        Schema::table('attendances', function (Blueprint $table) {
            $table->unsignedBigInteger('schedule_id')->nullable()->change();

            if (!Schema::hasColumn('attendances', 'classroom_id')) {
                $table->unsignedBigInteger('classroom_id')->nullable()->after('id');
            }
        });

        // 4) FK'leri geri ekle
        $fkNamesAfter = collect(Schema::getForeignKeys('attendances'))->pluck('name');
        Schema::table('attendances', function (Blueprint $table) use ($fkNamesAfter) {
            if (!$fkNamesAfter->contains('attendances_classroom_id_foreign')) {
                $table->foreign('classroom_id')->references('id')->on('classrooms')->cascadeOnDelete();
            }
            if (!$fkNamesAfter->contains('attendances_student_id_foreign')) {
                $table->foreign('student_id')->references('id')->on('students')->cascadeOnDelete();
            }
            if (!$fkNamesAfter->contains('attendances_schedule_id_foreign')) {
                $table->foreign('schedule_id')->references('id')->on('schedules')->nullOnDelete();
            }
        });

        // 5) Yeni unique ve index
        $indexNamesAfter = collect(Schema::getIndexes('attendances'))->pluck('name');
        Schema::table('attendances', function (Blueprint $table) use ($indexNamesAfter) {
            if (!$indexNamesAfter->contains('attendances_student_class_date_unique')) {
                $table->unique(['student_id', 'classroom_id', 'date'], 'attendances_student_class_date_unique');
            }
            if (!$indexNamesAfter->contains('attendances_classroom_id_date_index')) {
                $table->index(['classroom_id', 'date']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropForeign(['classroom_id']);
            $table->dropForeign(['schedule_id']);
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->dropUnique('attendances_student_class_date_unique');
            $table->dropIndex(['classroom_id', 'date']);
            $table->dropColumn('classroom_id');
            $table->unsignedBigInteger('schedule_id')->nullable(false)->change();
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->foreign('schedule_id')->references('id')->on('schedules')->cascadeOnDelete();
            $table->unique(['student_id', 'schedule_id', 'date']);
        });
    }
};
