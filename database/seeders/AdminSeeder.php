<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Admin;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run()
    {
        // Обычный админ
        Admin::updateOrCreate(
            ['email' => 'admin@reactor.club'],
            ['name' => 'Operator_01', 'password' => Hash::make('123'), 'role' => 'operator']
        );

        // СУПЕРВИЗОР (Владелец)
        Admin::updateOrCreate(
            ['email' => 'boss@reactor.club'],
            ['name' => 'SUPERVISOR', 'password' => Hash::make('boss_code_777'), 'role' => 'supervisor']
        );
    }
}
