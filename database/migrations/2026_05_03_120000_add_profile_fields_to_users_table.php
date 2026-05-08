<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('name');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('email')->nullable()->after('username');
            $table->string('phone', 20)->nullable()->after('email');
            $table->string('institution')->nullable()->after('phone');
            $table->longText('avatar')->nullable()->after('institution');
            $table->json('preferences')->nullable()->after('avatar');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'first_name',
                'last_name',
                'email',
                'phone',
                'institution',
                'avatar',
                'preferences',
            ]);
        });
    }
};
