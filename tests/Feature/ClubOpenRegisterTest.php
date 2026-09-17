<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Club;
use App\Models\Game;
use App\Support\AdminLocation;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClubOpenRegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_anyone_can_register_an_independent_club(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/clubs/join', [
                'name' => 'Neon Arena',
                'city' => 'Казань',
                'network_name' => '',
                'admin_name' => 'Марат',
                'email' => 'marat@neon.test',
                'password' => 'secret12',
                'password_confirmation' => 'secret12',
            ])
            ->assertRedirect('/admin/tournaments');

        $club = Club::query()->where('slug', 'neon-arena')->first();
        $this->assertNotNull($club);
        $this->assertSame(Club::SOURCE_OPEN, $club->source);
        $this->assertSame('Казань', $club->city);
        $this->assertTrue($club->tournament_open);
        $this->assertTrue($club->isOpenPartner());

        $this->assertDatabaseHas('admins', [
            'email' => 'marat@neon.test',
            'role' => 'supervisor',
            'club_id' => $club->id,
            'employment_pending' => false,
        ]);
    }

    public function test_open_club_is_a_tournament_opponent_not_an_owner_location(): void
    {
        $home = Club::query()->create([
            'name' => '0451',
            'slug' => 'home-0451',
            'type' => 'club',
            'source' => Club::SOURCE_LOCATION,
        ]);
        $guest = Club::query()->create([
            'name' => 'Street LAN',
            'slug' => 'street-lan',
            'type' => 'club',
            'source' => Club::SOURCE_OPEN,
            'city' => 'Уфа',
            'tournament_open' => true,
        ]);
        Game::query()->create([
            'title' => 'Counter-Strike 2',
            'platform' => 'Steam',
        ]);

        $owner = Admin::query()->create([
            'name' => 'Owner',
            'email' => 'owner@home.test',
            'password' => 'password',
            'role' => 'owner',
            'club_id' => $home->id,
        ]);
        $hostAdmin = Admin::query()->create([
            'name' => 'Host Super',
            'email' => 'host@home.test',
            'password' => 'password',
            'role' => 'supervisor',
            'club_id' => $home->id,
        ]);

        $this->actingAs($owner, 'admin')
            ->get('/admin/store/locations')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Store/Locations')
                ->has('locations', 1)
                ->where('locations.0.id', $home->id)
            );

        $this->assertFalse(AdminLocation::switch($guest->id, $owner));
        $ids = collect(AdminLocation::listForOwner($owner))->pluck('id')->all();
        $this->assertSame([$home->id], $ids);

        $this->actingAs($hostAdmin, 'admin')
            ->get('/admin/tournaments')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Tournaments')
                ->has('clubs', 1)
                ->where('clubs.0.id', $guest->id)
                ->where('clubs.0.city', 'Уфа')
            );
    }

    public function test_join_page_lists_registered_clubs(): void
    {
        Club::query()->create([
            'name' => 'Alpha',
            'slug' => 'alpha-open',
            'type' => 'club',
            'source' => Club::SOURCE_OPEN,
            'city' => 'Пермь',
            'tournament_open' => true,
        ]);

        $this->get('/clubs/join')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Club/Join')
                ->has('clubs', 1)
                ->where('clubs.0.name', 'Alpha')
            );
    }
}
