<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingGroup;
use App\Models\Club;
use App\Models\Computer;
use App\Models\LanBounty;
use App\Models\Order;
use App\Models\PartyEnergyPool;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use App\Services\LanLive\GhostCoachService;
use App\Services\LanLive\PartyEnergyPoolService;
use App\Services\LanLive\ShellGsiStore;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class LanLiveFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->club = Club::create(['name' => 'LAN Club', 'slug' => 'lan-live-club']);
    }

    public function test_deposit_bounty_settles_on_correlated_kill_and_death(): void
    {
        $poster = $this->player('Poster', '79001110001', 500);
        $target = $this->player('Target', '79001110002', 0);
        $hunter = $this->player('Hunter', '79001110003', 10);
        $pcPoster = $this->pc('PC-12');
        $pcTarget = $this->pc('PC-14');
        $pcHunter = $this->pc('PC-16');
        $bPoster = $this->activeSeat($poster, $pcPoster);
        $this->activeSeat($target, $pcTarget);
        $this->activeSeat($hunter, $pcHunter);

        $create = $this->postJson('/api/shell/bounties', [
            'terminal_id' => $pcPoster->id,
            'booking_id' => $bPoster->id,
            'target_computer_id' => $pcTarget->id,
            'kind' => 'frag',
            'game' => 'cs2',
            'weapon' => 'knife',
            'stake_type' => 'deposit',
            'stake_amount' => 150,
        ]);
        $create->assertOk()->assertJsonPath('status', 'success');
        $this->assertEquals(350.0, (float) $poster->fresh()->availableBalance());

        $this->postJson('/api/shell/gsi', [
            'terminal_id' => $pcHunter->id,
            'event' => 'kill',
            'game' => 'cs2',
            'weapon' => 'weapon_knife_karambit',
            'map' => 'de_dust2',
            'in_match' => true,
        ])->assertOk();

        $death = $this->postJson('/api/shell/gsi', [
            'terminal_id' => $pcTarget->id,
            'event' => 'death',
            'game' => 'cs2',
            'weapon' => 'weapon_knife_karambit',
            'map' => 'de_dust2',
            'in_match' => true,
        ]);
        $death->assertOk()->assertJsonPath('settled.prize.type', 'deposit');

        $this->assertSame(LanBounty::STATUS_WON, LanBounty::query()->first()->status);
        $this->assertEquals(160.0, (float) $hunter->fresh()->availableBalance());
    }

    public function test_product_bounty_prints_kitchen_order_for_winner(): void
    {
        $poster = $this->player('Poster2', '79001110011', 400);
        $target = $this->player('Target2', '79001110012', 0);
        $hunter = $this->player('Hunter2', '79001110013', 0);
        $pcPoster = $this->pc('PC-21');
        $pcTarget = $this->pc('PC-22');
        $pcHunter = $this->pc('PC-23');
        $bPoster = $this->activeSeat($poster, $pcPoster);
        $this->activeSeat($target, $pcTarget);
        $this->activeSeat($hunter, $pcHunter);
        $drink = Product::create([
            'name' => 'Red Bull',
            'category' => 'Напитки',
            'price' => 180,
            'stock' => 4,
            'is_active' => true,
            'requires_marking' => false,
        ]);

        $this->postJson('/api/shell/bounties', [
            'terminal_id' => $pcPoster->id,
            'booking_id' => $bPoster->id,
            'target_computer_id' => $pcTarget->id,
            'kind' => 'frag',
            'game' => 'cs2',
            'weapon' => 'any',
            'stake_type' => 'product',
            'product_id' => $drink->id,
        ])->assertOk();
        $this->assertSame(3, (int) $drink->fresh()->stock);

        $this->postJson('/api/shell/gsi', [
            'terminal_id' => $pcHunter->id,
            'event' => 'kill',
            'game' => 'cs2',
            'weapon' => 'weapon_ak47',
            'map' => 'de_mirage',
            'in_match' => true,
        ])->assertOk();
        $this->postJson('/api/shell/gsi', [
            'terminal_id' => $pcTarget->id,
            'event' => 'death',
            'game' => 'cs2',
            'map' => 'de_mirage',
            'in_match' => true,
        ])->assertOk()->assertJsonPath('settled.prize.type', 'product');

        $order = Order::query()->first();
        $this->assertNotNull($order);
        $this->assertStringContainsString('ОХОТА', $order->product_name);
        $this->assertStringContainsString('Red Bull', $order->product_name);
        $this->assertSame('PC-23', $order->pc_name);
        $this->assertSame($hunter->id, (int) $order->user_id);
    }

    public function test_party_energy_siphons_minutes_when_in_match(): void
    {
        $captain = $this->player('Cap', '79001110021', 800);
        $mate = $this->player('Mate', '79001110022', 0);
        $pc1 = $this->pc('PC-01');
        $pc2 = $this->pc('PC-02');
        $now = CarbonImmutable::now();
        $group = BookingGroup::create([
            'user_id' => $captain->id,
            'club_id' => $this->club->id,
            'starts_at' => $now->subHour(),
            'ends_at' => $now->addMinutes(2),
            'status' => 'confirmed',
            'payment_status' => 'paid',
            'currency' => 'RUB',
            'computers_total_minor' => 20000,
            'games_total_minor' => 0,
            'total_minor' => 20000,
            'paid_total_minor' => 20000,
            'paid_at' => $now->subHour(),
        ]);
        $b1 = $this->activeSeat($captain, $pc1, $group, $now->subHour(), $now->addMinutes(2));
        $b2 = $this->activeSeat($mate, $pc2, $group, $now->subHour(), $now->addSeconds(30));

        $this->postJson('/api/shell/party/energy/auto-fuel', [
            'terminal_id' => $pc1->id,
            'booking_id' => $b1->id,
            'auto_fuel' => true,
        ])->assertOk()->assertJsonPath('party_energy.auto_fuel', true);

        $pool = PartyEnergyPool::query()->where('booking_group_id', $group->id)->first();
        $this->assertNotNull($pool);
        $pool->update(['minutes_remaining' => 25, 'auto_fuel' => true]);

        app(ShellGsiStore::class)->put((int) $pc2->id, (int) $this->club->id, [
            'in_match' => true,
            'game' => 'cs2',
            'pc_name' => $pc2->name,
        ]);

        $ok = app(PartyEnergyPoolService::class)->trySiphonExpired($b2->fresh());
        $this->assertTrue($ok);
        $this->assertSame(15, (int) $pool->fresh()->minutes_remaining);
        $this->assertTrue(
            CarbonImmutable::parse($b2->fresh()->ends_at)->greaterThan($now->addMinutes(8))
        );
    }

    public function test_ghost_coach_uses_enemy_ult_from_club_gsi(): void
    {
        $pcMine = $this->pc('PC-08');
        $pcEnemy = $this->pc('PC-14');
        $store = app(ShellGsiStore::class);
        $store->put((int) $pcEnemy->id, (int) $this->club->id, [
            'in_match' => true,
            'game' => 'dota',
            'map' => 'dota',
            'match_id' => '999',
            'team' => 'dire',
            'hero' => 'npc_dota_hero_enigma',
            'ult_ready' => true,
            'ult_name' => 'enigma_black_hole',
            'pc_name' => 'PC-14',
        ]);

        $text = app(GhostCoachService::class)->compose($pcMine, [
            'game' => 'dota',
            'in_match' => true,
            'map' => 'dota',
            'match_id' => '999',
            'team' => 'radiant',
            'event' => 'heartbeat',
        ]);
        $this->assertNotNull($text);
        $this->assertStringContainsString('Enigma', $text);
        $this->assertStringContainsString('Black Hole', $text);
        $this->assertStringContainsString('PC-14', $text);
    }

    public function test_three_kills_crown_king_of_the_pc(): void
    {
        $king = $this->player('Volt', '79001110031', 0);
        $pc = $this->pc('PC-07');
        $booking = $this->activeSeat($king, $pc);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/shell/gsi', [
                'terminal_id' => $pc->id,
                'booking_id' => $booking->id,
                'event' => 'kill',
                'game' => 'cs2',
                'in_match' => true,
            ])->assertOk();
        }

        $last = $this->postJson('/api/shell/gsi', [
            'terminal_id' => $pc->id,
            'booking_id' => $booking->id,
            'event' => 'kill',
            'game' => 'cs2',
            'in_match' => true,
        ]);
        $last->assertOk()->assertJsonPath('throne.nickname', 'Volt');
        $this->assertSame(4, (int) $last->json('throne.kills'));
        $this->assertTrue((bool) $last->json('throne.mine'));

        $this->postJson('/api/shell/qr/challenge', ['terminal_id' => $pc->id])
            ->assertOk()
            ->assertJsonPath('throne.nickname', 'Volt');
    }

    public function test_lfg_matches_two_solo_players_in_the_hall(): void
    {
        $a = $this->player('Nova', '79001110041', 0);
        $b = $this->player('Ember', '79001110042', 0);
        $pcA = $this->pc('PC-11');
        $pcB = $this->pc('PC-14');
        $bA = $this->activeSeat($a, $pcA);
        $bB = $this->activeSeat($b, $pcB);

        $this->postJson('/api/shell/lfg', [
            'terminal_id' => $pcA->id,
            'booking_id' => $bA->id,
            'game' => 'cs2',
            'rank' => 'lem',
        ])->assertOk()->assertJsonPath('lfg.looking', true);

        $match = $this->postJson('/api/shell/lfg', [
            'terminal_id' => $pcB->id,
            'booking_id' => $bB->id,
            'game' => 'cs2',
            'rank' => 'supreme',
        ]);
        $match->assertOk()->assertJsonPath('lfg.queue.status', 'matched');
        $this->assertStringContainsString('PC-11', (string) $match->json('lfg.queue.line'));
        $this->assertNotNull($bA->fresh()->booking_group_id);
        $this->assertSame(
            (int) $bA->fresh()->booking_group_id,
            (int) $bB->fresh()->booking_group_id
        );
        $this->assertTrue((bool) $match->json('party_energy.available'));
    }

    public function test_better_winrate_steals_the_throne(): void
    {
        $king = $this->player('FragKing', '79001110051', 0);
        $challenger = $this->player('Clutch', '79001110052', 0);
        $pc = $this->pc('PC-09');
        $first = $this->activeSeat($king, $pc);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/shell/gsi', [
                'terminal_id' => $pc->id,
                'booking_id' => $first->id,
                'event' => 'kill',
                'game' => 'cs2',
                'player_name' => 'FragKing',
                'in_match' => true,
            ])->assertOk();
        }
        $this->assertSame('FragKing', $this->postJson('/api/shell/gsi', [
            'terminal_id' => $pc->id,
            'booking_id' => $first->id,
            'event' => 'round_loss',
            'game' => 'cs2',
            'in_match' => true,
        ])->json('throne.nickname'));

        $first->update(['status' => 'completed']);
        $second = $this->activeSeat($challenger, $pc);
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/shell/gsi', [
                'terminal_id' => $pc->id,
                'booking_id' => $second->id,
                'event' => 'kill',
                'game' => 'cs2',
                'player_name' => 'Clutch',
                'in_match' => true,
            ])->assertOk();
        }
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/shell/gsi', [
                'terminal_id' => $pc->id,
                'booking_id' => $second->id,
                'event' => 'round_win',
                'game' => 'cs2',
                'player_name' => 'Clutch',
                'in_match' => true,
            ])->assertOk();
        }

        $last = $this->postJson('/api/shell/gsi', [
            'terminal_id' => $pc->id,
            'booking_id' => $second->id,
            'event' => 'round_win',
            'game' => 'cs2',
            'player_name' => 'Clutch',
            'in_match' => true,
        ]);
        $last->assertOk()->assertJsonPath('throne.nickname', 'Clutch');
        $this->assertSame('winrate', $last->json('throne.metric'));
    }

    public function test_throne_reset_clears_today_king(): void
    {
        $king = $this->player('Volt', '79001110061', 0);
        $pc = $this->pc('PC-33');
        $booking = $this->activeSeat($king, $pc);
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/shell/gsi', [
                'terminal_id' => $pc->id,
                'booking_id' => $booking->id,
                'event' => 'kill',
                'game' => 'cs2',
                'in_match' => true,
            ])->assertOk();
        }
        $this->assertTrue(app(\App\Services\LanLive\PcThroneService::class)->resetToday($pc));
        $this->postJson('/api/shell/qr/challenge', ['terminal_id' => $pc->id])
            ->assertOk()
            ->assertJsonPath('throne', null);
    }

    public function test_guest_cannot_create_bounty_without_session(): void
    {
        $this->postJson('/api/shell/bounties', [
            'terminal_id' => 1,
            'target_computer_id' => 2,
            'stake_amount' => 100,
        ])->assertStatus(404);
    }

    private function player(string $name, string $phone, float $balance): User
    {
        $user = User::create([
            'name' => $name,
            'phone' => $phone,
            'email' => strtolower($name).'@lan.test',
            'password' => 'password',
        ]);
        Wallet::create([
            'user_id' => $user->id,
            'deposit_balance' => $balance,
            'bonus_balance' => 0,
        ]);

        return $user;
    }

    private function pc(string $name): Computer
    {
        return Computer::create([
            'club_id' => $this->club->id,
            'name' => $name,
            'status' => 'busy',
            'kind' => 'pc',
        ]);
    }

    private function activeSeat(
        User $user,
        Computer $pc,
        ?BookingGroup $group = null,
        ?CarbonImmutable $start = null,
        ?CarbonImmutable $end = null,
    ): Booking {
        $start = $start ?? CarbonImmutable::now()->subMinutes(10);
        $end = $end ?? CarbonImmutable::now()->addHour();
        $local = $start->timezone(config('app.timezone'));

        return Booking::create([
            'booking_group_id' => $group?->id,
            'user_id' => $user->id,
            'computer_id' => $pc->id,
            'pc_ids' => [(string) $pc->id],
            'date' => $local->toDateString(),
            'start_time' => $local->hour + ($local->minute / 60),
            'duration' => max(0.1, $start->diffInMinutes($end) / 60),
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
