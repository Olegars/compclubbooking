<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingGroup;
use App\Models\Club;
use App\Models\Computer;
use App\Models\User;
use App\Services\ComputerPowerService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShellHybridHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    private Computer $computer;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'club.booking.late_start_grace_minutes' => 30,
            'club.power.warmup_minutes' => 30,
        ]);

        $this->user = User::create([
            'name' => 'Hybrid User',
            'phone' => '+79991112233',
            'email' => 'hybrid@example.test',
            'password' => 'password',
        ]);
        $this->club = Club::create(['name' => 'Hybrid Club', 'slug' => 'hybrid-club']);
        $this->computer = Computer::create([
            'club_id' => $this->club->id,
            'name' => 'PC-01',
            'status' => 'available',
            'kind' => 'pc',
            'hwid' => 'aabbccdd-eeff-1122-3344-556677889900',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_heartbeat_stores_cache_and_skips_shutdown_in_maintenance(): void
    {
        $response = $this->postJson('/api/shell/power/heartbeat', [
            'hwid' => $this->computer->hwid,
            'mac_address' => 'AA:BB:CC:DD:EE:01',
            'maintenance' => true,
            'cache_ok' => false,
            'cache_free_gb' => 12.5,
            'data_root' => 'D:/ShellData',
            'volume_letter' => 'D',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('power_action', 'none')
            ->assertJsonPath('power_desired', 'on')
            ->assertJsonPath('maintenance', true)
            ->assertJsonPath('cache_ok', false);

        $this->computer->refresh();
        $this->assertTrue($this->computer->maintenance);
        $this->assertSame('maintenance', $this->computer->status);
        $this->assertFalse($this->computer->cache_ok);
        $this->assertEquals(12.5, (float) $this->computer->cache_free_gb);
        $this->assertSame('D:/ShellData', $this->computer->data_root);
        $this->assertSame('AA:BB:CC:DD:EE:01', $this->computer->mac_address);
    }

    public function test_leaving_maintenance_allows_idle_shutdown(): void
    {
        $this->computer->update([
            'status' => 'maintenance',
            'maintenance' => true,
        ]);

        $this->postJson('/api/shell/power/heartbeat', [
            'hwid' => $this->computer->hwid,
            'maintenance' => false,
            'cache_ok' => true,
            'cache_free_gb' => 80,
        ])->assertOk()
            ->assertJsonPath('power_action', 'shutdown')
            ->assertJsonPath('maintenance', false);

        $this->computer->refresh();
        $this->assertFalse($this->computer->maintenance);
        $this->assertNotSame('maintenance', $this->computer->status);
    }

    public function test_power_action_for_stays_none_while_maintenance(): void
    {
        $this->computer->update([
            'status' => 'maintenance',
            'maintenance' => true,
        ]);

        $action = app(ComputerPowerService::class)->powerActionFor((int) $this->computer->id);
        $this->assertSame('none', $action);
    }

    public function test_late_pin_keeps_pc_on_during_soft_grace(): void
    {
        $startsAt = CarbonImmutable::parse('2026-08-01 10:00:00', config('app.timezone'));
        $this->makeBooking($startsAt, $startsAt->addHour());
        $now = $startsAt->addMinutes(15);
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);

        $this->postJson('/api/shell/power/heartbeat', [
            'hwid' => $this->computer->hwid,
        ])->assertOk()
            ->assertJsonPath('power_desired', 'on')
            ->assertJsonPath('session_active', false);
    }

    public function test_exhausted_soft_grace_without_pin_allows_shutdown(): void
    {
        $startsAt = CarbonImmutable::parse('2026-08-01 10:00:00', config('app.timezone'));
        $this->makeBooking($startsAt, $startsAt->addHour());
        $now = $startsAt->addMinutes(95);
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);

        $this->postJson('/api/shell/power/heartbeat', [
            'hwid' => $this->computer->hwid,
        ])->assertOk()
            ->assertJsonPath('power_desired', 'off')
            ->assertJsonPath('power_action', 'shutdown');
    }

    private function makeBooking(CarbonImmutable $startsAt, CarbonImmutable $endsAt): Booking
    {
        $local = $startsAt->timezone(config('app.timezone'));
        $group = BookingGroup::create([
            'user_id' => $this->user->id,
            'club_id' => $this->club->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => 'confirmed',
            'payment_status' => 'paid',
            'currency' => 'RUB',
            'computers_total_minor' => 10000,
            'games_total_minor' => 0,
            'total_minor' => 10000,
            'paid_total_minor' => 10000,
            'paid_at' => $startsAt->subDay(),
        ]);

        return Booking::create([
            'booking_group_id' => $group->id,
            'user_id' => $this->user->id,
            'computer_id' => $this->computer->id,
            'pc_ids' => [(string) $this->computer->id],
            'date' => $local->toDateString(),
            'start_time' => $local->hour + ($local->minute / 60),
            'duration' => $startsAt->diffInMinutes($endsAt) / 60,
            'price' => 100,
            'price_minor' => 10000,
            'status' => 'confirmed',
            'pin_code' => '1234',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);
    }
}
