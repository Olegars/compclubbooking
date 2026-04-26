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
        Schema::table('games', function (Blueprint $table) {
            $table->string('category')->nullable()->after('platform'); // Категория: Шутер, RPG...
            $table->string('poster')->nullable()->after('category');   // Ссылка на картинку
            $table->string('exe_path')->nullable()->after('poster');   // Путь: D:\Games\...
            $table->string('launch_args')->nullable()->after('exe_path'); // Аргументы: -applaunch 570
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            //
        });
    }
};
