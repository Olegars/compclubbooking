<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Computer;
use App\Models\Game;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Models\Wallet;
use App\Services\TournamentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TournamentTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_elim_pays_deposit_and_locks_shell_catalog(): void
    {
        $club = Club::create(['name' => 'Arena Club', 'slug' => 'arena-club']);
        $cs = Game::create([
            'title' => 'Counter-Strike 2',
            'platform' => 'Steam',
            'exe_path' => 'D:\\Games\\cs2.exe',
        ]);
        Game::create([
            'title' => 'Dota 2',
            'platform' => 'Steam',
            'exe_path' => 'D:\\Games\\dota2.exe',
        ]);
        $pc = Computer::create([
            'club_id' => $club->id,
            'name' => 'PC-11',
            'status' => 'available',
            'kind' => 'pc',
        ]);

        $users = [];
        foreach (['A', 'B', 'C', 'D'] as $i => $name) {
            $users[$name] = User::create([
                'name' => 'Player '.$name,
                'phone' => '+7999000000'.($i + 1),
                'email' => strtolower($name).'@arena.test',
                'password' => 'password',
            ]);
            Wallet::create([
                'user_id' => $users[$name]->id,
                'deposit_balance' => 0,
                'bonus_balance' => 0,
                'total_spent' => 0,
            ]);
        }

        $tournament = Tournament::query()->create([
            'club_id' => $club->id,
            'name' => 'Friday CS',
            'game_id' => $cs->id,
            'start_at' => now(),
            'end_at' => now()->addHours(4),
            'status' => 'planned',
            'format' => 'single_elim',
            'lock_games' => true,
            'prize_first_minor' => 30000,
            'prize_second_minor' => 20000,
            'prize_third_minor' => 10000,
        ]);
        $tournament->computers()->sync([$pc->id]);

        $service = app(TournamentService::class);
        foreach ($users as $user) {
            $service->register($tournament, $user);
        }
        $service->generateBracket($tournament);
        $tournament->update(['status' => 'active']);

        $this->assertSame(3, TournamentMatch::query()->where('tournament_id', $tournament->id)->count());

        $locked = $this->getJson('/api/shell/games?terminal_id='.$pc->id);
        $locked->assertOk()->assertJsonPath('status', 'success');
        $this->assertCount(1, $locked->json('games'));
        $this->assertSame($cs->id, $locked->json('games.0.id'));

        $open = $this->getJson('/api/shell/games');
        $open->assertOk();
        $this->assertGreaterThanOrEqual(2, count($open->json('games')));

        $round1 = TournamentMatch::query()
            ->where('tournament_id', $tournament->id)
            ->where('round', 1)
            ->orderBy('slot')
            ->get();
        $this->assertCount(2, $round1);
        $service->reportScore($round1[0], 2, 0);
        $service->reportScore($round1[1], 2, 0);

        $final = TournamentMatch::query()
            ->where('tournament_id', $tournament->id)
            ->where('round', 2)
            ->first();
        $this->assertNotNull($final);
        $this->assertSame(TournamentMatch::STATUS_READY, $final->fresh()->status);
        $service->reportScore($final->fresh(), 2, 1);
        $service->finishAndPay($tournament->fresh());

        $winner = $users['A']->fresh();
        $second = $users['C']->fresh();
        $this->assertSame(300.0, (float) $winner->wallet->deposit_balance);
        $this->assertSame(200.0, (float) $second->wallet->deposit_balance);
        $this->assertEqualsWithDelta(50.0, (float) $users['B']->fresh()->wallet->deposit_balance, 0.01);
        $this->assertEqualsWithDelta(50.0, (float) $users['D']->fresh()->wallet->deposit_balance, 0.01);
        $this->assertSame(1, $tournament->fresh()->players()->where('placement', 1)->count());
        $this->assertNotNull($tournament->fresh()->prizes_paid_at);

        $unlocked = $this->getJson('/api/shell/games?terminal_id='.$pc->id);
        $unlocked->assertOk();
        $this->assertGreaterThanOrEqual(2, count($unlocked->json('games')));
    }
}
