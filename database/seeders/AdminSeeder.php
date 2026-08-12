<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\Club;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $club = Club::query()->first();
        if (! $club) {
            $club = Club::query()->create([
                'name' => 'REACTOR PROTOCOL',
                'slug' => 'reactor-protocol',
                'type' => 'both',
                'address' => 'Sector 7, Moscow',
            ]);
        } else {
            $club->update(['type' => 'both']);
        }

        $password = Hash::make('123');

        $accounts = [
            [
                'email' => 'admin@0451.space',
                'legacy_emails' => ['admin@reactor.club'],
                'name' => 'администратор',
                'role' => 'admin',
                'club_id' => $club->id,
                'is_official_employee' => true,
                'base_rate' => 2000.00,
                'pay_type' => 'shift',
            ],
            [
                'email' => 'super@0451.space',
                'legacy_emails' => ['super@reactor.club'],
                'name' => 'управляющий',
                'role' => 'supervisor',
                'club_id' => $club->id,
                'is_official_employee' => true,
                'base_rate' => 3000.00,
                'pay_type' => 'shift',
            ],
            [
                'email' => 'boss@0451.space',
                'legacy_emails' => ['boss@reactor.club'],
                'name' => 'владелец',
                'role' => 'owner',
                'club_id' => null,
                'is_official_employee' => false,
                'base_rate' => null,
                'pay_type' => null,
            ],
            [
                'email' => 'store@0451.space',
                'legacy_emails' => ['store@reactor.club'],
                'name' => 'менеджер магазина',
                'role' => 'store_manager',
                'club_id' => $club->id,
                'is_official_employee' => true,
                'base_rate' => 2500.00,
                'pay_type' => 'shift',
            ],
            [
                'email' => 'build@0451.space',
                'legacy_emails' => ['build@reactor.club'],
                'name' => 'сборщик',
                'role' => 'assembler',
                'club_id' => $club->id,
                'is_official_employee' => true,
                'base_rate' => 2200.00,
                'pay_type' => 'shift',
            ],
            [
                'email' => 'senior-store@0451.space',
                'legacy_emails' => ['senior-store@reactor.club'],
                'name' => 'старший менеджер',
                'role' => 'senior_manager',
                'club_id' => $club->id,
                'is_official_employee' => true,
                'base_rate' => 3500.00,
                'pay_type' => 'monthly',
            ],
        ];

        foreach ($accounts as $account) {
            $emails = array_values(array_unique(array_merge(
                [$account['email']],
                $account['legacy_emails']
            )));
            unset($account['legacy_emails']);

            // Сначала по новому/старому email, иначе — единственная запись с этой ролью
            $admin = Admin::query()->whereIn('email', $emails)->first()
                ?? Admin::query()->where('role', $account['role'])->first();

            $payload = array_merge($account, ['password' => $password]);

            if ($admin) {
                $admin->fill($payload)->save();
            } else {
                Admin::query()->create($payload);
            }
        }

        if (! $club->slug) {
            $club->update(['slug' => Str::slug($club->name)]);
        }
    }
}
