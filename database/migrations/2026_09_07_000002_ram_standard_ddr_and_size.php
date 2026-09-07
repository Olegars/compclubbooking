<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('store_avito_product_attrs', 'standard')) {
            return;
        }
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement("UPDATE store_avito_product_attrs SET standard = ddr || ' ' || ram_gb WHERE type = 'ram' AND ddr IS NOT NULL AND ddr != '' AND ram_gb IS NOT NULL AND ram_gb > 0");
        } else {
            DB::statement("UPDATE store_avito_product_attrs SET standard = CONCAT(ddr, ' ', ram_gb) WHERE type = 'ram' AND ddr IS NOT NULL AND ddr != '' AND ram_gb IS NOT NULL AND ram_gb > 0");
        }
    }

    public function down(): void
    {
        DB::statement("UPDATE store_avito_product_attrs SET standard = ram_gb WHERE type = 'ram' AND ram_gb IS NOT NULL AND ram_gb > 0");
    }
};
