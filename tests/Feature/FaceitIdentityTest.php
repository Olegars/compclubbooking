<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Booking;
use App\Models\Club;
use App\Models\Computer;
use App\Models\FaceitIdentity;
use App\Models\FaceitMatch;
use App\Models\Game;
use App\Models\User;
use App\Services\ClubFeatureService;
use App\Services\Faceit\FaceitIdentityService;
use App\Services\Faceit\FaceitRateLimited;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FaceitIdentityTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->club = Club::create(['name' => 'FACEIT Club', 'slug' => 'faceit-club']);
        $this->user = User::create([
            'name' => 'Гость',
            'phone' => '79005550101',
            'email' => 'faceit@club.test',
            'password' => 'password',
        ]);
        config([
            'services.faceit.client_id' => 'cid',
            'services.faceit.client_secret' => 'sec',
            'services.faceit.api_key' => 'server-key',
            'services.faceit.webhook_secret' => 'whsec',
        ]);
    }

    public function test_feature_off_hides_the_block_and_does_not_call_faceit(): void
    {
        Http::preventStrayRequests();
        $pc = $this->pc();
        $booking = $this->seat($this->user, $pc);

        $this->actingAs($this->user)->get('/account/profile')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('faceit.mode', 'off'));

        $this->getJson('/api/shell/balance?terminal_id='.$pc->id.'&booking_id='.$booking->id)
            ->assertOk()
            ->assertJsonPath('faceit', null);

        $cols = Schema::getColumnListing('faceit_identities');
        $this->assertNotContains('access_token', $cols);
        $this->assertNotContains('refresh_token', $cols);
    }

    public function test_second_account_cannot_claim_the_same_player_and_tokens_are_not_stored(): void
    {
        $this->enable('identity');
        $this->fakeFaceit('pid-1', 8, 2100);

        $this->actingAs($this->user)->get('/account/faceit/redirect')->assertRedirect();
        $state = (string) session('faceit_oauth_state');
        $this->actingAs($this->user)
            ->get('/account/faceit/callback?code=abc&state='.$state)
            ->assertRedirect(route('profile.edit'));

        $this->assertDatabaseHas('faceit_identities', [
            'user_id' => $this->user->id,
            'faceit_player_id' => 'pid-1',
            'elo' => 2100,
            'skill_level' => 8,
        ]);

        $other = User::create([
            'name' => 'Второй',
            'phone' => '79005550102',
            'email' => 'faceit2@club.test',
            'password' => 'password',
        ]);
        $this->actingAs($other)->get('/account/faceit/redirect')->assertRedirect();
        $state2 = (string) session('faceit_oauth_state');
        $this->actingAs($other)
            ->get('/account/faceit/callback?code=abc&state='.$state2)
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('error');
        $this->assertSame(1, FaceitIdentity::query()->count());

        $this->actingAs($this->user)->post('/account/faceit/unlink')->assertRedirect();
        $this->assertSame(0, FaceitIdentity::query()->count());
    }

    public function test_rate_limit_keeps_cached_elo_and_shell_poll_does_not_call_faceit(): void
    {
        $this->enable('identity');
        $row = FaceitIdentity::query()->create([
            'user_id' => $this->user->id,
            'faceit_player_id' => 'pid-1',
            'nickname' => 'Nick',
            'skill_level' => 8,
            'elo' => 2100,
            'game_id' => 'cs2',
            'synced_at' => now()->subHour(),
        ]);
        Http::fake(['*' => Http::response([], 429)]);
        try {
            app(FaceitIdentityService::class)->sync($row);
            $this->fail('ожидали 429');
        } catch (FaceitRateLimited) {
        }
        $this->assertSame(2100, $row->fresh()->elo);

        Http::preventStrayRequests();
        $pc = $this->pc();
        $booking = $this->seat($this->user, $pc);
        $this->getJson('/api/shell/balance?terminal_id='.$pc->id.'&booking_id='.$booking->id)
            ->assertOk()
            ->assertJsonPath('faceit.linked', true)
            ->assertJsonPath('faceit.elo', 2100)
            ->assertJsonPath('faceit.skill_level', 8);
    }

    public function test_faceit_shortcut_does_not_take_a_club_steam_account(): void
    {
        $pc = $this->pc();
        $game = Game::create([
            'title' => 'FACEIT',
            'platform' => 'steam',
            'exe_path' => 'C:\\Program Files\\FACEIT\\FACEIT.exe',
        ]);

        $this->postJson('/api/shell/games/take-account', [
            'game_id' => $game->id,
            'terminal_id' => $pc->id,
        ])->assertOk()->assertJsonPath('skip_club_steam', true);
    }

    public function test_webhook_is_idempotent_and_clan_war_hides_the_overlay(): void
    {
        $this->enable('hub');
        $pc = $this->pc();
        $this->seat($this->user, $pc);
        FaceitIdentity::query()->create([
            'user_id' => $this->user->id,
            'faceit_player_id' => 'pid-1',
            'nickname' => 'Nick',
            'skill_level' => 6,
            'elo' => 1800,
            'game_id' => 'cs2',
            'synced_at' => now(),
        ]);
        $body = [
            'event_id' => 'evt-1',
            'event' => 'match_status_ready',
            'payload' => [
                'id' => 'match-1',
                'map' => 'de_mirage',
                'teams' => [
                    ['name' => 'A', 'roster' => [['player_id' => 'pid-1', 'elo' => 1800]]],
                    ['name' => 'B', 'roster' => [['player_id' => 'pid-2', 'elo' => 1700]]],
                ],
            ],
        ];
        $this->postJson('/api/faceit/webhook', $body)->assertForbidden();
        $this->withHeader('X-Faceit-Webhook-Secret', 'whsec')->postJson('/api/faceit/webhook', $body)->assertOk();
        $this->withHeader('X-Faceit-Webhook-Secret', 'whsec')->postJson('/api/faceit/webhook', $body)
            ->assertOk()
            ->assertJsonPath('duplicate', true);
        $this->assertSame(1, FaceitMatch::query()->count());

        $this->getJson('/api/shell/overlays?terminal_id='.$pc->id)
            ->assertOk()
            ->assertJsonPath('faceit_match.id', 'match-1')
            ->assertJsonPath('faceit_match.map', 'de_mirage');

        $this->assertNull(app(FaceitIdentityService::class)->overlayUnlessClanWar($pc, true));

        $admin = Admin::query()->create([
            'name' => 'supervisor',
            'email' => 'faceit-admin@club.test',
            'password' => 'password',
            'role' => 'supervisor',
            'club_id' => $this->club->id,
            'base_rate' => 2000,
            'pay_type' => 'shift',
            'employment_pending' => false,
        ]);
        $this->actingAs($admin, 'admin')->get('/admin/faceit')->assertOk();
    }

    private function enable(string $mode): void
    {
        app(ClubFeatureService::class)->save($this->club->id, 'faceit', true, [
            'mode' => $mode,
            'hub_id' => 'hub-1',
            'organizer_id' => '',
            'game_id' => 'cs2',
            'sync_seated_minutes' => 15,
        ]);
    }

    private function fakeFaceit(string $playerId, int $level, int $elo): void
    {
        Http::fake([
            'https://api.faceit.com/auth/v1/oauth/token' => Http::response([
                'access_token' => 'guest-access',
                'refresh_token' => 'guest-refresh',
            ]),
            'https://api.faceit.com/auth/v1/userinfo' => Http::response([
                'sub' => $playerId,
                'steam_id_64' => '76561198000000001',
            ]),
            'https://open.faceit.com/data/v4/players/'.$playerId => Http::response([
                'nickname' => 'Nick',
                'avatar' => 'https://cdn.example/a.png',
                'games' => ['cs2' => ['skill_level' => $level, 'faceit_elo' => $elo]],
            ]),
            'https://open.faceit.com/data/v4/players/'.$playerId.'/bans' => Http::response(['items' => []]),
            'https://open.faceit.com/data/v4/players/'.$playerId.'/stats/cs2' => Http::response([
                'lifetime' => ['Average K/D Ratio' => '1.2', 'Win Rate %' => '55'],
            ]),
        ]);
    }

    private function pc(): Computer
    {
        return Computer::create([
            'club_id' => $this->club->id,
            'name' => 'PC-01',
            'status' => 'busy',
            'kind' => 'pc',
        ]);
    }

    private function seat(User $user, Computer $pc): Booking
    {
        $start = CarbonImmutable::now()->subMinutes(10);
        $end = CarbonImmutable::now()->addHour();
        $local = $start->timezone(config('app.timezone'));

        return Booking::create([
            'user_id' => $user->id,
            'computer_id' => $pc->id,
            'pc_ids' => [(string) $pc->id],
            'date' => $local->toDateString(),
            'start_time' => $local->hour + ($local->minute / 60),
            'duration' => 1,
            'price' => 200,
            'price_minor' => 20000,
            'status' => 'active',
            'pin_code' => '1111',
            'starts_at' => $start,
            'ends_at' => $end,
            'actual_started_at' => $start,
        ]);
    }
}
