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
        Schema::table('game_accounts', function (Blueprint $table) {
            // Удаляем старое VARCHAR(255) поле, которое не вмещало токен
            $table->dropColumn('shared_secret');

            // Создаем правильное поле TEXT для нового refreshToken
            $table->text('refresh_token')->nullable()->after('password');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('game_accounts', function (Blueprint $table) {
            // Откатываем изменения назад
            $table->dropColumn('refresh_token');
            $table->string('shared_secret')->nullable()->after('password');
        });
    }
};
