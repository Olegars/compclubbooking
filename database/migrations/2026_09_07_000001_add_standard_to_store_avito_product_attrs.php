<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_avito_product_attrs', function (Blueprint $table) {
            $table->string('standard', 64)->nullable()->after('type');
            $table->index(['type', 'standard']);
        });

        $driver = Schema::getConnection()->getDriverName();
        $len = $driver === 'sqlite' ? 'length(avito_code)' : 'CHAR_LENGTH(avito_code)';
        DB::statement("UPDATE store_avito_product_attrs SET standard = avito_code WHERE (standard IS NULL OR standard = '') AND type IN ('cpu', 'gpu', 'motherboard') AND avito_code IS NOT NULL AND avito_code != '' AND avito_code != 'SKIP' AND {$len} <= 64");
        DB::statement("UPDATE store_avito_product_attrs SET standard = ram_gb WHERE (standard IS NULL OR standard = '') AND type IN ('ssd', 'storage_ssd') AND ram_gb IS NOT NULL AND ram_gb > 0");
        if ($driver === 'sqlite') {
            DB::statement("UPDATE store_avito_product_attrs SET standard = ddr || ' ' || ram_gb WHERE type = 'ram' AND ddr IS NOT NULL AND ddr != '' AND ram_gb IS NOT NULL AND ram_gb > 0");
        } else {
            DB::statement("UPDATE store_avito_product_attrs SET standard = CONCAT(ddr, ' ', ram_gb) WHERE type = 'ram' AND ddr IS NOT NULL AND ddr != '' AND ram_gb IS NOT NULL AND ram_gb > 0");
        }
        DB::statement("UPDATE store_avito_product_attrs SET standard = wattage WHERE (standard IS NULL OR standard = '') AND type = 'psu' AND wattage IS NOT NULL AND wattage > 0");
    }

    public function down(): void
    {
        Schema::table('store_avito_product_attrs', function (Blueprint $table) {
            $table->dropIndex(['type', 'standard']);
            $table->dropColumn('standard');
        });
    }
};
