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
            'name' => 'H32',
            'driver' => RelayBoard::DRIVER_KINCONY_HTTP,
            'host' => '192.168.1.50',
            'port' => 80,
            'meta' => ['password' => 'admin123'],
            'is_active' => true,
        ]);

        $this->fan = SpaceFan::create([
            'club_id' => $this->club->id,
            'space_id' => $this->space->id,
            'relay_board_id' => $this->board->id,
            'channel' => 3,
            'manual_mode' => SpaceFan::MODE_AUTO,
            'desired_power' => 0,
            'applied_power' => 0,
            'default_on_power' => 100,
            'thermal_on_c' => 75,
            'thermal_off_c' => 65,
        ]);

        $this->fans = app(FanControlService::class);
    }

    public function test_idle_fan_stays_off(): void
    {
        $fan = $this->fans->reconcileForSpace($this->space->id, $this->club->id);

        $this->assertSame(0, $fan->desired_power);
        $this->assertSame(0, $fan->applied_power);
    }

    public function test_active_session_turns_fan_on(): void
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

        $this->assertSame(100, $fan->desired_power);
        $this->assertSame(100, $fan->applied_power);
        $this->assertTrue($fan->isOn());
    }

    public function test_session_on_any_pc_keeps_room_fan_on(): void
    {
        Booking::create([
            'user_id' => User::create([
                'name' => 'Player B',
                'phone' => '+79991110002',
                'email' => 'fan2@example.test',
                'password' => 'password',
            ])->id,
            'computer_id' => $this->pcB->id,
            'pc_ids' => [(string) $this->pcB->id],
            'date' => now()->toDateString(),
            'start_time' => 12.0,
            'duration' => 2,
            'price' => 100,
            'status' => 'active',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
        ]);

        $fan = $this->fans->reconcileForComputer($this->pcA->id);

        $this->assertSame(100, $fan->applied_power);
    }

    public function test_thermal_hysteresis(): void
    {
        $this->fans->reportThermal($this->pcA->id, 70.0);
        $this->fan->refresh();
        $this->assertSame(0, $this->fan->applied_power);
        $this->assertFalse(ComputerThermal::where('computer_id', $this->pcA->id)->first()->is_hot);

        $this->fans->reportThermal($this->pcA->id, 75.0);
        $this->fan->refresh();
        $this->assertSame(100, $this->fan->applied_power);
        $this->assertTrue(ComputerThermal::where('computer_id', $this->pcA->id)->first()->is_hot);

        $this->fans->reportThermal($this->pcA->id, 70.0);
        $this->fan->refresh();
        $this->assertSame(100, $this->fan->applied_power);

        $this->fans->reportThermal($this->pcA->id, 65.0);
        $this->fan->refresh();
        $this->assertSame(0, $this->fan->applied_power);
        $this->assertFalse(ComputerThermal::where('computer_id', $this->pcA->id)->first()->is_hot);
    }

    public function test_manual_force_off_overrides_session(): void
    {
        Booking::create([
            'user_id' => User::create([
                'name' => 'Player C',
                'phone' => '+79991110003',
                'email' => 'fan3@example.test',
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

        $this->fans->reconcileForComputer($this->pcA->id);
        $this->fans->setManualModeForComputer($this->pcB->id, 'off');

        $this->fan->refresh();
        $this->assertSame(SpaceFan::MODE_FORCE_OFF, $this->fan->manual_mode);
        $this->assertSame(0, $this->fan->applied_power);
    }

    public function test_parallel_manual_on_from_other_pc(): void
    {
        $this->fans->setManualModeForComputer($this->pcA->id, 'off');
        $this->fans->setManualModeForComputer($this->pcB->id, 'on');

        $this->fan->refresh();
        $this->assertSame(SpaceFan::MODE_FORCE_ON, $this->fan->manual_mode);
        $this->assertSame(100, $this->fan->applied_power);
    }

    public function test_shell_thermal_and_fan_endpoints(): void
    {
        $this->postJson('/api/shell/thermal', [
            'terminal_id' => $this->pcA->id,
            'cpu_c' => 80,
        ])->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('fan.is_on', true);

        $this->postJson('/api/shell/fan', [
            'terminal_id' => $this->pcB->id,
            'action' => 'off',
        ])->assertOk()
            ->assertJsonPath('fan.manual_mode', 'force_off')
            ->assertJsonPath('fan.is_on', false);

        $this->getJson('/api/shell/fan?terminal_id='.$this->pcA->id)
            ->assertOk()
            ->assertJsonPath('fan.available', true)
            ->assertJsonPath('fan.club_id', $this->club->id);
    }

    public function test_kincony_http_called_with_channel(): void
    {
        Http::fake([
            '192.168.1.50/*' => Http::response('OK', 200),
        ]);

        $this->fans->setManualModeForComputer($this->pcA->id, 'on');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '192.168.1.50')
                && str_contains($request->url(), 'Relay03=ON');
        });
    }
}
