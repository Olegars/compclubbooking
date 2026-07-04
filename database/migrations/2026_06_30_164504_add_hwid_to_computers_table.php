<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('computers', function (Blueprint $table) {
            // Добавляем nullable-поле hwid после поля name.
            // Оно должно быть nullable, так как изначально на карте ПК создаются без привязки к железу.
            $table->string('hwid')->nullable()->unique()->after('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('computers', function (Blueprint $table) {
            $table->dropColumn('hwid');
        });
    }
};
