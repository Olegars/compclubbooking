<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_avito_settings', function (Blueprint $table) {
            $table->timestamp('last_dict_sync_at')->nullable()->after('last_error');
            $table->json('last_dict_sync_result')->nullable()->after('last_dict_sync_at');
        });

        Schema::create('store_avito_dict_values', function (Blueprint $table) {
            $table->id();
            $table->string('tag', 64);
            $table->string('value');
            $table->string('parent_value')->default('');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tag', 'parent_value', 'value']);
            $table->index(['tag', 'parent_value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_avito_dict_values');
        Schema::table('store_avito_settings', function (Blueprint $table) {
            $table->dropColumn(['last_dict_sync_at', 'last_dict_sync_result']);
        });
    }
};
