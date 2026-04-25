<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Admin;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run()
    {
        // 1. Линейный админ (Базовый доступ: посадка, касса, маркет)
        Admin::updateOrCreate(
            ['email' => 'admin@reactor.club'],
            [
                'name' => 'Operator_01',
                'password' => Hash::make('123'),
                'role' => 'admin', // <-- Поменяли с operator на admin
                'is_official_employee' => true,
                'base_rate' => 2000.00, // 2000 руб за смену
                'pay_type' => 'shift'
            ]
        );

        // 2. Старший админ / Супервизор (Доступ к складу инвентаризации и верификации отзывов)
        Admin::updateOrCreate(
            ['email' => 'super@reactor.club'],
            [
                'name' => 'Shift Lead',
                'password' => Hash::make('123'),
                'role' => 'supervisor',
                'is_official_employee' => true,
                'base_rate' => 3000.00, // Ставка повыше
                'pay_type' => 'shift'
            ]
        );

        // 3. БОСС / Владелец (МАКСИМАЛЬНЫЙ ДОСТУП - GOD MODE)
        Admin::updateOrCreate(
            ['email' => 'boss@reactor.club'],
            [
                'name' => 'REACTOR FOUNDER',
                'password' => Hash::make('123'),
                'role' => 'owner', // <-- ИМЕННО ЭТА РОЛЬ ОТКРЫВАЕТ НАЛОГИ И ЗАРПЛАТЫ
                'is_official_employee' => false, // Владелец - ИП, он не получает зарплату по ТК РФ
                'base_rate' => null,
                'pay_type' => null
            ]
        );
    }
}
