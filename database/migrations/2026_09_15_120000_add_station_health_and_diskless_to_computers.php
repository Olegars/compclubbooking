<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Здоровье станции (линк, SMART) + инвентарь игр + очередь Super Client из админки.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('computers', function (Blueprint $table) {
            $table->unsignedInteger('nic_link_mbps')->nullable()->after('ssd_temp_c');
            $table->unsignedTinyInteger('ssd_wear_pct')->nullable()->after('nic_link_mbps');
            $table->unsignedBigInteger('ssd_read_errors')->nullable()->after('ssd_wear_pct');
            $table->unsignedBigInteger('ssd_write_errors')->nullable()->after('ssd_read_errors');
            $table->string('ssd_health', 16)->nullable()->after('ssd_write_errors');
            $table->boolean('super_client')->default(false)->after('ssd_health');
            $table->unsignedInteger('games_steam_count')->nullable()->after('super_client');
            $table->unsignedInteger('games_epic_count')->nullable()->after('games_steam_count');
            $table->string('games_inventory_hash', 64)->nullable()->after('games_epic_count');
            $table->jsonb('games_inventory')->nullable()->after('games_inventory_hash');
            $table->string('diskless_command', 32)->nullable()->after('games_inventory');
            $table->string('diskless_disk_mode', 16)->nullable()->after('diskless_command');
            $table->unsignedBigInteger('diskless_command_id')->nullable()->after('diskless_disk_mode');
            $table->timestampTz('diskless_command_at')->nullable()->after('diskless_command_id');
            $table->string('diskless_result', 32)->nullable()->after('diskless_command_at');
            $table->string('diskless_message', 240)->nullable()->after('diskless_result');
        });
    }

    public function down(): void
    {
        Schema::table('computers', function (Blueprint $table) {
            $table->dropColumn([
                'nic_link_mbps',
                'ssd_wear_pct',
                'ssd_read_errors',
                'ssd_write_errors',
                'ssd_health',
                'super_client',
                'games_steam_count',
                'games_epic_count',
                'games_inventory_hash',
                'games_inventory',
                'diskless_command',
                'diskless_disk_mode',
                'diskless_command_id',
                'diskless_command_at',
                'diskless_result',
                'diskless_message',
            ]);
        });
    }
};
