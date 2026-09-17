<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ClubMapSeeder::class,
            AdminSeeder::class,
            GameSeeder::class,
            ProductSeeder::class,
            OverlaySeeder::class,
        ]);
    }
}
