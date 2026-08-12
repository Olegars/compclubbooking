<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_built_pcs', function (Blueprint $table) {
            if (! Schema::hasColumn('store_built_pcs', 'verified_at')) {
                $table->timestamp('verified_at')->nullable()->after('notes');
            }
            if (! Schema::hasColumn('store_built_pcs', 'verified_ok')) {
                $table->boolean('verified_ok')->default(false)->after('verified_at');
            }
            if (! Schema::hasColumn('store_built_pcs', 'verified_hostname')) {
                $table->string('verified_hostname', 128)->nullable()->after('verified_ok');
            }
        });

        Schema::table('store_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('store_orders', 'verified_at')) {
                $table->timestamp('verified_at')->nullable()->after('notes');
            }
            if (! Schema::hasColumn('store_orders', 'verified_ok')) {
                $table->boolean('verified_ok')->default(false)->after('verified_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('store_built_pcs', function (Blueprint $table) {
            foreach (['verified_hostname', 'verified_ok', 'verified_at'] as $col) {
                if (Schema::hasColumn('store_built_pcs', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        Schema::table('store_orders', function (Blueprint $table) {
            foreach (['verified_ok', 'verified_at'] as $col) {
                if (Schema::hasColumn('store_orders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
