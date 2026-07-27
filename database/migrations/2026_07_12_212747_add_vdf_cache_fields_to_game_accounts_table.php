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
            // Добавляем поля под кэш файлов конфигурации Steam
            // Используем nullable(), так как изначально у новых аккаунтов файлов не будет
            $table->mediumText('config_vdf')->nullable()->after('persona_name');
            $table->mediumText('loginusers_vdf')->nullable()->after('config_vdf');
            $table->mediumText('local_vdf')->nullable()->after('loginusers_vdf');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('game_accounts', function (Blueprint $table) {
            // Откат миграции в случае необходимости
            $table->dropColumn(['config_vdf', 'loginusers_vdf', 'local_vdf']);
        });
    }
};
