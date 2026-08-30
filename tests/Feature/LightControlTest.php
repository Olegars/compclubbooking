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
        ]);

        $this->pcB = Computer::create([
            'club_id' => $this->club->id,
            'space_id' => $this->space->id,
            'name' => 'PC-B',
            'status' => 'available',
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

    public function test_empty_room_stays_off_without_http(): void
    {
        $light = $this->lights->reconcileForSpace($this->space->id, $this->club->id);

        $this->assertSame(0, $light->desired_brightness);
        $this->assertTrue((bool) $light->vacant);
        Http::assertNothingSent();
    }

    public function test_session_restores_last_on_without_actuator(): void
    {
        Booking::create([
            'user_id' => User::create([
                'name' => 'Player',
                'phone' => '+79991110002',
                'email' => 'light1@example.test',
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

        $light = $this->lights->reconcileForComputer($this->pcA->id);

        $this->assertSame(80, $light->desired_brightness);
        $this->assertSame('white', $light->desired_color);
        $this->assertFalse((bool) $light->vacant);
        Http::assertNothingSent();
    }

    public function test_any_pc_in_room_sets_color(): void
    {
        $this->light->update(['vacant' => false, 'desired_brightness' => 80]);

        $result = $this->lights->setSceneForComputer($this->pcB->id, 'red', 60);

        $this->assertFalse($result['locked']);
        $this->assertSame('red', $result['light']->desired_color);
        $this->assertSame(60, $result['light']->desired_brightness);
        $this->assertSame($this->pcB->id, $result['light']->last_manual_by_computer_id);

        $state = $this->lights->stateForComputer($this->pcA->id);
        $this->assertTrue($state['available']);
        $this->assertSame('red', $state['color']);
        $this->assertSame(60, $state['brightness']);
        $this->assertSame('192.168.20.50', $state['node']['host']);
        $this->assertSame(1, $state['nodes'][0]['fixtures'][0]['start']);
        $this->assertSame(4, $state['nodes'][0]['fixtures'][0]['count']);
        Http::assertNothingSent();
    }

    public function test_rainbow_and_ack_and_shell_endpoints(): void
    {
        $this->light->update(['vacant' => false, 'desired_brightness' => 80]);

        $this->postJson('/api/shell/light', [
            'terminal_id' => $this->pcA->id,
            'color' => 'rainbow',
        ])->assertOk()
            ->assertJsonPath('light.effect', 'rainbow')
            ->assertJsonPath('light.color', 'rainbow')
            ->assertJsonPath('light.node.host', '192.168.20.50');

        $this->postJson('/api/shell/light/applied', [
            'terminal_id' => $this->pcA->id,
            'applied_color' => 'rainbow',
            'applied_brightness' => 80,
            'applied_effect' => 'rainbow',
        ])->assertOk()
            ->assertJsonPath('light.applied_effect', 'rainbow');

        $this->getJson('/api/shell/light?terminal_id='.$this->pcA->id)
            ->assertOk()
            ->assertJsonPath('light.available', true)
            ->assertJsonPath('light.club_id', $this->club->id);
    }

    public function test_manual_cooldown_locks_other_change(): void
    {
        $this->light->update([
            'vacant' => false,
            'desired_brightness' => 80,
            'last_manual_at' => now(),
            'last_manual_by_computer_id' => $this->pcA->id,
        ]);

        $result = $this->lights->setSceneForComputer($this->pcB->id, 'blue', 40);
        $this->assertTrue($result['locked']);
        $this->assertGreaterThan(0, $result['remaining_sec']);
        $this->assertSame('white', $this->light->fresh()->desired_color);
    }

    public function test_channel_overlap_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->lights->assertChannelsFree((int) $this->node->id, 2, 6);
    }
}
