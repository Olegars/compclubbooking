<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Club;
use App\Models\Computer;
use App\Models\ComputerThermal;
use App\Models\RelayBoard;
use App\Models\Space;
use App\Models\SpaceFan;
use App\Models\User;
use App\Models\Zone;
use App\Services\Fan\FanControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FanControlTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    private Zone $zone;

    private Space $space;

    private Computer $pcA;

    private Computer $pcB;

    private RelayBoard $board;

    private SpaceFan $fan;

    private FanControlService $fans;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            '*' => Http::response('OK', 200),
        ]);

        $this->club = Club::create([
            'name' => 'Fan Club',
            'slug' => 'fan-club',
        ]);

        $this->zone = Zone::create([
            'name' => 'Single',
            'slug' => 'single-fan',
        ]);

        $this->space = Space::create([
            'club_id' => $this->club->id,
            'zone_id' => $this->zone->id,
            'name' => 'Booth-1',
            'x' => 0,
            'y' => 0,
            'w' => 10,
            'h' => 10,
        ]);

        $this->pcA = Computer::create([
            'club_id' => $this->club->id,
            'space_id' => $this->space->id,
            'name' => 'PC-A',
            'status' => 'available',
        ]);

        $this->pcB = Computer::create([
            'club_id' => $this->club->id,
            'space_id' => $this->space->id,
            'name' => 'PC-B',
            'status' => 'available',
        ]);

        $this->board = RelayBoard::create([
            'club_id' => $this->club->id,
            'name' => 'W5100',
            'driver' => RelayBoard::DRIVER_W5100_HTTP,
            'host' => '192.168.1.4',
            'port' => 30000,
            'meta' => null,
            'is_active' => true,
        ]);

        $this->fan = SpaceFan::create([
            'club_id' => $this->club->id,
            'space_id' => $this->space->id,
            'relay_board_id' => $this->board->id,
            'channel' => 3,
            'channel2' => 4,
            'manual_mode' => SpaceFan::MODE_AUTO,
            'desired_power' => SpaceFan::SPEED_NIGHT,
            'applied_power' => SpaceFan::SPEED_NIGHT,
            'default_on_power' => SpaceFan::SPEED_HIGH,
            'thermal_on_c' => 75,
            'thermal_off_c' => 65,
        ]);

        $this->fans = app(FanControlService::class);
    }

    public function test_idle_fan_desired_stays_off_without_cloud_http(): void
    {
        $fan = $this->fans->reconcileForSpace($this->space->id, $this->club->id);

        $this->assertSame(SpaceFan::SPEED_NIGHT, $fan->desired_power);
        $this->assertSame(SpaceFan::SPEED_NIGHT, $fan->applied_power);
        Http::assertNothingSent();
    }

    public function test_active_session_sets_desired_on_without_actuator(): void
    {
        Booking::create([
            'user_id' => User::create([
                'name' => 'Player',
                'phone' => '+79991110001',
                'email' => 'fan1@example.test',
                'password' => 'password',
            ])->id,
            'computer_id' => $this->pcA->id,
            'pc_ids' => [(string) $this->pcA->id],
            'date' => now()->toDateString(),
            'start_time' => 12.0,
            'duration' => 2,
            'price' => 100,
            'status' => 'active',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
        ]);

        $fan = $this->fans->reconcileForComputer($this->pcA->id);

        $this->assertSame(SpaceFan::SPEED_HIGH, $fan->desired_power);
        $this->assertSame(SpaceFan::SPEED_NIGHT, $fan->applied_power); // shell must ack
        Http::assertNothingSent();
    }

    public function test_shell_state_includes_relay_payload(): void
    {
        $state = $this->fans->stateForComputer($this->pcA->id);

        $this->assertTrue($state['available']);
        $this->assertSame('192.168.1.4', $state['relay']['host']);
        $this->assertSame(30000, $state['relay']['port']);
        $this->assertSame(3, $state['relay']['channel']);
        $this->assertSame(4, $state['relay']['channel2']);
        $this->assertSame('w5100_http', $state['relay']['driver']);
        $this->assertArrayHasKey('facts', $state);
        $this->assertArrayHasKey('manual_lock', $state);
    }

    public function test_thermal_hysteresis_updates_facts_not_applied(): void
    {
        $this->fans->reportThermal($this->pcA->id, 70.0);
        $this->fan->refresh();
        $this->assertSame(SpaceFan::SPEED_NIGHT, $this->fan->desired_power);
        $this->assertFalse(ComputerThermal::where('computer_id', $this->pcA->id)->first()->is_hot);

        $this->fans->reportThermal($this->pcA->id, 75.0);
        $this->fan->refresh();
        $this->assertSame(SpaceFan::SPEED_MID, $this->fan->desired_power);
        $this->assertSame(SpaceFan::SPEED_NIGHT, $this->fan->applied_power);
        $this->assertTrue(ComputerThermal::where('computer_id', $this->pcA->id)->first()->is_hot);

        $this->fans->reportThermal($this->pcA->id, 70.0);
        $this->assertTrue(ComputerThermal::where('computer_id', $this->pcA->id)->first()->is_hot);

        $this->fans->reportThermal($this->pcA->id, 65.0);
        $this->fan->refresh();
        $this->assertSame(SpaceFan::SPEED_NIGHT, $this->fan->desired_power);
        $this->assertFalse(ComputerThermal::where('computer_id', $this->pcA->id)->first()->is_hot);
    }

    public function test_manual_force_off_with_cooldown(): void
    {
        $first = $this->fans->setManualModeForComputer($this->pcA->id, 'on');
        $this->assertFalse($first['locked']);
        $this->fan->refresh();
        $this->assertSame(SpaceFan::MODE_FORCE_ON, $this->fan->manual_mode);

        $locked = $this->fans->setManualModeForComputer($this->pcB->id, 'off');
        $this->assertTrue($locked['locked']);
        $this->assertGreaterThan(0, $locked['remaining_sec']);
        $this->fan->refresh();
        $this->assertSame(SpaceFan::MODE_FORCE_ON, $this->fan->manual_mode);
    }

    public function test_acknowledge_applied_updates_power(): void
    {
        $result = $this->fans->acknowledgeApplied($this->pcA->id, 3, null, 'command');
        $this->assertFalse($result['locked']);
        $this->fan->refresh();
        $this->assertSame(SpaceFan::SPEED_HIGH, $this->fan->applied_power);
        $this->assertSame($this->pcA->id, $this->fan->last_applied_by_computer_id);
    }

    public function test_shell_thermal_and_fan_endpoints(): void
    {
        $this->postJson('/api/shell/thermal', [
            'terminal_id' => $this->pcA->id,
            'cpu_c' => 80,
        ])->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('fan.desired_power', SpaceFan::SPEED_MID)
            ->assertJsonPath('fan.relay.host', '192.168.1.4')
            ->assertJsonPath('fan.relay.channel2', 4);

        $this->postJson('/api/shell/fan', [
            'terminal_id' => $this->pcB->id,
            'action' => 'off',
        ])->assertOk()
            ->assertJsonPath('fan.manual_mode', 'force_off')
            ->assertJsonPath('fan.desired_power', SpaceFan::SPEED_NIGHT);

        $this->postJson('/api/shell/fan/applied', [
            'terminal_id' => $this->pcA->id,
            'applied_power' => 1,
            'source' => 'command',
        ])->assertOk()
            ->assertJsonPath('fan.applied_power', 1);

        $this->getJson('/api/shell/fan?terminal_id='.$this->pcA->id)
            ->assertOk()
            ->assertJsonPath('fan.available', true)
            ->assertJsonPath('fan.club_id', $this->club->id);
    }

    public function test_cloud_does_not_http_to_relay(): void
    {
        Http::fake();

        $this->fans->setManualModeForComputer($this->pcA->id, 'on');
        $this->fans->reconcileForComputer($this->pcA->id);
        $this->fans->acknowledgeApplied($this->pcA->id, 3);

        Http::assertNothingSent();
    }

    public function test_orphan_snapshot_when_fan_on_and_pcs_off(): void
    {
        $this->fan->update(['applied_power' => SpaceFan::SPEED_HIGH]);
        $this->pcA->update(['power_state' => 'off', 'last_seen_at' => null]);
        $this->pcB->update(['power_state' => 'off', 'last_seen_at' => null]);

        $orphans = $this->fans->orphanSnapshot($this->club->id);
        $this->assertNotEmpty($orphans);
        $this->assertTrue(collect($orphans)->firstWhere('fan_id', $this->fan->id)['fan_orphan_on']);
    }

    public function test_admin_force_off_sets_mode(): void
    {
        $this->fan->update(['applied_power' => SpaceFan::SPEED_HIGH, 'manual_mode' => SpaceFan::MODE_AUTO]);
        $this->pcA->update(['mac_address' => 'AA:BB:CC:DD:EE:FF', 'power_state' => 'off', 'last_seen_at' => null]);
        $this->pcB->update(['power_state' => 'off', 'last_seen_at' => null]);

        $result = $this->fans->adminForceOff($this->fan->id);
        $this->assertSame(SpaceFan::MODE_FORCE_OFF, $result['fan']->manual_mode);
        $this->assertSame(SpaceFan::SPEED_NIGHT, $result['fan']->desired_power);
        $this->assertSame($this->pcA->id, $result['wol_computer_id']);
        $this->assertSame('on', Computer::find($this->pcA->id)->power_desired);
    }

    public function test_speed_to_relays_cascade(): void
    {
        $this->assertSame([false, false], SpaceFan::speedToRelays(1));
        $this->assertSame([true, false], SpaceFan::speedToRelays(2));
        $this->assertSame([false, true], SpaceFan::speedToRelays(3));
        $this->assertSame(3, SpaceFan::relaysToSpeed(true, true));
    }

    public function test_cascade_pair_validation(): void
    {
        $this->assertTrue(SpaceFan::isCascadePair(1, 2));
        $this->assertTrue(SpaceFan::isCascadePair(15, 16));
        $this->assertFalse(SpaceFan::isCascadePair(1, 3));
        $this->assertFalse(SpaceFan::isCascadePair(5, 9));
        $this->assertFalse(SpaceFan::isCascadePair(2, 3));
        $this->assertFalse(SpaceFan::isCascadePair(4, 3));

        $bad = $this->fans->bindForComputer($this->pcA->id, $this->board->id, 1, 3);
        $this->assertFalse($bad['ok']);
        $this->assertStringContainsString('парные', $bad['message']);
    }
}
