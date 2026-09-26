<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Booking;
use App\Models\Club;
use App\Models\Computer;
use App\Models\User;
use App\Models\UserFeatureDailyStat;
use App\Models\UserFeatureEvent;
use App\Services\UserFeatureReport;
use App\Services\UserFeatureTelemetry;
use App\Support\UserFeatureCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserFeatureTelemetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_records_conscious_action_and_coalesces_slider(): void
    {
        [$user, $pc, $booking] = $this->seat();
        $telemetry = app(UserFeatureTelemetry::class);

        $telemetry->record('sos_call', null, UserFeatureCatalog::SOURCE_PC, [], $booking->id, $pc->id);
        $telemetry->record('not_a_feature', $user, UserFeatureCatalog::SOURCE_PC);
        $telemetry->record('sos_call', $user, UserFeatureCatalog::SOURCE_PC, ['reason' => 'other'], $booking->id, $pc->id);
        $telemetry->record('fan_speed_manual', $user, null, ['target_speed' => '50'], $booking->id, $pc->id);
        $telemetry->record('fan_speed_manual', $user, null, ['target_speed' => '100'], $booking->id, $pc->id);

        $this->assertSame(2, UserFeatureEvent::query()->count());
        $fan = UserFeatureEvent::query()->where('feature_key', 'fan_speed_manual')->first();
        $this->assertSame('100', $fan->payload['target_speed']);
        $this->assertSame($pc->name, $fan->terminal_id);
        $this->assertSame((int) $user->id, (int) $fan->user_id);
        $this->assertSame((int) $booking->id, (int) $fan->booking_id);
    }

    public function test_gsi_and_pc_launcher_do_not_write_events(): void
    {
        [$user, $pc, $booking] = $this->seat();

        $this->postJson('/api/shell/gsi', [
            'terminal_id' => $pc->id,
            'booking_id' => $booking->id,
            'event' => 'kill',
            'game' => 'cs2',
            'in_match' => true,
        ])->assertOk();

        $this->postJson('/api/shell/telemetry/user-action', [
            'terminal_id' => $pc->id,
            'feature_key' => 'tv_app_launch',
            'payload' => ['app' => 'YouTube'],
        ])->assertOk()->assertJsonPath('status', 'ignored');

        $this->postJson('/api/shell/telemetry/user-action', [
            'terminal_id' => $pc->id,
            'feature_key' => 'voice_ai_f1',
        ])->assertStatus(422);

        $this->assertSame(0, UserFeatureEvent::query()->count());
        $this->assertSame((int) $user->id, (int) $booking->user_id);
    }

    public function test_tv_launch_is_stored_for_the_guest(): void
    {
        [$user, $pc, $booking] = $this->seat('tv');

        $this->postJson('/api/shell/telemetry/user-action', [
            'terminal_id' => $pc->id,
            'feature_key' => 'tv_app_launch',
            'payload' => ['app' => 'Кинопоиск'],
        ])->assertOk()->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('user_feature_events', [
            'user_id' => $user->id,
            'booking_id' => $booking->id,
            'feature_key' => 'tv_app_launch',
            'source_client' => UserFeatureCatalog::SOURCE_TV,
        ]);
    }

    public function test_report_reach_trend_and_admin_page(): void
    {
        [$user, $pc, $booking] = $this->seat();
        $second = User::create([
            'name' => 'Second Guest',
            'phone' => '79000000002',
            'email' => 'second@telemetry.test',
            'password' => 'password',
        ]);
        $secondBooking = Booking::create([
            'user_id' => $second->id,
            'computer_id' => $pc->id,
            'pc_ids' => [(string) $pc->id],
            'date' => now()->toDateString(),
            'start_time' => 12,
            'duration' => 2,
            'price' => 100,
            'price_minor' => 10000,
            'status' => 'completed',
            'pin_code' => '2222',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'actual_started_at' => now()->subHour(),
        ]);

        $telemetry = app(UserFeatureTelemetry::class);
        $telemetry->record('sos_call', $user, UserFeatureCatalog::SOURCE_PC, [], $booking->id, $pc->id);
        $telemetry->record('sos_call', $user, UserFeatureCatalog::SOURCE_PC, [], $booking->id, $pc->id);
        CarbonImmutable::setTestNow(now()->subDays(20));
        $telemetry->record('lfg_party_search', $second, UserFeatureCatalog::SOURCE_PC, [], $secondBooking->id, $pc->id);
        CarbonImmutable::setTestNow();

        $page = app(UserFeatureReport::class)->page(
            CarbonImmutable::now()->subDays(6),
            CarbonImmutable::now(),
            '7d',
            'all',
            '',
            (int) $pc->club_id,
        );

        $sos = collect($page['rows'])->firstWhere('key', 'sos_call');
        $this->assertSame(2, $sos['actions']);
        $this->assertSame(1, $sos['unique_users']);
        $this->assertSame(2.0, $sos['avg_per_session']);
        $this->assertSame('up', $sos['trend']['direction']);
        $this->assertGreaterThan(0, $page['audience']);

        $lfg = collect($page['rows'])->firstWhere('key', 'lfg_party_search');
        $this->assertSame(0, $lfg['actions']);

        $this->assertTrue(collect($page['dead'])->contains(fn (array $row) => $row['key'] === 'lfg_party_search'));
        $this->assertFalse(collect($page['dead'])->contains(fn (array $row) => $row['key'] === 'sos_call'));

        $admin = Admin::query()->create([
            'name' => 'Supervisor',
            'email' => 'sup-features@test',
            'password' => 'password',
            'role' => 'supervisor',
            'club_id' => $pc->club_id,
            'base_rate' => 2000,
            'pay_type' => 'shift',
            'employment_pending' => false,
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin/analytics/features')
            ->assertOk()
            ->assertInertia(fn ($view) => $view
                ->component('Admin/FeatureEngagement')
                ->has('rows')
                ->where('audience', $page['audience']));

        $this->artisan('telemetry:aggregate', ['--date' => now()->toDateString()])
            ->assertSuccessful();
        $this->assertTrue(UserFeatureDailyStat::query()->where('feature_key', 'sos_call')->exists());

        CarbonImmutable::setTestNow(now()->addDays(91));
        $this->artisan('telemetry:prune', ['--days' => 90])->assertSuccessful();
        CarbonImmutable::setTestNow();
        $this->assertSame(0, UserFeatureEvent::query()->count());
        $this->assertTrue(UserFeatureDailyStat::query()->where('feature_key', 'sos_call')->exists());
    }

    /**
     * @return array{0: User, 1: Computer, 2: Booking}
     */
    private function seat(string $kind = 'pc'): array
    {
        $club = Club::create(['name' => 'Telemetry', 'slug' => 'telemetry-'.$kind]);
        $user = User::create([
            'name' => 'Guest',
            'phone' => '79000000001',
            'email' => 'guest-'.$kind.'@telemetry.test',
            'password' => 'password',
        ]);
        $pc = Computer::create([
            'club_id' => $club->id,
            'name' => $kind === 'tv' ? 'TV-1' : 'PC-08',
            'status' => 'busy',
            'kind' => $kind,
        ]);
        $start = CarbonImmutable::now()->subHour();
        $booking = Booking::create([
            'user_id' => $user->id,
            'computer_id' => $pc->id,
            'pc_ids' => [(string) $pc->id],
            'date' => $start->toDateString(),
            'start_time' => 10,
            'duration' => 3,
            'price' => 200,
            'price_minor' => 20000,
            'status' => 'active',
            'pin_code' => '1111',
            'starts_at' => $start,
            'ends_at' => $start->addHours(3),
            'actual_started_at' => $start,
        ]);

        return [$user, $pc, $booking];
    }
}
