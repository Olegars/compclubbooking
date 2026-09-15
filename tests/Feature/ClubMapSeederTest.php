<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Club;
use App\Models\Computer;
use App\Models\Space;
use Database\Seeders\AdminSeeder;
use Database\Seeders\ClubMapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClubMapSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_kosino_map_owner_and_club_admin_without_computers(): void
    {
        $this->seed([ClubMapSeeder::class, AdminSeeder::class]);

        $club = Club::query()->first();
        $this->assertNotNull($club);
        $this->assertSame('0451 KOSINO', $club->name);
        $this->assertCount(30, $club->map_config['zoneRects'] ?? []);
        $this->assertSame(30, Space::query()->where('club_id', $club->id)->count());
        $this->assertSame(0, Computer::query()->count());

        $this->assertDatabaseHas('admins', [
            'email' => 'boss@0451.space',
            'role' => 'owner',
        ]);
        $this->assertDatabaseHas('admins', [
            'email' => 'admin@0451.space',
            'role' => 'admin',
            'club_id' => $club->id,
        ]);
        $this->assertSame(2, Admin::query()->count());
        $this->assertTrue(password_verify('123', Admin::query()->where('email', 'boss@0451.space')->value('password')));
    }
}
