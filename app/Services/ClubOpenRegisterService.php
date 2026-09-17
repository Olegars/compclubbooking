<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Club;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClubOpenRegisterService
{
    /**
     * @param  array{name:string, city:string, network_name?:?string, address?:?string, contact?:?string, website?:?string, admin_name:string, email:string, password:string}  $data
     */
    public function register(array $data): Admin
    {
        return DB::transaction(function () use ($data) {
            $club = Club::query()->create([
                'name' => trim((string) $data['name']),
                'slug' => $this->uniqueSlug((string) $data['name']),
                'type' => 'club',
                'source' => Club::SOURCE_OPEN,
                'city' => trim((string) $data['city']),
                'network_name' => $this->nullable($data['network_name'] ?? null),
                'address' => $this->nullable($data['address'] ?? null),
                'contact' => $this->nullable($data['contact'] ?? null),
                'website' => $this->nullable($data['website'] ?? null),
                'tournament_open' => true,
            ]);

            return Admin::query()->create([
                'name' => trim((string) $data['admin_name']),
                'email' => strtolower(trim((string) $data['email'])),
                'password' => $data['password'],
                'role' => Admin::ROLE_SUPERVISOR,
                'club_id' => $club->id,
                'is_official_employee' => false,
                'base_rate' => 0,
                'pay_type' => 'monthly',
                'employment_pending' => false,
                'hired_at' => now(),
            ]);
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'club';
        $slug = $base;
        $n = 0;
        while (Club::query()->where('slug', $slug)->exists()) {
            $n++;
            $slug = $base.'-'.$n;
        }

        return $slug;
    }

    private function nullable(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
