<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_supplier_catalog_products', function (Blueprint $table) {
            $table->decimal('price', 12, 2)->nullable()->after('rrp');
            $table->unsignedInteger('stock_qty')->nullable()->after('price');
            $table->timestamp('price_synced_at')->nullable()->after('stock_qty');
            $table->index('price');
        });
    }

    public function down(): void
    {
        Schema::table('store_supplier_catalog_products', function (Blueprint $table) {
            $table->dropIndex(['price']);
            $table->dropColumn(['price', 'stock_qty', 'price_synced_at']);
        });
    }
};
