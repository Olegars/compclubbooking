<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingGroup;
use App\Models\Club;
use App\Models\Computer;
use App\Models\User;
use App\Models\UserSetting;
use App\Models\Wallet;
use App\Services\LanLive\GhostCoachService;
use App\Services\LanLive\PartyEcoDropSynchronizer;
use App\Services\LanLive\ShellGsiStore;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class GhostCoachPartyTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->club = Club::create(['name' => 'Coach Club', 'slug' => 'ghost-coach-party']);
    }

    public function test_party_eco_line_when_team_bank_is_below_threshold(): void
    {
        [$pcs, $bookings] = $this->stack(['ПК-03' => 800, 'ПК-05' => 900, 'ПК-07' => 700]);

        $text = app(PartyEcoDropSynchronizer::class)->compose(
            $pcs['ПК-03'],
            $bookings['ПК-03'],
            $this->freezeSnap($pcs['ПК-03'], 800)
        );

        $this->assertSame(PartyEcoDropSynchronizer::ECO_LINE, $text);
        $this->assertSame('Эко-раунд, копим на бай', $text);
    }

    public function test_party_drop_points_awp_at_poorest_seat(): void
    {
        [$pcs, $bookings] = $this->stack(['ПК-03' => 8000, 'ПК-05' => 1200, 'ПК-07' => 4000]);

        $text = app(GhostCoachService::class)->compose(
            $pcs['ПК-03'],
            $this->freezeSnap($pcs['ПК-03'], 8000),
            $bookings['ПК-03']
        );

        $this->assertSame('Скинь AWP на ПК-05', $text);
    }

    public function test_eco_beats_drop_when_bank_is_poor(): void
    {
        [$pcs, $bookings] = $this->stack(['ПК-03' => 5500, 'ПК-05' => 200]);

        $text = app(PartyEcoDropSynchronizer::class)->compose(
            $pcs['ПК-03'],
            $bookings['ПК-03'],
            $this->freezeSnap($pcs['ПК-03'], 5500)
        );

        $this->assertSame(PartyEcoDropSynchronizer::ECO_LINE, $text);
    }

    public function test_solo_booking_does_not_get_party_whisper(): void
    {
        $user = $this->player('Solo', '79002220001');
        $pc = $this->pc('ПК-11');
        $booking = $this->activeSeat($user, $pc);
        app(ShellGsiStore::class)->put((int) $pc->id, (int) $this->club->id, $this->freezeSnap($pc, 800));

        $this->assertNull(app(PartyEcoDropSynchronizer::class)->compose(
            $pc,
            $booking,
            $this->freezeSnap($pc, 800)
        ));
    }

    public function test_live_round_is_silent(): void
    {
        [$pcs, $bookings] = $this->stack(['ПК-03' => 800, 'ПК-05' => 900]);

        $snap = $this->freezeSnap($pcs['ПК-03'], 800);
        $snap['event'] = 'heartbeat';
        $snap['phase'] = 'live';

        $this->assertNull(app(PartyEcoDropSynchronizer::class)->compose(
            $pcs['ПК-03'],
            $bookings['ПК-03'],
            $snap
        ));
    }

    public function test_dota_party_is_ignored(): void
    {
        [$pcs, $bookings] = $this->stack(['ПК-03' => 800, 'ПК-05' => 900]);
        $snap = $this->freezeSnap($pcs['ПК-03'], 800);
        $snap['game'] = 'dota';

        $this->assertNull(app(PartyEcoDropSynchronizer::class)->compose(
            $pcs['ПК-03'],
            $bookings['ПК-03'],
            $snap
        ));
    }

    public function test_skips_teammate_who_already_holds_awp(): void
    {
        [$pcs, $bookings] = $this->stack(['ПК-03' => 9000, 'ПК-05' => 800, 'ПК-07' => 1500]);
        app(ShellGsiStore::class)->put((int) $pcs['ПК-05']->id, (int) $this->club->id, array_merge(
            $this->freezeSnap($pcs['ПК-05'], 800),
            ['weapon' => 'weapon_awp']
        ));

        $text = app(PartyEcoDropSynchronizer::class)->compose(
            $pcs['ПК-03'],
            $bookings['ПК-03'],
            $this->freezeSnap($pcs['ПК-03'], 9000)
        );

        $this->assertSame('Скинь AWP на ПК-07', $text);
    }

    public function test_same_line_is_synced_across_party_pcs(): void
    {
        [$pcs, $bookings, $users] = $this->stack(['ПК-03' => 800, 'ПК-05' => 900], withUsers: true);
        $coach = app(GhostCoachService::class);

        $first = $coach->maybeWhisper(
            $pcs['ПК-05'],
            $users['ПК-05'],
            $this->freezeSnap($pcs['ПК-05'], 900),
            $bookings['ПК-05']
        );
        $this->assertSame('Эко-раунд, копим на бай', $first);

        $second = $coach->maybeWhisper(
            $pcs['ПК-03'],
            $users['ПК-03'],
            $this->freezeSnap($pcs['ПК-03'], 8000),
            $bookings['ПК-03']
        );
        $this->assertSame('Эко-раунд, копим на бай', $second);

        $repeat = $coach->maybeWhisper(
            $pcs['ПК-05'],
            $users['ПК-05'],
            $this->freezeSnap($pcs['ПК-05'], 900),
            $bookings['ПК-05']
        );
        $this->assertNull($repeat);
    }

    public function test_gsi_endpoint_whispers_eco_to_each_party_shell(): void
    {
        [$pcs, $bookings] = $this->stack(['ПК-03' => 800, 'ПК-05' => 900], seedGsi: false);

        $this->postJson('/api/shell/gsi', [
            'terminal_id' => $pcs['ПК-03']->id,
            'booking_id' => $bookings['ПК-03']->id,
            'event' => 'freezetime',
            'game' => 'cs2',
            'phase' => 'freezetime',
            'money' => 800,
            'team' => 'T',
            'map' => 'de_mirage',
            'match_id' => 'party-1',
            'round' => 4,
            'in_match' => true,
        ])->assertOk()->assertJsonPath('whisper', null);

        $this->postJson('/api/shell/gsi', [
            'terminal_id' => $pcs['ПК-05']->id,
            'booking_id' => $bookings['ПК-05']->id,
            'event' => 'freezetime',
            'game' => 'cs2',
            'phase' => 'freezetime',
            'money' => 900,
            'team' => 'T',
            'map' => 'de_mirage',
            'match_id' => 'party-1',
            'round' => 4,
            'in_match' => true,
        ])->assertOk()->assertJsonPath('whisper', 'Эко-раунд, копим на бай');

        $this->postJson('/api/shell/gsi', [
            'terminal_id' => $pcs['ПК-03']->id,
            'booking_id' => $bookings['ПК-03']->id,
            'event' => 'freezetime',
            'game' => 'cs2',
            'phase' => 'freezetime',
            'money' => 800,
            'team' => 'T',
            'map' => 'de_mirage',
            'match_id' => 'party-1',
            'round' => 4,
            'in_match' => true,
        ])->assertOk()->assertJsonPath('whisper', 'Эко-раунд, копим на бай');
    }

    public function test_gsi_endpoint_whispers_awp_drop(): void
    {
        [$pcs, $bookings] = $this->stack(['ПК-03' => 8000, 'ПК-05' => 1200], seedGsi: false);

        $this->postJson('/api/shell/gsi', [
            'terminal_id' => $pcs['ПК-03']->id,
            'booking_id' => $bookings['ПК-03']->id,
            'event' => 'freezetime',
            'game' => 'cs2',
            'phase' => 'freezetime',
            'money' => 8000,
            'team' => 'CT',
            'map' => 'de_inferno',
            'match_id' => 'drop-1',
            'round' => 9,
            'in_match' => true,
        ])->assertOk();

        $this->postJson('/api/shell/gsi', [
            'terminal_id' => $pcs['ПК-05']->id,
            'booking_id' => $bookings['ПК-05']->id,
            'event' => 'freezetime',
            'game' => 'cs2',
            'phase' => 'freezetime',
            'money' => 1200,
            'team' => 'CT',
            'map' => 'de_inferno',
            'match_id' => 'drop-1',
            'round' => 9,
            'in_match' => true,
        ])->assertOk()->assertJsonPath('whisper', 'Скинь AWP на ПК-05');

        $this->postJson('/api/shell/gsi', [
            'terminal_id' => $pcs['ПК-03']->id,
            'booking_id' => $bookings['ПК-03']->id,
            'event' => 'freezetime',
            'game' => 'cs2',
            'phase' => 'freezetime',
            'money' => 8000,
            'team' => 'CT',
            'map' => 'de_inferno',
            'match_id' => 'drop-1',
            'round' => 9,
            'in_match' => true,
        ])->assertOk()->assertJsonPath('whisper', 'Скинь AWP на ПК-05');
    }

    public function test_disabled_coach_skips_party_whisper(): void
    {
        [$pcs, $bookings, $users] = $this->stack(['ПК-03' => 800, 'ПК-05' => 900], withUsers: true, seedGsi: false);
        UserSetting::query()->updateOrCreate(
            ['user_id' => $users['ПК-05']->id],
            ['ghost_coach_enabled' => false]
        );

        $this->postJson('/api/shell/gsi', [
            'terminal_id' => $pcs['ПК-03']->id,
            'booking_id' => $bookings['ПК-03']->id,
            'event' => 'freezetime',
            'game' => 'cs2',
            'phase' => 'freezetime',
            'money' => 800,
            'in_match' => true,
        ])->assertOk();

        $this->postJson('/api/shell/gsi', [
            'terminal_id' => $pcs['ПК-05']->id,
            'booking_id' => $bookings['ПК-05']->id,
            'event' => 'freezetime',
            'game' => 'cs2',
            'phase' => 'freezetime',
            'money' => 900,
            'in_match' => true,
        ])->assertOk()->assertJsonPath('whisper', null);
    }

    public function test_docs_cover_coach_whisper(): void
    {
        $blob = json_encode(\App\Support\SystemDocs::sections(), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Coach Whisper: Eco-Round & Drop Synchronizer', $blob);
        $this->assertStringContainsString('Эко-раунд, копим на бай', $blob);
        $this->assertStringContainsString('Скинь AWP на ПК-05', $blob);
        $this->assertStringContainsString('PartyEcoDropSynchronizer', $blob);
    }

    /**
     * @param  array<string, int>  $seats
     * @return array{0: array<string, Computer>, 1: array<string, Booking>, 2?: array<string, User>}
     */
    private function stack(array $seats, bool $withUsers = false, bool $seedGsi = true): array
    {
        $now = CarbonImmutable::now();
        $captain = $this->player('Cap', '7900222'.sprintf('%04d', random_int(1000, 9999)));
        $group = BookingGroup::create([
            'user_id' => $captain->id,
            'club_id' => $this->club->id,
            'starts_at' => $now->subHour(),
            'ends_at' => $now->addHour(),
            'status' => 'confirmed',
            'payment_status' => 'paid',
            'currency' => 'RUB',
            'computers_total_minor' => 20000,
            'games_total_minor' => 0,
            'total_minor' => 20000,
            'paid_total_minor' => 20000,
            'paid_at' => $now->subHour(),
        ]);

        $pcs = [];
        $bookings = [];
        $users = [];
        $store = app(ShellGsiStore::class);
        $index = 0;
        foreach ($seats as $name => $money) {
            $user = $index === 0 ? $captain : $this->player($name, '7900333'.sprintf('%04d', random_int(1000, 9999)));
            $pc = $this->pc($name);
            $booking = $this->activeSeat($user, $pc, $group);
            $pcs[$name] = $pc;
            $bookings[$name] = $booking;
            $users[$name] = $user;
            if ($seedGsi) {
                $store->put((int) $pc->id, (int) $this->club->id, $this->freezeSnap($pc, $money));
            }
            $index++;
        }

        return $withUsers ? [$pcs, $bookings, $users] : [$pcs, $bookings];
    }

    /**
     * @return array<string, mixed>
     */
    private function freezeSnap(Computer $pc, int $money): array
    {
        return [
            'event' => 'freezetime',
            'game' => 'cs2',
            'phase' => 'freezetime',
            'money' => $money,
            'in_match' => true,
            'team' => 'T',
            'map' => 'de_mirage',
            'match_id' => 'party-1',
            'round' => 4,
            'pc_name' => (string) $pc->name,
            'weapon' => 'weapon_knife',
        ];
    }

    private function player(string $name, string $phone): User
    {
        $user = User::create([
            'name' => $name,
            'phone' => $phone,
            'email' => strtolower(preg_replace('/\W+/', '', $name)).uniqid().'@lan.test',
            'password' => 'password',
        ]);
        Wallet::create([
            'user_id' => $user->id,
            'deposit_balance' => 0,
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

    private function activeSeat(User $user, Computer $pc, ?BookingGroup $group = null): Booking
    {
        $start = CarbonImmutable::now()->subMinutes(10);
        $end = CarbonImmutable::now()->addHour();
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
