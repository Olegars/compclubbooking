<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Заполняет справочники целевой структурой.
 *
 * Прежний набор зон (standard / solo / bootkamp / ps5) смешивал формат посадки
 * с типом устройства, поэтому заменяется целиком. Цены не сеются: матрица
 * заполняется в админке, пустая ячейка означает «здесь не продаётся».
 */
return new class extends Migration
{
    private const ZONES = [
        ['slug' => 'singl', 'name' => 'Сингл', 'color' => '#38bdf8'],
        ['slug' => 'duo', 'name' => 'Дуо', 'color' => '#22c55e'],
        ['slug' => 'trio', 'name' => 'Трио', 'color' => '#a3e635'],
        ['slug' => 'kvatro', 'name' => 'Кватро', 'color' => '#fbbf24'],
        ['slug' => 'bootcamp', 'name' => 'Буткамп', 'color' => '#ef4444'],
        ['slug' => 'tv', 'name' => 'ТВ', 'color' => '#a855f7'],
    ];

    private const SEAT_CLASSES = [
        ['slug' => 'pc', 'name' => 'ПК', 'kind' => 'pc', 'color' => '#22c55e'],
        ['slug' => 'tv', 'name' => 'Телевизор', 'kind' => 'tv', 'color' => '#a855f7'],
    ];

    public function up(): void
    {
        $now = now();

        DB::table('zones')->whereNotIn('slug', array_column(self::ZONES, 'slug'))->delete();

        foreach (self::ZONES as $index => $zone) {
            DB::table('zones')->updateOrInsert(
                ['slug' => $zone['slug']],
                [
                    'name' => $zone['name'],
                    'color' => $zone['color'],
                    'sort' => ($index + 1) * 10,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        foreach (self::SEAT_CLASSES as $index => $class) {
            DB::table('seat_classes')->updateOrInsert(
                ['slug' => $class['slug']],
                [
                    'name' => $class['name'],
                    'kind' => $class['kind'],
                    'color' => $class['color'],
                    'sort' => ($index + 1) * 10,
                    'is_active' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        $pcClassId = DB::table('seat_classes')->where('slug', 'pc')->value('id');
        $tvClassId = DB::table('seat_classes')->where('slug', 'tv')->value('id');

        // Стартовое назначение: всё, что не экран, считается ПК.
        // Точное железо администратор проставит в админке классов.
        DB::table('computers')->whereIn('kind', ['tv', 'ps5'])->update(['seat_class_id' => $tvClassId]);
        DB::table('computers')->whereNotIn('kind', ['tv', 'ps5'])->update(['seat_class_id' => $pcClassId]);
    }

    public function down(): void
    {
        DB::table('computers')->update(['seat_class_id' => null, 'space_id' => null]);
        DB::table('seat_classes')->whereIn('slug', array_column(self::SEAT_CLASSES, 'slug'))->delete();
        DB::table('zones')->whereIn('slug', array_column(self::ZONES, 'slug'))->delete();
    }
};
