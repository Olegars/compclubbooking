<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Booking;
use App\Models\Club;
use App\Models\Computer;
use App\Models\LuckySeatDrop;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ClubFeatureService;
use App\Services\LanLive\LuckySeatLootService;
use App\Support\ClubFeatureCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ClubFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        LuckySeatLootService::flushSessions();
        $this->club = Club::create(['name' => 'Feature Club', 'slug' => 'feature-club']);
    }

    public function test_supervisor_opens_features_page_with_defaults_on(): void
    {
        $this->actingAs($this->supervisor(), 'admin')
            ->get('/admin/config/features')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/ClubFeatures')
                ->has('features')
                ->where('features.0.enabled', true)
            );

        $keys = collect(ClubFeatureCatalog::all())->pluck('key');
        $this->assertTrue($keys->contains('lucky_seat'));
        $this->assertTrue($keys->contains('qr_login'));
        $this->assertTrue($keys->contains('clan_wars'));
        $this->assertTrue($keys->contains('rollback_markers'));

        $this->actingAs($this->supervisor(), 'admin')
            ->get('/admin/config/features')
            ->assertInertia(fn ($page) => $page
                ->where('features', fn ($rows) => collect($rows)->contains(
                    fn ($row) => ($row['key'] ?? '') === 'rollback_markers'
                        && ($row['group'] ?? '') === 'stations'
                        && ($row['group_title'] ?? '') === 'Станции и образ'
                        && ($row['enabled'] ?? false) === true
                ))
            );
    }

    public function test_intern_cannot_open_features_page(): void
    {
        $intern = $this->makeAdmin('intern');
        $this->actingAs($intern, 'admin')
            ->get('/admin/config/features')
            ->assertRedirect('/admin/salary');
    }

    public function test_can_disable_lucky_seat_and_save_streak(): void
    {
        $admin = $this->supervisor();

        $this->actingAs($admin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->from('/admin/config/features')
            ->post('/admin/config/features/lucky_seat', [
                'enabled' => false,
            ])
            ->assertRedirect();

        $this->assertFalse(app(ClubFeatureService::class)->enabled($this->club->id, 'lucky_seat'));

        $this->actingAs($admin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->from('/admin/config/features')
            ->post('/admin/config/features/lucky_seat', [
                'enabled' => true,
                'settings' => [
                    'round_streak' => 2,
                    'cooldown_hours' => 3,
                    'playtime_hours' => 3,
                    'match_streak' => 2,
                    'bonus_low' => 50,
                    'bonus_mid' => 75,
                    'bonus_high' => 100,
                    'promo_percent' => 10,
                    'promo_days' => 30,
                ],
            ])
            ->assertRedirect();

        $this->assertSame(2, app(ClubFeatureService::class)->int($this->club->id, 'lucky_seat', 'round_streak'));
    }

    public function test_can_save_rollback_markers_from_features_page(): void
    {
        $admin = $this->supervisor();

        $this->actingAs($admin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->from('/admin/config/features')
            ->post('/admin/config/features/rollback_markers', [
                'enabled' => true,
                'settings' => [
                    'crash_ticket' => false,
                    'auto_verify' => true,
                    'keep' => 12,
                ],
            ])
            ->assertRedirect();

        $features = app(ClubFeatureService::class);
        $this->assertTrue($features->enabled($this->club->id, 'rollback_markers'));
        $this->assertFalse($features->bool($this->club->id, 'rollback_markers', 'crash_ticket', true));
        $this->assertTrue($features->bool($this->club->id, 'rollback_markers', 'auto_verify', false));
        $this->assertSame(12, $features->int($this->club->id, 'rollback_markers', 'keep'));
    }

    public function test_disabled_lootbox_does_not_drop(): void
    {
        app(ClubFeatureService::class)->save($this->club->id, 'lucky_seat', false);
        [$pc, $booking] = $this->seat();

        for ($i = 0; $i < 5; $i++) {
            $this->gsi($pc, $booking, 'round_win')
                ->assertOk()
                ->assertJsonPath('lootbox_dropped', null);
        }

        $this->assertSame(0, LuckySeatDrop::query()->count());
    }

    public function test_custom_round_streak_drops_earlier(): void
    {
        app(ClubFeatureService::class)->save($this->club->id, 'lucky_seat', true, [
            'round_streak' => 2,
        ]);
        [$pc, $booking] = $this->seat();

        $this->gsi($pc, $booking, 'round_win')
            ->assertOk()
            ->assertJsonPath('lootbox_dropped', null);

        $this->gsi($pc, $booking, 'round_win')
            ->assertOk()
            ->assertJsonPath('lootbox_dropped.status', 'pending');

        $this->assertSame(1, LuckySeatDrop::query()->count());
    }

    public function test_qr_challenge_respects_toggle(): void
    {
        $pc = Computer::create([
            'club_id' => $this->club->id,
            'name' => 'PC-QR',
            'status' => 'available',
            'kind' => 'pc',
        ]);

        $on = $this->postJson('/api/shell/qr/challenge', ['terminal_id' => $pc->id]);
        $on->assertOk()->assertJsonPath('enabled', true)->assertJsonPath('features.qr_login.enabled', true);

        app(ClubFeatureService::class)->save($this->club->id, 'qr_login', false);

        $off = $this->postJson('/api/shell/qr/challenge', ['terminal_id' => $pc->id]);
        $off->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('qr_payload', '')
            ->assertJsonPath('features.qr_login.enabled', false);
    }

    public function test_heartbeat_includes_features(): void
    {
        $pc = Computer::create([
            'club_id' => $this->club->id,
            'name' => 'PC-HB',
            'status' => 'available',
            'kind' => 'pc',
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
        ]);
        app(ClubFeatureService::class)->save($this->club->id, 'lfg', false);

        $this->postJson('/api/shell/power/heartbeat', [
            'terminal_id' => $pc->id,
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
        ])
            ->assertOk()
            ->assertJsonPath('features.lfg.enabled', false)
            ->assertJsonPath('features.lucky_seat.enabled', true)
            ->assertJsonPath('features.rollback_markers.enabled', true);
    }

    public function test_lfg_enqueue_rejected_when_off(): void
    {
        app(ClubFeatureService::class)->save($this->club->id, 'lfg', false);
        [$pc, $booking] = $this->seat();

        $this->postJson('/api/shell/lfg', [
            'terminal_id' => $pc->id,
            'booking_id' => $booking->id,
            'game' => 'cs2',
            'rank' => 'lem',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Поиск пати выключен');
    }

    private function supervisor(): Admin
    {
        return $this->makeAdmin('supervisor');
    }

    private function makeAdmin(string $role): Admin
    {
        return Admin::query()->create([
            'name' => ucfirst($role).' '.uniqid(),
            'email' => $role.'.'.uniqid().'@features.test',
            'password' => 'password',
            'role' => $role,
            'club_id' => $this->club->id,
            'base_rate' => 2000,
            'pay_type' => 'shift',
            'employment_pending' => $role === 'intern',
        ]);
    }

    /**
     * @return array{0: Computer, 1: Booking}
     */
    private function seat(): array
    {
        $user = User::create([
            'name' => 'Feat User',
            'phone' => '79001110888',
            'email' => 'feat@lan.test',
            'password' => 'password',
        ]);
        Wallet::create([
            'user_id' => $user->id,
            'deposit_balance' => 200,
            'bonus_balance' => 0,
        ]);
        $pc = Computer::create([
            'club_id' => $this->club->id,
            'name' => 'PC-FEAT',
            'status' => 'busy',
            'kind' => 'pc',
        ]);
        $start = CarbonImmutable::now()->subMinutes(20);
        $local = $start->timezone(config('app.timezone'));
        $booking = Booking::create([
            'user_id' => $user->id,
            'computer_id' => $pc->id,
            'pc_ids' => [(string) $pc->id],
            'date' => $local->toDateString(),
            'start_time' => $local->hour + ($local->minute / 60),
            'duration' => 5,
            'price' => 200,
            'price_minor' => 20000,
            'status' => 'active',
            'pin_code' => '1111',
            'starts_at' => $start,
            'ends_at' => $start->addHours(5),
            'actual_started_at' => $start,
        ]);

        return [$pc, $booking];
    }

    private function gsi(Computer $pc, Booking $booking, string $event)
    {
        return $this->postJson('/api/shell/gsi', [
            'terminal_id' => $pc->id,
            'booking_id' => $booking->id,
            'event' => $event,
            'game' => 'cs2',
            'in_match' => true,
        ]);
    }
}
