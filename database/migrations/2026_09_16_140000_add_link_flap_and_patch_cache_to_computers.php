<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link-flap инциденты + LAN P2P patch cache (seed/peer по heartbeat).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('computers', function (Blueprint $table) {
            if (! Schema::hasColumn('computers', 'lan_ip')) {
                $table->string('lan_ip', 45)->nullable()->after('mac_address');
            }
            if (! Schema::hasColumn('computers', 'patch_seed_port')) {
                $table->unsignedSmallInteger('patch_seed_port')->nullable()->after('lan_ip');
            }
            if (! Schema::hasColumn('computers', 'nic_flap_count')) {
                $table->unsignedInteger('nic_flap_count')->default(0)->after('nic_link_mbps');
            }
            if (! Schema::hasColumn('computers', 'nic_flap_shift_id')) {
                $table->unsignedBigInteger('nic_flap_shift_id')->nullable()->after('nic_flap_count');
            }
            if (! Schema::hasColumn('computers', 'nic_flap_last_at')) {
                $table->timestampTz('nic_flap_last_at')->nullable()->after('nic_flap_shift_id');
            }
            if (! Schema::hasColumn('computers', 'patch_pull_command_id')) {
                $table->unsignedBigInteger('patch_pull_command_id')->nullable()->after('resync_message');
            }
            if (! Schema::hasColumn('computers', 'patch_pull_command_at')) {
                $table->timestampTz('patch_pull_command_at')->nullable()->after('patch_pull_command_id');
            }
            if (! Schema::hasColumn('computers', 'patch_pull_payload')) {
                $table->json('patch_pull_payload')->nullable()->after('patch_pull_command_at');
            }
            if (! Schema::hasColumn('computers', 'patch_pull_result')) {
                $table->string('patch_pull_result', 32)->nullable()->after('patch_pull_payload');
            }
            if (! Schema::hasColumn('computers', 'patch_pull_message')) {
                $table->string('patch_pull_message', 240)->nullable()->after('patch_pull_result');
            }
        });
    }

    public function down(): void
    {
        Schema::table('computers', function (Blueprint $table) {
            $cols = [
                'lan_ip',
                'patch_seed_port',
                'nic_flap_count',
                'nic_flap_shift_id',
                'nic_flap_last_at',
                'patch_pull_command_id',
                'patch_pull_command_at',
                'patch_pull_payload',
                'patch_pull_result',
                'patch_pull_message',
            ];
            $drop = array_values(array_filter($cols, fn ($c) => Schema::hasColumn('computers', $c)));
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};
