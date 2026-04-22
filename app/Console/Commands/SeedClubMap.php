<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeedClubMap extends Command
{
    // Название команды для запуска в консоли
    protected $signature = 'app:seed-club-map';
    protected $description = 'Заполняет таблицу computers координатами из старого ClubMap.vue';

    public function handle()
    {
        $this->info('Начинаем заполнение карты клуба...');

        // 1. Создаем тестовый клуб (т.к. у нас есть foreignId('club_id'))
        // Предполагается, что у вас есть таблица clubs. Если нет - создайте миграцию для нее.
        $clubId = DB::table('clubs')->insertGetId([
            'name' => 'Main Reactor Club',
            'slug' => 'reactor-moscow',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Очищаем таблицу перед заполнением (опционально)
        DB::table('computers')->truncate();

        $pcs = [];

        // Вспомогательная функция для добавления пачки компов
        $addPcs = function(&$pcs, $ids, $startX, $startY, $stepX, $stepY, $type, $clubId) {
            foreach ($ids as $index => $id) {
                $pcs[] = [
                    'id' => $id, // Сохраняем оригинальные ID 1-42
                    'club_id' => $clubId,
                    'name' => sprintf("%02d", $id), // "01", "02" и т.д.
                    'x' => $startX + ($index * $stepX),
                    'y' => $startY + ($index * $stepY),
                    'type' => $type,
                    'status' => 'available',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        };

        // Переводим твои старые массивы в PHP:
        $addPcs($pcs, [1,2,3,4,5], 84, 13, 0, 5, 'pro', $clubId);
        $addPcs($pcs, [16,17,18,19,20], 42.5, 2.2, 5, 0, 'bootcamp', $clubId);
        $addPcs($pcs, [11,12,13,14,15], 41.5, 10, 0, 5, 'bootcamp', $clubId);
        $addPcs($pcs, [6,7,8,9,10], 62.3, 10, 0, 5, 'bootcamp', $clubId);
        $addPcs($pcs, [21,22], 2.2, 69, 0, 7.5, 'duo', $clubId);
        $addPcs($pcs, [23,24,25], 2.2, 84.3, 0, 5.8, 'trio', $clubId);
        $addPcs($pcs, [26,27], 2.2, 104.3, 0, 7.5, 'duo', $clubId);
        $addPcs($pcs, [28,29,30,31,32], 23.5, 71.5, 0, 9.8, 'standard', $clubId);
        $addPcs($pcs, [33], 66.8, 74.8, 0, 0, 'standard', $clubId); // Одиночный
        $addPcs($pcs, [34,35], 66.8, 83.5, 0, 7.8, 'duo', $clubId);
        $addPcs($pcs, [36,37], 66.8, 98.4, 0, 7.8, 'duo', $clubId);
        $addPcs($pcs, [38,39,40], 93, 74.5, 0, 6.2, 'trio', $clubId);
        $addPcs($pcs, [41,42], 93, 94, 0, 6.5, 'duo', $clubId);

        // 3. Записываем всё в базу данных
        DB::table('computers')->insert($pcs);

        $this->info('Успешно добавлено ' . count($pcs) . ' компьютеров!');
    }
}
