<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Температура SSD-кэша с шелла: heartbeat (плитка дашборда) и computer_thermals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('computers', function (Blueprint $table) {
            $table->decimal('ssd_temp_c', 5, 1)->nullable()->after('volume_letter');
        });

        Schema::table('computer_thermals', function (Blueprint $table) {
            $table->decimal('ssd_c', 5, 1)->nullable()->after('cpu_c');
        });
    }

    public function down(): void
    {
        Schema::table('computers', function (Blueprint $table) {
            $table->dropColumn('ssd_temp_c');
        });

        Schema::table('computer_thermals', function (Blueprint $table) {
            $table->dropColumn('ssd_c');
        });
    }
};
