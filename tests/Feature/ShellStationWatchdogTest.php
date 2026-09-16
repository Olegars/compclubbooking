<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Club;
use App\Models\Computer;
use App\Services\OwnerSystemTestService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShellStationWatchdogTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    private Computer $computer;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'club.power.warmup_minutes' => 30,
            'club.power.heartbeat_stale_seconds' => 180,
        ]);

        $this->club = Club::create(['name' => 'Watch Club', 'slug' => 'watch-club']);
        $this->computer = Computer::create([
            'club_id' => $this->club->id,
            'name' => 'ПК-04',
            'status' => 'available',
            'kind' => 'pc',
            'hwid' => 'watchdog-hwid-0004',
        ]);
        $this->admin = Admin::query()->create([
            'name' => 'Owner',
            'email' => 'watch.owner@test.local',
            'password' => 'password',
            'role' => 'owner',
            'pay_type' => 'shift',
            'club_id' => $this->club->id,
        ]);
    }

    public function test_shell_posts_fan_bearing_incident_to_admin_feed(): void
    {
        $this->postJson('/api/shell/incidents', [
            'terminal_id' => $this->computer->id,
            'type' => 'fan_bearing_wear',
            'severity' => 'medium',
            'payload' => ['peak_hz' => 6120, 'fan_speed' => 3],
        ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('created', true);

        $row = DB::table('incidents')->first();
        $this->assertNotNull($row);
        $this->assertSame('fan_bearing_wear', $row->type);
        $this->assertSame($this->computer->id, (int) $row->computer_id);
        $this->assertStringContainsString('ПК-04', $row->description);
        $this->assertStringContainsString('смазка', $row->description);

        $this->actingAs($this->admin, 'admin')
            ->get('/admin/incidents')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Incidents')
                ->has('incidents', 1)
                ->where('incidents.0.type', 'fan_bearing_wear')
                ->where('incidents.0.type_label', 'Износ подшипника вентилятора')
                ->where('incidents.0.pc_name', 'ПК-04')
            );
    }

    public function test_bearing_incident_is_deduped_while_open(): void
    {
        $this->postJson('/api/shell/incidents', [
            'hwid' => $this->computer->hwid,
            'type' => 'fan_bearing_wear',
        ])->assertOk()->assertJsonPath('created', true);

        $this->postJson('/api/shell/incidents', [
            'hwid' => $this->computer->hwid,
            'type' => 'fan_bearing_wear',
            'description' => 'Подшипник SpaceFan на ПК-04 изношен, требуется смазка',
        ])->assertOk()->assertJsonPath('created', false);

        $this->assertSame(1, DB::table('incidents')->count());
    }

    public function test_golden_image_drift_queues_silent_resync_via_heartbeat(): void
    {
        $this->postJson('/api/shell/incidents', [
            'terminal_id' => $this->computer->id,
            'type' => 'golden_image_drift',
            'severity' => 'high',
            'description' => 'На ПК-04 повреждены файлы игрового диска — нужен тихий re-sync',
            'payload' => ['files' => ['D:/Steam/EasyAntiCheat/EasyAntiCheat.exe']],
        ])->assertOk();

        $this->postJson('/api/shell/power/heartbeat', [
            'hwid' => $this->computer->hwid,
            'integrity_status' => 'drift',
            'integrity_hash' => 'abc123',
            'integrity_message' => '2 файла не совпали с эталоном',
            'gpu_mode' => 'idle',
            'gpu_power_limit_w' => 45,
        ])->assertOk();

        $this->computer->refresh();
        $this->assertSame('drift', $this->computer->integrity_status);
        $this->assertSame(45, (int) $this->computer->gpu_power_limit_w);
        $this->assertSame('idle', $this->computer->gpu_mode);

        $this->actingAs($this->admin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/admin/api/computers/resync', [
                'computer_id' => $this->computer->id,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('action', 'resync_files');

        $this->computer->refresh();
        $commandId = (int) $this->computer->resync_command_id;
        $this->assertSame('resync_files', $this->computer->resync_command);
        $this->assertSame('resyncing', $this->computer->integrity_status);

        $this->postJson('/api/shell/power/heartbeat', [
            'hwid' => $this->computer->hwid,
        ])->assertOk()
            ->assertJsonPath('power_action', 'none')
            ->assertJsonPath('resync.action', 'resync_files')
            ->assertJsonPath('resync.command_id', $commandId);

        $this->postJson('/api/shell/power/heartbeat', [
            'hwid' => $this->computer->hwid,
            'resync_ack_id' => $commandId,
            'resync_result' => 'ok',
            'resync_message' => 'Скопировано 2 файла',
            'integrity_status' => 'ok',
        ])->assertOk()
            ->assertJsonPath('resync', null);

        $this->computer->refresh();
        $this->assertNull($this->computer->resync_command);
        $this->assertSame('ok', $this->computer->resync_result);
        $this->assertSame('ok', $this->computer->integrity_status);
    }

    public function test_offline_pc_cannot_queue_resync(): void
    {
        $this->assertNull($this->computer->fresh()->last_seen_at);

        $this->actingAs($this->admin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/admin/api/computers/resync', [
                'computer_id' => $this->computer->id,
            ])
            ->assertStatus(422);
    }

    public function test_station_health_warns_on_golden_image_drift(): void
    {
        Computer::query()->where('id', $this->computer->id)->update([
            'last_seen_at' => now(),
            'power_state' => 'on',
            'cache_ok' => true,
            'nic_link_mbps' => 1000,
            'ssd_health' => 'healthy',
            'integrity_status' => 'drift',
        ]);

        $result = app(OwnerSystemTestService::class)->run('station_health', $this->club);
        $this->assertSame('warn', $result['status']);
        $this->assertStringContainsString('повреждёнными файлами', $result['message']);
    }
}
