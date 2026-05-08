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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Öğretmenin Adı Soyadı
            $table->string('username')->unique(); // Giriş için kullanıcı adı
            $table->string('password'); // Şifre
            $table->boolean('is_admin')->default(false); // İleride bölüm başkanı vb. için yetki sistemi
            $table->rememberToken();
            $table->timestamps();
        });

        // Şifre sıfırlama ve session tabloları alt kısımda kalabilir, onlara dokunmana gerek yok.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
