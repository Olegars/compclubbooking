<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Слухач вентиляторов / golden image / eco-GPU: инциденты привязываются к ПК,
 * heartbeat хранит целостность D: и лимит видеокарты, очередь тихого re-sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            if (! Schema::hasColumn('incidents', 'computer_id')) {
                $table->unsignedBigInteger('computer_id')->nullable()->after('order_id');
                $table->index(['computer_id', 'type', 'resolved_at'], 'incidents_computer_type_open_idx');
            }
            if (! Schema::hasColumn('incidents', 'payload')) {
                $table->json('payload')->nullable()->after('description');
            }
        });

        Schema::table('computers', function (Blueprint $table) {
            if (! Schema::hasColumn('computers', 'integrity_status')) {
                $table->string('integrity_status', 16)->nullable()->after('diskless_message');
            }
            if (! Schema::hasColumn('computers', 'integrity_hash')) {
                $table->string('integrity_hash', 64)->nullable()->after('integrity_status');
            }
            if (! Schema::hasColumn('computers', 'integrity_message')) {
                $table->string('integrity_message', 240)->nullable()->after('integrity_hash');
            }
            if (! Schema::hasColumn('computers', 'integrity_drift')) {
                $table->json('integrity_drift')->nullable()->after('integrity_message');
            }
            if (! Schema::hasColumn('computers', 'gpu_power_limit_w')) {
                $table->unsignedSmallInteger('gpu_power_limit_w')->nullable()->after('integrity_drift');
            }
            if (! Schema::hasColumn('computers', 'gpu_mode')) {
                $table->string('gpu_mode', 16)->nullable()->after('gpu_power_limit_w');
            }
            if (! Schema::hasColumn('computers', 'resync_command')) {
                $table->string('resync_command', 32)->nullable()->after('gpu_mode');
            }
            if (! Schema::hasColumn('computers', 'resync_command_id')) {
                $table->unsignedBigInteger('resync_command_id')->nullable()->after('resync_command');
            }
            if (! Schema::hasColumn('computers', 'resync_command_at')) {
                $table->timestampTz('resync_command_at')->nullable()->after('resync_command_id');
            }
            if (! Schema::hasColumn('computers', 'resync_result')) {
                $table->string('resync_result', 32)->nullable()->after('resync_command_at');
            }
            if (! Schema::hasColumn('computers', 'resync_message')) {
                $table->string('resync_message', 240)->nullable()->after('resync_result');
            }
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            if (Schema::hasColumn('incidents', 'computer_id')) {
                $table->dropIndex('incidents_computer_type_open_idx');
                $table->dropColumn('computer_id');
            }
            if (Schema::hasColumn('incidents', 'payload')) {
                $table->dropColumn('payload');
            }
        });

        Schema::table('computers', function (Blueprint $table) {
            $cols = [
                'integrity_status',
                'integrity_hash',
                'integrity_message',
                'integrity_drift',
                'gpu_power_limit_w',
                'gpu_mode',
                'resync_command',
                'resync_command_id',
                'resync_command_at',
                'resync_result',
                'resync_message',
            ];
            $drop = array_values(array_filter($cols, fn ($c) => Schema::hasColumn('computers', $c)));
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};
