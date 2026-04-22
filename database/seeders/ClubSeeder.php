<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Club;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClubSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Создаем или обновляем ОДИН конкретный клуб
        $name = 'Reactor Protocol';

        $config = [
            'walls' => 'M40,0 H90 V40 H50 V60 H80 V70 H100 V120 H65 V70 H45 V120 H0 V35 H40 Z',
            'rooms' => [
                ['name' => 'WC', 'd' => 'M0,35 H15 V55 H0 Z'],
                ['name' => 'Staff', 'd' => 'M90,0 H110 V30 H90 Z']
            ],
            'doors' => [
                ['d' => 'M5,120 H20'],
                ['d' => 'M15,40 V45']
            ],
            'labels' => [
                ['x' => 2, 'y' => 45, 'content' => 'WC'],
                ['x' => 92, 'y' => 15, 'content' => 'STAFF'],
                ['x' => 5, 'y' => 128, 'content' => 'ВХОД']
            ],
            // ---> ДОБАВЛЕНЫ ИГРОВЫЕ ЗОНЫ <---
            'zoneRects' => [
                // PRO зона (компы 1-5)
                ['x' => 82, 'y' => 10, 'w' => 8, 'h' => 25, 'c' => '#fbbf24'],
                // BOOTCAMP зона (компы 6-20)
                ['x' => 38, 'y' => 0, 'w' => 28, 'h' => 32, 'c' => '#ef4444'],
                // Левое крыло (DUO, TRIO, STANDARD)
                ['x' => 0, 'y' => 66, 'w' => 30, 'h' => 45, 'c' => '#3b82f6'],
                // Правое крыло (DUO, TRIO, STANDARD)
                ['x' => 64, 'y' => 70, 'w' => 35, 'h' => 35, 'c' => '#a855f7']
            ]
        ];

        $club = Club::updateOrCreate(
            ['name' => $name],
            [
                'slug' => Str::slug($name),
                'viewbox' => '-10 -10 140 160', // Масштаб камеры по умолчанию
                'map_config' => $config
            ]
        );

        // 2. Теперь заполняем компьютеры для ЭТОГО клуба
        // Очищаем старые записи, чтобы не было дублей
        DB::table('computers')->where('club_id', $club->id)->delete();

        $pcs = [];
        $addPcs = function(&$pcs, $ids, $startX, $startY, $stepX, $stepY, $type, $clubId) {
            foreach ($ids as $index => $id) {
                $pcs[] = [
                    'id' => $id,
                    'club_id' => $clubId,
                    'name' => sprintf("%02d", $id),
                    'x' => $startX + ($index * $stepX),
                    'y' => $startY + ($index * $stepY),
                    'type' => $type,
                    'status' => 'available',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        };

        // Твои координаты
        $addPcs($pcs, [1,2,3,4,5], 84, 13, 0, 5, 'pro', $club->id);
        $addPcs($pcs, [16,17,18,19,20], 42.5, 2.2, 5, 0, 'bootcamp', $club->id);
        $addPcs($pcs, [11,12,13,14,15], 41.5, 10, 0, 5, 'bootcamp', $club->id);
        $addPcs($pcs, [6,7,8,9,10], 62.3, 10, 0, 5, 'bootcamp', $club->id);
        $addPcs($pcs, [21,22], 2.2, 69, 0, 7.5, 'duo', $club->id);
        $addPcs($pcs, [23,24,25], 2.2, 84.3, 0, 5.8, 'trio', $club->id);
        $addPcs($pcs, [26,27], 2.2, 104.3, 0, 7.5, 'duo', $club->id);
        $addPcs($pcs, [28,29,30,31,32], 23.5, 71.5, 0, 9.8, 'standard', $club->id);
        $addPcs($pcs, [33], 66.8, 74.8, 0, 0, 'standard', $club->id);
        $addPcs($pcs, [34,35], 66.8, 83.5, 0, 7.8, 'duo', $club->id);
        $addPcs($pcs, [36,37], 66.8, 98.4, 0, 7.8, 'duo', $club->id);
        $addPcs($pcs, [38,39,40], 93, 74.5, 0, 6.2, 'trio', $club->id);
        $addPcs($pcs, [41,42], 93, 94, 0, 6.5, 'duo', $club->id);

        DB::table('computers')->insert($pcs);
    }
}
