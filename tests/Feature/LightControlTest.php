<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Club;
use App\Models\Computer;
use App\Models\DmxNode;
use App\Models\Space;
use App\Models\SpaceLight;
use App\Models\User;
use App\Models\Zone;
use App\Services\Light\LightControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LightControlTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    private Space $space;

    private Computer $pcA;

    private Computer $pcB;

    private DmxNode $node;

    private SpaceLight $light;

    private LightControlService $lights;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response('OK', 200)]);

        $this->club = Club::create([
            'name' => 'Light Club',
            'slug' => 'light-club',
        ]);

        $zone = Zone::create([
            'name' => 'Single',
            'slug' => 'single-light',
        ]);

        $this->space = Space::create([
            'club_id' => $this->club->id,
            'zone_id' => $zone->id,
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
            'power_state' => 'off',
        ]);

        $this->pcB = Computer::create([
            'club_id' => $this->club->id,
            'space_id' => $this->space->id,
            'name' => 'PC-B',
            'status' => 'available',
            'power_state' => 'off',
        ]);

        $this->node = DmxNode::create([
            'club_id' => $this->club->id,
            'name' => 'ArtNet-1',
            'host' => '192.168.20.50',
            'port' => 6454,
            'universe' => 0,
            'is_active' => true,
        ]);

        $this->light = SpaceLight::create([
            'club_id' => $this->club->id,
            'space_id' => $this->space->id,
            'dmx_node_id' => $this->node->id,
            'start_channel' => 1,
            'fixture_count' => 4,
            'layout' => SpaceLight::LAYOUT_RGB,
            'desired_color' => 'white',
            'desired_brightness' => 0,
            'desired_effect' => SpaceLight::EFFECT_NONE,
            'last_on_color' => 'white',
            'last_on_brightness' => 80,
            'last_on_effect' => SpaceLight::EFFECT_NONE,
            'vacant' => true,
        ]);

        $this->lights = app(LightControlService::class);
    }

    public function test_all_pcs_off_keeps_lights_off(): void
    {
        $light = $this->lights->reconcileForSpace($this->space->id, $this->club->id);

        $this->assertSame(0, $light->desired_brightness);
        $this->assertTrue((bool) $light->vacant);
        Http::assertNothingSent();
    }

    public function test_pc_boot_sets_lobby_white_without_session(): void
    {
        $this->pcA->update(['power_state' => 'on', 'last_seen_at' => now()]);

        $light = $this->lights->reconcileForComputer($this->pcA->id);

        $this->assertSame('white', $light->desired_color);
        $this->assertSame(80, $light->desired_brightness);
        $this->assertFalse((bool) $light->vacant);
        Http::assertNothingSent();
    }

    public function test_empty_session_does_not_turn_off_while_pc_on(): void
    {
        $this->pcA->update(['power_state' => 'on', 'last_seen_at' => now()]);
        $this->light->update([
            'vacant' => false,
            'desired_color' => 'red',
            'desired_brightness' => 60,
        ]);

        $light = $this->lights->reconcileForSpace($this->space->id, $this->club->id);

        $this->assertSame('white', $light->desired_color);
        $this->assertSame(80, $light->desired_brightness);
        $this->assertGreaterThan(0, $light->desired_brightness);
    }

    public function test_first_login_fades_to_green(): void
    {
        $this->pcA->update(['power_state' => 'on', 'last_seen_at' => now()]);
        $user = User::create([
            'name' => 'Newbie',
            'phone' => '+79991110003',
            'email' => 'light-new@example.test',
            'password' => 'password',
        ]);
        $booking = $this->makeActiveBooking($user);

        $result = $this->lights->applyLoginScene($this->pcA->id, $user, (int) $booking->id);
        $state = $this->lights->statePayload($result['light'], $this->pcA->id);

        $this->assertTrue($result['first_visit']);
        $this->assertSame('green', $result['light']->desired_color);
        $this->assertSame(80, $result['light']->desired_brightness);
        $this->assertGreaterThan(0, $state['fade_ms']);
        $this->assertSame('green', $user->fresh()->lightScene()['color']);
    }

    public function test_returning_login_uses_saved_color(): void
    {
        $this->pcA->update(['power_state' => 'on', 'last_seen_at' => now()]);
        $user = User::create([
            'name' => 'Regular',
            'phone' => '+79991110004',
            'email' => 'light-old@example.test',
            'password' => 'password',
        ]);
        $user->saveLightScene('purple', 55, 'none');
        Booking::create([
            'user_id' => $user->id,
            'computer_id' => $this->pcB->id,
            'pc_ids' => [(string) $this->pcB->id],
            'date' => now()->subDay()->toDateString(),
            'start_time' => 12.0,
            'duration' => 2,
            'price' => 100,
            'status' => 'completed',
            'actual_started_at' => now()->subDay(),
            'starts_at' => now()->subDay(),
            'ends_at' => now()->subDay()->addHours(2),
        ]);
        $booking = $this->makeActiveBooking($user);

        $result = $this->lights->applyLoginScene($this->pcA->id, $user, (int) $booking->id);

        $this->assertFalse($result['first_visit']);
        $this->assertSame('purple', $result['light']->desired_color);
        $this->assertSame(55, $result['light']->desired_brightness);
    }

    public function test_any_pc_in_room_sets_color(): void
    {
        $this->pcA->update(['power_state' => 'on', 'last_seen_at' => now()]);
        $this->pcB->update(['power_state' => 'on', 'last_seen_at' => now()]);
        $this->light->update(['vacant' => false, 'desired_brightness' => 80]);

        $result = $this->lights->setSceneForComputer($this->pcB->id, 'red', 60);

        $this->assertFalse($result['locked']);
        $this->assertSame('red', $result['light']->desired_color);
        $this->assertSame(60, $result['light']->desired_brightness);

        $state = $this->lights->stateForComputer($this->pcA->id);
        $this->assertTrue($state['available']);
        $this->assertSame('red', $state['color']);
        Http::assertNothingSent();
    }

    public function test_rainbow_and_ack_and_shell_endpoints(): void
    {
        $this->pcA->update(['power_state' => 'on', 'last_seen_at' => now()]);
        $this->light->update(['vacant' => false, 'desired_brightness' => 80, 'desired_color' => 'white']);

        $this->postJson('/api/shell/light', [
            'terminal_id' => $this->pcA->id,
            'color' => 'rainbow',
        ])->assertOk()
            ->assertJsonPath('light.effect', 'rainbow')
            ->assertJsonPath('light.color', 'rainbow');

        $this->postJson('/api/shell/light/applied', [
            'terminal_id' => $this->pcA->id,
            'applied_color' => 'rainbow',
            'applied_brightness' => 80,
            'applied_effect' => 'rainbow',
        ])->assertOk()
            ->assertJsonPath('light.applied_effect', 'rainbow');
    }

    public function test_manual_cooldown_locks_other_change(): void
    {
        $this->pcA->update(['power_state' => 'on', 'last_seen_at' => now()]);
        $this->pcB->update(['power_state' => 'on', 'last_seen_at' => now()]);
        $this->light->update([
            'vacant' => false,
            'desired_color' => 'white',
            'desired_brightness' => 80,
            'last_manual_at' => now(),
            'last_manual_by_computer_id' => $this->pcA->id,
        ]);

        $result = $this->lights->setSceneForComputer($this->pcB->id, 'blue', 40);
        $this->assertTrue($result['locked']);
        $this->assertSame('white', $this->light->fresh()->desired_color);
    }

    public function test_channel_overlap_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->lights->assertChannelsFree((int) $this->node->id, 2, 6);
    }

    private function makeActiveBooking(User $user): Booking
    {
        return Booking::create([
            'user_id' => $user->id,
            'computer_id' => $this->pcA->id,
            'pc_ids' => [(string) $this->pcA->id],
            'date' => now()->toDateString(),
            'start_time' => 12.0,
            'duration' => 2,
            'price' => 100,
            'status' => 'active',
            'actual_started_at' => now(),
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
        ]);
    }
}
