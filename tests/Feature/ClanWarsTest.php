<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Booking;
use App\Models\ClanPlayerRating;
use App\Models\ClanRating;
use App\Models\ClanWar;
use App\Models\Club;
use App\Models\Computer;
use App\Models\Overlay;
use App\Models\Space;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Zone;
use App\Services\ClanWarService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClanWarsTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    private Club $other;

    private Zone $bootcamp;

    private Zone $singl;

    private Computer $bootPc;

    private Computer $stdPc;

    private Computer $tv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->club = Club::create(['name' => 'Alpha', 'slug' => 'alpha-cw', 'type' => 'club']);
        $this->other = Club::create(['name' => 'Beta', 'slug' => 'beta-cw', 'type' => 'club']);
        $this->bootcamp = Zone::create(['name' => 'Bootcamp', 'slug' => 'bootcamp', 'color' => '#a855f7']);
        $this->singl = Zone::create(['name' => 'Singl', 'slug' => 'singl', 'color' => '#22c55e']);
        $bootSpace = Space::create([
            'club_id' => $this->club->id,
            'zone_id' => $this->bootcamp->id,
            'name' => 'Boot room',
        ]);
        $stdSpace = Space::create([
            'club_id' => $this->club->id,
            'zone_id' => $this->singl->id,
            'name' => 'Singl room',
        ]);
        $this->bootPc = $this->pc('PC-B1', $this->club->id, $bootSpace->id);
        $this->stdPc = $this->pc('PC-S1', $this->club->id, $stdSpace->id);
        $this->tv = Computer::create([
            'club_id' => $this->club->id,
            'name' => 'TV-1',
            'status' => 'free',
            'kind' => 'tv',
        ]);
    }

    public function test_zone_war_sums_gsi_wins_and_paints_overlay(): void
    {
        $bootUser = $this->player('Boot', '79001112201');
        $stdUser = $this->player('Std', '79001112202');
        $this->seat($bootUser, $this->bootPc);
        $this->seat($stdUser, $this->stdPc);

        $war = app(ClanWarService::class)->create([
            'mode' => 'zone',
            'game' => 'cs2',
            'duration_minutes' => 60,
        ], $this->club->id);
        app(ClanWarService::class)->start($war);

        $this->postJson('/api/shell/gsi', [
            'terminal_id' => $this->bootPc->id,
            'event' => 'match_win',
            'game' => 'cs2',
            'match_id' => 'm1',
            'in_match' => true,
        ])->assertOk();
        $this->postJson('/api/shell/gsi', [
            'terminal_id' => $this->stdPc->id,
            'event' => 'round_win',
            'game' => 'cs2',
            'match_id' => 'm2',
            'round' => 4,
            'in_match' => true,
        ])->assertOk();
        $this->postJson('/api/shell/gsi', [
            'terminal_id' => $this->bootPc->id,
            'event' => 'match_win',
            'game' => 'cs2',
            'match_id' => 'm1',
            'in_match' => true,
        ])->assertOk();

        $war = $war->fresh();
        $this->assertSame(10, (int) $war->score_a);
        $this->assertSame(1, (int) $war->wins_a);
        $this->assertSame(1, (int) $war->score_b);
        $this->assertSame(1, (int) $war->rounds_b);

        Overlay::create([
            'block_position' => 'mid_left',
            'title' => 'DAT',
            'type' => 'text',
            'content' => ['layers' => []],
            'is_active' => true,
        ]);

        $this->getJson('/api/shell/overlays?terminal_id='.$this->tv->id)
            ->assertOk()
            ->assertJsonPath('clan_war.side_a.score', 10)
            ->assertJsonPath('clan_war.side_b.score', 1)
            ->assertJsonPath('data.mid_left.content.layers.0.role', 'clan_war');

        $this->getJson('/api/shell/clan-wars/live?terminal_id='.$this->tv->id)
            ->assertOk()
            ->assertJsonPath('clan_war.side_a.score', 10)
            ->assertJsonPath('clan_war.side_b.score', 1);
    }

    public function test_location_war_and_rating_in_cabinet(): void
    {
        $aUser = $this->player('LocA', '79001112211');
        $bUser = $this->player('LocB', '79001112212');
        $pcB = $this->pc('PC-BETA', $this->other->id, null);
        $this->seat($aUser, $this->bootPc);
        $this->seat($bUser, $pcB);

        $admin = Admin::create([
            'name' => 'CW Super',
            'email' => 'cw@test.local',
            'password' => 'password',
            'role' => 'supervisor',
            'club_id' => $this->club->id,
        ]);

        $this->actingAs($admin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/admin/clan-wars', [
                'mode' => 'location',
                'game' => 'any',
                'duration_minutes' => 30,
                'side_a_club_id' => $this->club->id,
                'side_b_club_id' => $this->other->id,
            ])
            ->assertRedirect();

        $war = ClanWar::query()->first();
        $this->assertNotNull($war);
        $this->actingAs($admin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->patch('/admin/clan-wars/'.$war->id.'/status', ['status' => 'live'])
            ->assertRedirect();

        $this->postJson('/api/shell/gsi', [
            'terminal_id' => $this->bootPc->id,
            'event' => 'match_win',
            'game' => 'dota',
            'match_id' => 'd1',
        ])->assertOk();

        $this->actingAs($admin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->patch('/admin/clan-wars/'.$war->id.'/status', ['status' => 'finished'])
            ->assertRedirect();

        $this->assertSame('a', $war->fresh()->winner_side);
        $this->assertGreaterThan(1000, (int) ClanRating::query()->where('faction_key', (string) $this->club->id)->value('rating'));
        $this->assertSame(1, (int) ClanPlayerRating::query()->where('user_id', $aUser->id)->value('wars_played'));

        $this->actingAs($aUser)
            ->get('/account/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('clan_wars.board')
                ->where('clan_wars.mine.0.points', 10));
    }

    public function test_wrong_zone_does_not_score(): void
    {
        $user = $this->player('TVGuy', '79001112221');
        $this->seat($user, $this->tv);
        $war = app(ClanWarService::class)->create(['mode' => 'zone', 'game' => 'any'], $this->club->id);
        app(ClanWarService::class)->start($war);

        $this->postJson('/api/shell/gsi', [
            'terminal_id' => $this->tv->id,
            'event' => 'match_win',
            'game' => 'cs2',
            'match_id' => 'x',
        ])->assertOk();

        $this->assertSame(0, (int) $war->fresh()->score_a);
        $this->assertSame(0, (int) $war->fresh()->score_b);
    }

    private function player(string $name, string $phone): User
    {
        $user = User::create([
            'name' => $name,
            'phone' => $phone,
            'email' => strtolower($name).'@cw.test',
            'password' => 'password',
        ]);
        Wallet::create(['user_id' => $user->id, 'deposit_balance' => 0, 'bonus_balance' => 0]);

        return $user;
    }

    private function pc(string $name, int $clubId, ?int $spaceId): Computer
    {
        return Computer::create([
            'club_id' => $clubId,
            'space_id' => $spaceId,
            'name' => $name,
            'status' => 'busy',
            'kind' => 'pc',
        ]);
    }

    private function seat(User $user, Computer $pc): Booking
    {
        $start = now()->subMinutes(10);

        return Booking::create([
            'user_id' => $user->id,
            'computer_id' => $pc->id,
            'pc_ids' => [(string) $pc->id],
            'date' => $start->toDateString(),
            'start_time' => $start->hour + ($start->minute / 60),
            'duration' => 2,
            'price' => 200,
            'price_minor' => 20000,
            'status' => 'active',
            'pin_code' => '1111',
            'starts_at' => $start,
            'ends_at' => now()->addHour(),
            'actual_started_at' => $start,
        ]);
    }
}
