<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClubSeeder extends Seeder
{
    public function run(): void
    {
        $name = 'REACTOR PROTOCOL';

        DB::table('clubs')->updateOrInsert(
            ['id' => 1],
            [
                'name' => $name,
                'slug' => Str::slug($name),
                'type' => 'both',
                'address' => 'Sector 7, Moscow',
                'map_config' => json_encode([
                    'viewbox' => '-10 -10 120 200',
                    'walls' => [],
                    'zoneRects' => [],
                    'labels' => [],
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}
