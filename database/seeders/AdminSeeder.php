<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\Club;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $club = Club::query()->orderBy('id')->first();

        $accounts = [
            [
                'email' => 'boss@0451.space',
                'name' => 'владелец',
                'role' => 'owner',
                'club_id' => null,
                'is_official_employee' => false,
                'base_rate' => null,
                'pay_type' => null,
                'password' => '123',
            ],
            [
                'email' => 'admin@0451.space',
                'name' => 'администратор',
                'role' => 'admin',
                'club_id' => $club?->id,
                'is_official_employee' => true,
                'base_rate' => 2000.00,
                'pay_type' => 'shift',
                'password' => '123',
            ],
        ];

        foreach ($accounts as $account) {
            $admin = Admin::query()->where('email', $account['email'])->first()
                ?? Admin::query()->where('role', $account['role'])->first();

            if ($admin) {
                $admin->fill($account)->save();
            } else {
                Admin::query()->create($account);
            }
        }

        Admin::query()->whereIn('email', [
            'intern@0451.space',
            'super@0451.space',
            'store@0451.space',
            'build@0451.space',
            'senior-store@0451.space',
            'admin@reactor.club',
            'intern@reactor.club',
            'super@reactor.club',
            'boss@reactor.club',
            'store@reactor.club',
            'build@reactor.club',
            'senior-store@reactor.club',
        ])->delete();
    }
}
