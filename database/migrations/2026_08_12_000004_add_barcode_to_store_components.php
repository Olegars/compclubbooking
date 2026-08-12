<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_components', function (Blueprint $table) {
            if (! Schema::hasColumn('store_components', 'barcode')) {
                $table->string('barcode', 128)->nullable()->after('name');
                $table->index(['club_id', 'barcode']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('store_components', function (Blueprint $table) {
            if (Schema::hasColumn('store_components', 'barcode')) {
                $table->dropIndex(['club_id', 'barcode']);
                $table->dropColumn('barcode');
            }
        });
    }
};
