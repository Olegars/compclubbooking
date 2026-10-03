<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Booking;
use App\Models\Club;
use App\Models\Computer;
use App\Models\Order;
use App\Models\User;
use App\Models\WledController;
use App\Models\WledCue;
use App\Services\Light\WledCorridorCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WledCorridorTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    private Computer $pc;

    private User $guest;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create([
            'name' => 'WLED Club',
            'slug' => 'wled-club',
        ]);
        $this->pc = Computer::create([
            'club_id' => $this->club->id,
            'name' => 'PC-1',
            'status' => 'available',
            'power_state' => 'on',
        ]);
        $this->guest = User::create([
            'name' => 'Guest',
            'phone' => '+7999'.random_int(1000000, 9999999),
            'email' => 'wled-'.uniqid().'@example.test',
            'password' => 'password',
        ]);
    }

    public function test_order_queues_orange_blink_on_four_outputs(): void
    {
        $controller = $this->controller();

        Order::create([
            'user_id' => $this->guest->id,
            'product_name' => 'Энергетик',
            'price' => 100,
            'pc_name' => 'PC-1',
            'status' => Order::STATUS_PENDING,
        ]);

        $cue = WledCue::query()->first();
        $this->assertNotNull($cue);
        $this->assertSame('bar.order', $cue->event_id);
        $this->assertSame((int) $controller->id, (int) $cue->wled_controller_id);
        $play = $cue->payload['play'];
        $this->assertCount(4, $play['seg']);
        $this->assertSame([255, 90, 0], $play['seg'][0]['col'][0]);
        $this->assertSame(1, $play['seg'][0]['fx']);
        $this->assertSame(160, $play['seg'][0]['sx']);
        $this->assertTrue($play['seg'][3]['on']);
        $this->assertSame(8000, $cue->payload['duration_ms']);
        $this->assertFalse($cue->payload['idle']['on']);
    }

    public function test_scheduled_order_waits_until_it_is_released(): void
    {
        $this->controller();

        $order = Order::create([
            'user_id' => $this->guest->id,
            'product_name' => 'Латте',
            'price' => 80,
            'pc_name' => 'PC-1',
            'status' => Order::STATUS_SCHEDULED,
        ]);
        $this->assertSame(0, WledCue::query()->count());

        $order->update(['status' => Order::STATUS_PENDING]);
        $this->assertSame(1, WledCue::query()->count());
    }

    public function test_second_order_coalesces_until_the_first_cue_is_claimed(): void
    {
        $this->controller();
        $this->makeOrder();
        $this->makeOrder();
        $this->assertSame(1, WledCue::query()->count());

        $this->getJson('/api/shell/wled/cues?terminal_id='.$this->pc->id)->assertOk();
        $this->makeOrder();
        $this->assertSame(2, WledCue::query()->count());
    }

    public function test_shell_claims_once_and_acks(): void
    {
        $this->controller();
        $this->makeOrder();

        $first = $this->getJson('/api/shell/wled/cues?terminal_id='.$this->pc->id)
            ->assertOk()
            ->assertJsonPath('cues.0.event_id', 'bar.order')
            ->assertJsonPath('cues.0.host', '192.168.20.40')
            ->assertJsonPath('cues.0.port', 80);

        $cueId = (int) $first->json('cues.0.id');
        $this->assertNotNull(WledCue::query()->find($cueId)->claimed_at);

        $this->getJson('/api/shell/wled/cues?terminal_id='.$this->pc->id)
            ->assertOk()
            ->assertJsonPath('cues', []);

        $other = Computer::create([
            'club_id' => $this->club->id,
            'name' => 'PC-2',
            'status' => 'available',
            'power_state' => 'on',
        ]);
        $this->postJson('/api/shell/wled/cues/'.$cueId.'/ack', [
            'terminal_id' => $other->id,
            'ok' => true,
        ])->assertStatus(409);

        $this->postJson('/api/shell/wled/cues/'.$cueId.'/ack', [
            'terminal_id' => $this->pc->id,
            'ok' => false,
            'error' => 'timeout',
        ])->assertOk();

        $cue = WledCue::query()->find($cueId);
        $this->assertNotNull($cue->played_at);
        $this->assertSame('timeout', $cue->last_error);
    }

    public function test_selected_channels_and_disabled_event(): void
    {
        $controller = $this->controller();
        $map = WledCorridorCatalog::defaultMap();
        $map['bar.order']['channels'] = [1, 3];
        $map['sos']['enabled'] = false;
        $controller->update(['bindings' => $map]);

        $this->makeOrder();
        $play = WledCue::query()->first()->payload['play'];
        $this->assertTrue($play['seg'][0]['on']);
        $this->assertFalse($play['seg'][1]['on']);
        $this->assertTrue($play['seg'][2]['on']);
        $this->assertFalse($play['seg'][3]['on']);

        $this->postJson('/api/shell/sos', [
            'computer_id' => $this->pc->id,
            'reason' => ['code' => 'other', 'label' => 'Нужна помощь'],
        ])->assertOk();

        $this->assertSame(1, WledCue::query()->count());
    }

    public function test_booking_flash_is_one_per_burst_when_enabled(): void
    {
        $controller = $this->controller();
        $map = WledCorridorCatalog::defaultMap();
        $map['booking.new']['enabled'] = true;
        $controller->update(['bindings' => $map]);

        $this->booking();
        $this->booking();
        $this->assertSame(1, WledCue::query()->where('event_id', 'booking.new')->count());
    }

    public function test_shell_reads_effect_list_and_admin_can_play_one(): void
    {
        $controller = $this->controller();
        $controller->update(['effects_sync_requested_at' => now()]);

        $sync = $this->getJson('/api/shell/wled/cues?terminal_id='.$this->pc->id)
            ->assertOk()
            ->json('sync');
        $this->assertSame((int) $controller->id, (int) $sync[0]['id']);
        $this->assertSame('192.168.20.40', $sync[0]['host']);

        $this->getJson('/api/shell/wled/cues?terminal_id='.$this->pc->id)
            ->assertOk()
            ->assertJsonPath('sync', []);

        $this->postJson('/api/shell/wled/'.$controller->id.'/effects', [
            'terminal_id' => $this->pc->id,
            'ok' => true,
            'effects' => ['Solid', 'Blink', 'Breathe', 'Rainbow'],
        ])->assertOk();

        $controller->refresh();
        $this->assertSame(['Solid', 'Blink', 'Breathe', 'Rainbow'], $controller->effects);
        $this->assertNull($controller->effects_sync_requested_at);
        $this->assertNotNull($controller->effects_synced_at);

        $map = WledCorridorCatalog::defaultMap();
        $map['bar.order']['fx'] = 3;
        $map['bar.order']['sx'] = 90;
        $controller->update(['bindings' => $map]);
        $this->makeOrder();

        $play = WledCue::query()->first()->payload['play'];
        $this->assertSame(3, $play['seg'][0]['fx']);
        $this->assertSame(90, $play['seg'][0]['sx']);
    }

    public function test_admin_adds_controller_on_the_lights_page(): void
    {
        $admin = Admin::query()->create([
            'name' => 'Supervisor',
            'email' => 'wled-'.uniqid().'@admin.test',
            'password' => 'password',
            'role' => 'supervisor',
            'club_id' => $this->club->id,
            'base_rate' => 2000,
            'pay_type' => 'shift',
            'employment_pending' => false,
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin/lights?tab=corridor&club_id='.$this->club->id)
            ->assertOk();

        $this->actingAs($admin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/admin/lights/wled', [
                'club_id' => $this->club->id,
                'name' => 'Коридор у бара',
                'host' => 'http://192.168.20.41:8080',
                'http_port' => 80,
            ])
            ->assertRedirect();

        $saved = WledController::query()->where('name', 'Коридор у бара')->first();
        $this->assertNotNull($saved);
        $this->assertSame('192.168.20.41', $saved->host);
        $this->assertSame(8080, (int) $saved->http_port);
        $this->assertTrue($saved->binding('bar.order')['enabled']);
        $this->assertFalse($saved->binding('booking.new')['enabled']);
    }

    private function controller(): WledController
    {
        return WledController::create([
            'club_id' => $this->club->id,
            'name' => 'Коридор',
            'host' => '192.168.20.40',
            'http_port' => 80,
            'is_active' => true,
            'idle_on' => false,
            'idle_color' => 'white',
            'idle_brightness' => 15,
            'bindings' => WledCorridorCatalog::defaultMap(),
        ]);
    }

    private function makeOrder(): Order
    {
        return Order::create([
            'user_id' => $this->guest->id,
            'product_name' => 'Энергетик',
            'price' => 100,
            'pc_name' => 'PC-1',
            'status' => Order::STATUS_PENDING,
        ]);
    }

    private function booking(): Booking
    {
        return Booking::create([
            'user_id' => $this->guest->id,
            'computer_id' => $this->pc->id,
            'pc_ids' => [$this->pc->id],
            'date' => now()->toDateString(),
            'start_time' => 12,
            'duration' => 1,
            'price' => 100,
            'status' => 'confirmed',
        ]);
    }
}
