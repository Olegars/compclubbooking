<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Club;
use App\Models\Game;
use App\Models\Tournament;
use App\Models\TournamentChallenge;
use App\Models\User;
use App\Models\Wallet;
use App\Services\TournamentService;
use App\Support\AdminAlerts;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TournamentChallengeTest extends TestCase
{
    use RefreshDatabase;

    private Club $alpha;

    private Club $beta;

    private Club $gamma;

    private Game $cs;

    private Admin $alphaAdmin;

    private Admin $betaAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = Club::create(['name' => 'Alpha', 'slug' => 'alpha-cup', 'type' => 'club']);
        $this->beta = Club::create(['name' => 'Beta', 'slug' => 'beta-cup', 'type' => 'club']);
        $this->gamma = Club::create(['name' => 'Gamma', 'slug' => 'gamma-cup', 'type' => 'club']);
        $this->cs = Game::create([
            'title' => 'Counter-Strike 2',
            'platform' => 'Steam',
            'exe_path' => 'D:\\Games\\cs2.exe',
        ]);
        $this->alphaAdmin = $this->supervisor($this->alpha, 'alpha.cup');
        $this->betaAdmin = $this->supervisor($this->beta, 'beta.cup');
    }

    public function test_two_clubs_agree_on_terms_and_then_roster_is_split(): void
    {
        $payload = $this->terms([
            'guest_club_id' => $this->beta->id,
            'comment' => 'BO3, только премьер',
        ]);

        $this->actingAs($this->alphaAdmin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/admin/tournaments/challenges', $payload)
            ->assertRedirect();

        $challenge = TournamentChallenge::query()->first();
        $this->assertNotNull($challenge);
        $this->assertSame('pending', $challenge->status);
        $this->assertSame($this->beta->id, (int) $challenge->waiting_club_id);
        $this->assertSame(5, (int) $challenge->roster_size);

        $this->actingAs($this->betaAdmin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->get('/admin/tournaments')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Tournaments')
                ->has('challenges', 1)
                ->where('challenges.0.incoming', true)
                ->where('challenges.0.name', 'Alpha vs Beta CS')
            );

        $this->assertSame(1, AdminAlerts::counts()['tournament_inbox']);

        $this->actingAs($this->betaAdmin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/admin/tournaments/challenges/'.$challenge->id.'/counter', $this->terms([
                'roster_size' => 3,
                'prize_first' => 5000,
                'comment' => 'Трое с клуба, банк больше',
            ]))
            ->assertRedirect();

        $challenge = $challenge->fresh();
        $this->assertSame($this->alpha->id, (int) $challenge->waiting_club_id);
        $this->assertSame(3, (int) $challenge->roster_size);
        $this->assertSame(500000, (int) $challenge->prize_first_minor);

        $this->actingAs($this->alphaAdmin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/admin/tournaments/challenges/'.$challenge->id.'/accept')
            ->assertRedirect();

        $challenge = $challenge->fresh();
        $this->assertSame('agreed', $challenge->status);
        $tournament = Tournament::query()->find($challenge->tournament_id);
        $this->assertNotNull($tournament);
        $this->assertSame($this->alpha->id, (int) $tournament->club_id);
        $this->assertSame($this->beta->id, (int) $tournament->opponent_club_id);
        $this->assertSame(3, (int) $tournament->roster_size);
        $this->assertSame('planned', $tournament->status);

        $alphaPlayers = [$this->guest('A1'), $this->guest('A2'), $this->guest('A3')];
        $betaPlayers = [$this->guest('B1'), $this->guest('B2'), $this->guest('B3')];
        $service = app(TournamentService::class);
        foreach ($alphaPlayers as $user) {
            $service->register($tournament, $user, null, $this->alpha->id);
        }
        foreach ($betaPlayers as $user) {
            $service->register($tournament, $user, null, $this->beta->id);
        }

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->register($tournament, $this->guest('A4'), null, $this->alpha->id);
    }

    public function test_third_club_does_not_see_foreign_challenge(): void
    {
        $this->actingAs($this->alphaAdmin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/admin/tournaments/challenges', $this->terms([
                'guest_club_id' => $this->beta->id,
            ]))
            ->assertRedirect();

        $gammaAdmin = $this->supervisor($this->gamma, 'gamma.cup');
        $this->actingAs($gammaAdmin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->get('/admin/tournaments')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Tournaments')
                ->has('challenges', 0)
                ->has('tournaments', 0)
            );

        $challenge = TournamentChallenge::query()->first();
        $this->actingAs($gammaAdmin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/admin/tournaments/challenges/'.$challenge->id.'/accept')
            ->assertNotFound();
    }

    public function test_cannot_accept_out_of_turn(): void
    {
        $this->actingAs($this->alphaAdmin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/admin/tournaments/challenges', $this->terms([
                'guest_club_id' => $this->beta->id,
            ]))
            ->assertRedirect();

        $challenge = TournamentChallenge::query()->first();
        $this->actingAs($this->alphaAdmin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/admin/tournaments/challenges/'.$challenge->id.'/accept')
            ->assertSessionHasErrors('challenge');
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function terms(array $extra = []): array
    {
        return array_merge([
            'name' => 'Alpha vs Beta CS',
            'game_id' => $this->cs->id,
            'format' => 'single_elim',
            'start_at' => now()->addDay()->format('Y-m-d\\TH:i'),
            'end_at' => now()->addDay()->addHours(6)->format('Y-m-d\\TH:i'),
            'roster_size' => 5,
            'entry_fee' => 0,
            'prize_first' => 3000,
            'prize_second' => 1500,
            'prize_third' => 500,
            'prize_funding' => 'split',
            'venue' => 'host',
            'lock_games' => true,
            'rules' => 'BO3, FACEIT',
        ], $extra);
    }

    private function supervisor(Club $club, string $emailPrefix): Admin
    {
        return Admin::query()->create([
            'name' => $club->name.' Super',
            'email' => $emailPrefix.'@cup.test',
            'password' => 'password',
            'role' => 'supervisor',
            'club_id' => $club->id,
            'pay_type' => 'shift',
        ]);
    }

    private function guest(string $tag): User
    {
        $user = User::create([
            'name' => 'Player '.$tag,
            'phone' => '+7999'.str_pad((string) random_int(1000000, 9999999), 7, '0'),
            'email' => strtolower($tag).uniqid().'@cup.test',
            'password' => 'password',
        ]);
        Wallet::create([
            'user_id' => $user->id,
            'deposit_balance' => 0,
            'bonus_balance' => 0,
            'total_spent' => 0,
        ]);

        return $user;
    }
}
