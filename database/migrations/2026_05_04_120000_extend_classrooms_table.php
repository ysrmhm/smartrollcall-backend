<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classrooms', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->onDelete('cascade');
            $table->string('department')->nullable()->after('name');
            $table->string('day', 20)->nullable()->after('department');
            $table->string('time', 10)->nullable()->after('day');
            $table->string('status', 20)->default('Aktif')->after('time');
            $table->boolean('attendance_taken')->default(false)->after('status');
            $table->string('file_name')->nullable()->after('attendance_taken');

            $table->index('user_id');
            $table->index(['user_id', 'status']);
        });

        // Mevcut 'code' artık zorunlu değil — frontend kullanmıyor
        Schema::table('classrooms', function (Blueprint $table) {
            $table->string('code')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('classrooms', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropIndex(['user_id']);
            $table->dropIndex(['user_id', 'status']);
            $table->dropColumn([
                'user_id', 'department', 'day', 'time', 'status', 'attendance_taken', 'file_name',
            ]);
            $table->string('code')->nullable(false)->change();
        });
    }
};
