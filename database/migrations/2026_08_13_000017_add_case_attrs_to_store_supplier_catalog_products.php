<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_supplier_catalog_products', function (Blueprint $table) {
            // white|black|other
            $table->string('case_color', 16)->nullable()->after('image_synced_at');
            // none|side|front_side
            $table->string('case_glass', 16)->nullable()->after('case_color');
            // atx|matx|itx|eatx|other
            $table->string('case_form', 16)->nullable()->after('case_glass');
            $table->timestamp('case_attrs_at')->nullable()->after('case_form');

            $table->index(['case_color']);
            $table->index(['case_glass']);
            $table->index(['case_form']);
        });
    }

    public function down(): void
    {
        Schema::table('store_supplier_catalog_products', function (Blueprint $table) {
            $table->dropIndex(['case_color']);
            $table->dropIndex(['case_glass']);
            $table->dropIndex(['case_form']);
            $table->dropColumn(['case_color', 'case_glass', 'case_form', 'case_attrs_at']);
        });
    }
};
