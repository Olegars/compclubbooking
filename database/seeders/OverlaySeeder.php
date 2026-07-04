<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Overlay;

class OverlaySeeder extends Seeder
{
    public function run(): void
    {
        // Наши 6 позиций из QML шелла
        $positions = [
            'top_left', 'top_right',
            'mid_left', 'mid_right',
            'bottom_left', 'bottom_right'
        ];

        foreach ($positions as $pos) {
            // firstOrCreate создаст блок только если его еще нет в базе
            Overlay::firstOrCreate(
                ['block_position' => $pos],
                [
                    'title' => 'БЛОК ' . strtoupper($pos),
                    'type' => 'text',
                    'content' => ['text' => 'Добро пожаловать в REACTOR'],
                    'is_active' => true,
                ]
            );
        }
    }
}
