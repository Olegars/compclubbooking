<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ComputerSeeder extends Seeder
{
    public function run()
    {
        // Предполагаем, что у нас ID клуба = 1
        $clubId = 1;

        $computers = [];

        // 1. Стандартная зона (15 ПК)
        for ($i = 1; $i <= 15; $i++) {
            $computers[] = [
                'club_id' => $clubId,
                'name' => str_pad($i, 2, '0', STR_PAD_LEFT), // "01", "02"...
                'x' => 10 + ($i * 6),
                'y' => 20,
                'type' => 'standard',
                'status' => 'available',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // 2. PRO Зона (5 ПК)
        for ($i = 1; $i <= 5; $i++) {
            $computers[] = [
                'club_id' => $clubId,
                'name' => 'PRO-' . $i,
                'x' => 10 + ($i * 10),
                'y' => 60,
                'type' => 'pro',
                'status' => 'available',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // 3. VIP Бутка (2 ПК)
        for ($i = 1; $i <= 2; $i++) {
            $computers[] = [
                'club_id' => $clubId,
                'name' => 'VIP-' . $i,
                'x' => 100 + ($i * 10),
                'y' => 100,
                'type' => 'vip',
                'status' => 'available',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('computers')->insert($computers);
    }
}
