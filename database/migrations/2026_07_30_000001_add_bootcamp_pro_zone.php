<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Отдельный тип «Буткамп Pro» — раньше на карте был botkamp-profi,
 * но при нормализации ошибочно схлопнулся в bootcamp.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('zones')->updateOrInsert(
            ['slug' => 'bootcamp-pro'],
            [
                'name' => 'Буткамп Pro',
                'color' => '#e01b24',
                'sort' => 55,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );
    }

    public function down(): void
    {
        DB::table('zones')->where('slug', 'bootcamp-pro')->delete();
    }
};
