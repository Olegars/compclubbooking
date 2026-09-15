<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Game;
use App\Models\QuickApp;
use Database\Seeders\ClubMapSeeder;
use Database\Seeders\GameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_catalog_with_posters_and_quick_apps(): void
    {
        $this->seed([ClubMapSeeder::class, GameSeeder::class]);

        $this->assertGreaterThanOrEqual(50, Game::query()->count());
        $this->assertGreaterThanOrEqual(10, QuickApp::query()->count());

        $cs = Game::query()->where('title', 'Counter-Strike 2')->first();
        $this->assertNotNull($cs);
        $this->assertNotEmpty($cs->poster);
        $this->assertStringContainsString('730', $cs->poster);
        $this->assertTrue($cs->poster === 'games/posters/counter-strike-2.jpg'
            || str_starts_with((string) $cs->poster, 'http'));

        $this->assertDatabaseHas('club_games', [
            'club_id' => Club::query()->value('id'),
            'game_id' => $cs->id,
        ]);
        $this->assertDatabaseHas('quick_apps', ['title' => 'Discord']);
        $this->assertDatabaseHas('games', ['title' => 'Valorant', 'platform' => 'Riot']);
    }
}
