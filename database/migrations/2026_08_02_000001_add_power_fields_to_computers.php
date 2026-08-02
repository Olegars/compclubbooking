<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Управление питанием ПК по расписанию бронирований.
 *
 * power_desired — чего хочет бэкенд (on/off) исходя из букингов ± warmup.
 * power_state   — фактическое состояние, которое видит дашборд.
 * mac_address   — для Wake-on-LAN (шелл присылает при heartbeat).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('computers', function (Blueprint $table) {
            $table->string('mac_address', 17)->nullable()->after('hwid');
            $table->string('power_desired', 8)->default('on')->after('mac_address');
            $table->string('power_state', 16)->default('off')->after('power_desired');
            $table->timestampTz('power_state_updated_at')->nullable()->after('power_state');
            $table->timestampTz('last_seen_at')->nullable()->after('power_state_updated_at');
            $table->timestampTz('wol_sent_at')->nullable()->after('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('computers', function (Blueprint $table) {
            $table->dropColumn([
                'mac_address',
                'power_desired',
                'power_state',
                'power_state_updated_at',
                'last_seen_at',
                'wol_sent_at',
            ]);
        });
    }
};
