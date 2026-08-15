<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Гибридный шелл: SSD-кэш в heartbeat и техрежим, который не гасится idle-policy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('computers', function (Blueprint $table) {
            $table->boolean('maintenance')->default(false)->after('status');
            $table->timestampTz('maintenance_until')->nullable()->after('maintenance');
            $table->boolean('cache_ok')->nullable()->after('wol_sent_at');
            $table->decimal('cache_free_gb', 8, 2)->nullable()->after('cache_ok');
            $table->string('data_root', 260)->nullable()->after('cache_free_gb');
            $table->string('volume_letter', 8)->nullable()->after('data_root');
        });
    }

    public function down(): void
    {
        Schema::table('computers', function (Blueprint $table) {
            $table->dropColumn([
                'maintenance',
                'maintenance_until',
                'cache_ok',
                'cache_free_gb',
                'data_root',
                'volume_letter',
            ]);
        });
    }
};
