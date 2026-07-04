<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            // Удаляем старый путь, если он был, и добавляем JSON-поле
            if (Schema::hasColumn('clubs', 'map_path')) {
                $table->dropColumn('map_path');
            }
            // Здесь будет храниться всё: стены, двери, подписи
            $table->json('map_config')->nullable();

            // Также добавим настройки холста, чтобы карта не «прыгала»
            $table->string('viewbox')->default('-10 -20 140 180');
        });
    }

    public function down(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->dropColumn(['map_config', 'viewbox']);
        });
    }
};
