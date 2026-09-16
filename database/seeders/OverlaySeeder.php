<?php

namespace Database\Seeders;

use App\Models\Overlay;
use Illuminate\Database\Seeder;

class OverlaySeeder extends Seeder
{
    public function run(): void
    {
        Overlay::ensureDefaultSlots();
    }
}
