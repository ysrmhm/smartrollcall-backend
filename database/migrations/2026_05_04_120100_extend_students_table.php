<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            // Aynı öğrenci farklı sınıflarda olabilir → global unique kaldır,
            // sınıf içi unique yap.
            $table->dropUnique(['student_number']);
            $table->unique(['classroom_id', 'student_number']);

            $table->string('email')->nullable()->after('last_name');
            $table->string('phone', 20)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropUnique(['classroom_id', 'student_number']);
            $table->unique('student_number');
            $table->dropColumn(['email', 'phone']);
        });
    }
};
