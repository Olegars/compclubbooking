<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('store_order_items', 'store_component_id')) {
                $table->foreignId('store_component_id')
                    ->nullable()
                    ->after('store_product_id')
                    ->constrained('store_components')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('store_order_items', function (Blueprint $table) {
            if (Schema::hasColumn('store_order_items', 'store_component_id')) {
                $table->dropConstrainedForeignId('store_component_id');
            }
        });
    }
};
