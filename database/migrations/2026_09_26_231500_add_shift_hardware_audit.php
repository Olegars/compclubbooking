<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->string('hardware_audit_status', 32)->nullable()->after('presence_meta');
            $table->timestamp('hardware_audit_started_at')->nullable()->after('hardware_audit_status');
            $table->json('hardware_snapshot')->nullable()->after('hardware_audit_started_at');
            $table->json('unresolved_hardware_damages')->nullable()->after('hardware_snapshot');
            $table->json('hardware_wake_ids')->nullable()->after('unresolved_hardware_damages');
        });

        Schema::table('computers', function (Blueprint $table) {
            $table->boolean('shift_audit_hold')->default(false)->after('power_desired');
        });

        Schema::table('incidents', function (Blueprint $table) {
            if (! Schema::hasColumn('incidents', 'responsible_admin_id')) {
                $table->unsignedBigInteger('responsible_admin_id')->nullable()->after('computer_id');
                $table->index(['responsible_admin_id', 'created_at'], 'incidents_responsible_admin_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            if (Schema::hasColumn('incidents', 'responsible_admin_id')) {
                $table->dropIndex('incidents_responsible_admin_idx');
                $table->dropColumn('responsible_admin_id');
            }
        });

        Schema::table('computers', function (Blueprint $table) {
            $table->dropColumn('shift_audit_hold');
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn([
                'hardware_audit_status',
                'hardware_audit_started_at',
                'hardware_snapshot',
                'unresolved_hardware_damages',
                'hardware_wake_ids',
            ]);
        });
    }
};
