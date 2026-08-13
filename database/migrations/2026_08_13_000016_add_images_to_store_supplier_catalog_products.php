<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_supplier_catalog_products', function (Blueprint $table) {
            $table->boolean('has_image')->default(false)->after('barcodes');
            $table->string('image_path', 512)->nullable()->after('has_image');
            $table->timestamp('image_synced_at')->nullable()->after('image_path');
        });
    }

    public function down(): void
    {
        Schema::table('store_supplier_catalog_products', function (Blueprint $table) {
            $table->dropColumn(['has_image', 'image_path', 'image_synced_at']);
        });
    }
};
