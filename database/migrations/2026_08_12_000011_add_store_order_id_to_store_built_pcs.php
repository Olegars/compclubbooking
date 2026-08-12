<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_built_pcs', function (Blueprint $table) {
            if (! Schema::hasColumn('store_built_pcs', 'store_order_id')) {
                $table->foreignId('store_order_id')
                    ->nullable()
                    ->after('club_id')
                    ->constrained('store_orders')
                    ->nullOnDelete();
                $table->unique('store_order_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('store_built_pcs', function (Blueprint $table) {
            if (Schema::hasColumn('store_built_pcs', 'store_order_id')) {
                $table->dropUnique(['store_order_id']);
                $table->dropConstrainedForeignId('store_order_id');
            }
        });
    }
};
