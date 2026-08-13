<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('store_supplier_catalog_products')) {
            return;
        }

        // PostgreSQL / MySQL: длинные названия/партномера из прайса поставщика
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE store_supplier_catalog_products ALTER COLUMN name TYPE TEXT');
            DB::statement('ALTER TABLE store_supplier_catalog_products ALTER COLUMN part TYPE TEXT');
            DB::statement('ALTER TABLE store_supplier_catalog_products ALTER COLUMN vendor TYPE VARCHAR(512)');
            DB::statement('ALTER TABLE store_supplier_catalog_products ALTER COLUMN warranty TYPE VARCHAR(64)');
        } else {
            DB::statement('ALTER TABLE store_supplier_catalog_products MODIFY name TEXT NOT NULL');
            DB::statement('ALTER TABLE store_supplier_catalog_products MODIFY part TEXT NULL');
            DB::statement('ALTER TABLE store_supplier_catalog_products MODIFY vendor VARCHAR(512) NULL');
            DB::statement('ALTER TABLE store_supplier_catalog_products MODIFY warranty VARCHAR(64) NULL');
        }
    }

    public function down(): void
    {
        // необратимо без потери данных — оставляем расширенные типы
    }
};
