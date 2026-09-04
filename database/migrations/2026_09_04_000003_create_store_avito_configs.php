<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_avito_parts', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16);
            $table->string('code', 64)->unique();
            $table->string('label');
            $table->string('socket', 32)->nullable();
            $table->string('ddr', 16)->nullable();
            $table->unsignedSmallInteger('ram_gb')->nullable();
            $table->unsignedSmallInteger('capacity_gb')->nullable();
            $table->unsignedSmallInteger('wattage')->nullable();
            $table->string('avito_brand')->nullable();
            $table->string('avito_model')->nullable();
            $table->string('avito_code')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['type', 'socket']);
            $table->index(['type', 'ddr']);
        });

        Schema::create('store_avito_configs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('cpu_part_id')->constrained('store_avito_parts');
            $table->foreignId('ram_part_id')->constrained('store_avito_parts');
            $table->foreignId('ssd_part_id')->constrained('store_avito_parts');
            $table->foreignId('psu_part_id')->constrained('store_avito_parts');
            $table->string('socket', 32);
            $table->string('ddr', 16);
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('use_count')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['enabled', 'sort_order']);
            $table->index(['socket', 'ddr']);
        });

        Schema::table('store_avito_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('last_config_id')->nullable()->after('last_dict_sync_result');
        });

        Schema::table('store_avito_ads', function (Blueprint $table) {
            $table->unsignedBigInteger('store_avito_config_id')->nullable()->after('fingerprint');
            $table->index('store_avito_config_id');
        });
    }

    public function down(): void
    {
        Schema::table('store_avito_ads', function (Blueprint $table) {
            $table->dropIndex(['store_avito_config_id']);
            $table->dropColumn('store_avito_config_id');
        });
        Schema::table('store_avito_settings', function (Blueprint $table) {
            $table->dropColumn('last_config_id');
        });
        Schema::dropIfExists('store_avito_configs');
        Schema::dropIfExists('store_avito_parts');
    }
};
