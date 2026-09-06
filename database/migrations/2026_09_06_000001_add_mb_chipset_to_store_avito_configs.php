<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_avito_configs', function (Blueprint $table) {
            $table->foreignId('mb_part_id')->nullable()->after('cpu_part_id')->constrained('store_avito_parts');
        });
    }

    public function down(): void
    {
        Schema::table('store_avito_configs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mb_part_id');
        });
    }
};
