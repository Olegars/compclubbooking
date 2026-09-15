<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Booking;
use App\Models\BookingGroup;
use App\Models\Club;
use App\Models\Computer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DisklessCommandTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    private Computer $computer;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'club.booking.late_start_grace_minutes' => 30,
            'club.power.warmup_minutes' => 30,
            'club.power.heartbeat_stale_seconds' => 180,
        ]);

        $this->club = Club::create(['name' => 'Diskless Club', 'slug' => 'diskless-club']);
        $this->computer = Computer::create([
            'club_id' => $this->club->id,
            'name' => 'PC-08',
            'status' => 'available',
            'kind' => 'pc',
            'hwid' => 'diskless-hwid-0001',
        ]);
        $this->admin = Admin::query()->create([
            'name' => 'Supervisor',
            'email' => 'diskless.supervisor@test.local',
            'password' => 'password',
            'role' => 'supervisor',
            'pay_type' => 'shift',
        ]);
    }

    public function test_heartbeat_delivers_and_acks_super_client_command(): void
    {
        $this->postJson('/api/shell/power/heartbeat', [
            'hwid' => $this->computer->hwid,
        ])->assertOk();

        $this->actingAs($this->admin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/admin/api/computers/diskless', [
                'computer_id' => $this->computer->id,
                'action' => 'enable_sc',
                'disk_mode' => 'image',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->computer->refresh();
        $commandId = (int) $this->computer->diskless_command_id;
        $this->assertSame('enable_sc', $this->computer->diskless_command);
        $this->assertTrue($this->computer->maintenance);

        $this->postJson('/api/shell/power/heartbeat', [
            'hwid' => $this->computer->hwid,
        ])->assertOk()
            ->assertJsonPath('power_action', 'none')
            ->assertJsonPath('diskless.action', 'enable_sc')
            ->assertJsonPath('diskless.disk_mode', 'image')
            ->assertJsonPath('diskless.command_id', $commandId);

        $this->postJson('/api/shell/power/heartbeat', [
            'hwid' => $this->computer->hwid,
            'diskless_ack_id' => $commandId,
            'diskless_result' => 'accepted',
            'diskless_message' => 'Команда принята',
            'super_client' => false,
        ])->assertOk()
            ->assertJsonPath('diskless', null);

        $this->computer->refresh();
        $this->assertNull($this->computer->diskless_command);
        $this->assertSame('accepted', $this->computer->diskless_result);
    }

    public function test_game_disk_requires_confirm(): void
    {
        $this->postJson('/api/shell/power/heartbeat', [
            'hwid' => $this->computer->hwid,
        ])->assertOk();

        $this->actingAs($this->admin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/admin/api/computers/diskless', [
                'computer_id' => $this->computer->id,
                'action' => 'enable_sc',
                'disk_mode' => 'disk',
            ])
            ->assertStatus(422);
    }

    public function test_second_super_client_is_blocked(): void
    {
        $this->postJson('/api/shell/power/heartbeat', [
            'hwid' => $this->computer->hwid,
        ])->assertOk();

        $other = Computer::create([
            'club_id' => $this->club->id,
            'name' => 'PC-09',
            'status' => 'maintenance',
            'kind' => 'pc',
            'hwid' => 'diskless-hwid-0002',
            'super_client' => true,
        ]);

        $this->actingAs($this->admin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/admin/api/computers/diskless', [
                'computer_id' => $this->computer->id,
                'action' => 'enable_sc',
                'disk_mode' => 'image',
            ])
            ->assertStatus(422);

        $this->assertDatabaseHas('computers', [
            'id' => $other->id,
            'super_client' => true,
        ]);
    }

    public function test_active_session_blocks_super_client(): void
    {
        $this->postJson('/api/shell/power/heartbeat', [
            'hwid' => $this->computer->hwid,
        ])->assertOk();

        $user = User::create([
            'name' => 'Guest',
            'phone' => '+79990001122',
            'email' => 'diskless-guest@test.local',
            'password' => 'password',
        ]);
        $starts = CarbonImmutable::now()->subMinutes(10);
        $ends = CarbonImmutable::now()->addHour();
        $group = BookingGroup::create([
            'user_id' => $user->id,
            'club_id' => $this->club->id,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'status' => 'active',
            'payment_status' => 'paid',
            'currency' => 'RUB',
            'computers_total_minor' => 10000,
            'games_total_minor' => 0,
            'total_minor' => 10000,
            'paid_total_minor' => 10000,
            'paid_at' => $starts,
        ]);
        Booking::create([
            'booking_group_id' => $group->id,
            'user_id' => $user->id,
            'computer_id' => $this->computer->id,
            'pc_ids' => [(string) $this->computer->id],
            'date' => $starts->timezone(config('app.timezone'))->toDateString(),
            'start_time' => 0,
            'duration' => 1,
            'price' => 100,
            'price_minor' => 10000,
            'status' => 'active',
            'pin_code' => '1234',
            'starts_at' => $starts,
            'ends_at' => $ends,
        ]);

        $this->actingAs($this->admin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/admin/api/computers/diskless', [
                'computer_id' => $this->computer->id,
                'action' => 'enable_sc',
                'disk_mode' => 'image',
            ])
            ->assertStatus(422);
    }

    public function test_offline_pc_is_rejected(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/admin/api/computers/diskless', [
                'computer_id' => $this->computer->id,
                'action' => 'enable_sc',
                'disk_mode' => 'image',
            ])
            ->assertStatus(422);
    }
}
