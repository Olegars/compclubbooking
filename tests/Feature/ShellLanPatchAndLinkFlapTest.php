<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Club;
use App\Models\Computer;
use App\Models\Shift;
use App\Services\OwnerSystemTestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShellLanPatchAndLinkFlapTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    private Computer $seed;

    private Computer $peer;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'club.power.warmup_minutes' => 30,
            'club.power.heartbeat_stale_seconds' => 180,
        ]);

        $this->club = Club::create(['name' => 'Patch Club', 'slug' => 'patch-club', 'type' => 'club']);
        $this->seed = Computer::create([
            'club_id' => $this->club->id,
            'name' => 'ПК-01',
            'status' => 'available',
            'kind' => 'pc',
            'hwid' => 'seed-hwid-0001',
        ]);
        $this->peer = Computer::create([
            'club_id' => $this->club->id,
            'name' => 'ПК-02',
            'status' => 'available',
            'kind' => 'pc',
            'hwid' => 'peer-hwid-0002',
        ]);
        $this->admin = Admin::query()->create([
            'name' => 'Owner',
            'email' => 'patch.owner@test.local',
            'password' => 'password',
            'role' => 'owner',
            'pay_type' => 'shift',
            'club_id' => $this->club->id,
        ]);
    }

    public function test_two_link_flaps_in_shift_create_patch_cord_incident(): void
    {
        Shift::query()->create([
            'admin_id' => $this->admin->id,
            'status' => 'open',
            'started_at' => now()->subHour(),
            'cash_start' => 0,
        ]);

        $this->postJson('/api/shell/power/heartbeat', [
            'hwid' => $this->peer->hwid,
            'nic_link_mbps' => 100,
            'nic_flap_events' => 1,
            'nic_flap_payload' => ['from_mbps' => 1000, 'to_mbps' => 100],
        ])->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('nic_flap_acked', 1);

        $this->peer->refresh();
        $this->assertSame(1, (int) $this->peer->nic_flap_count);
        $this->assertSame(0, DB::table('incidents')->count());

        $this->postJson('/api/shell/power/heartbeat', [
            'hwid' => $this->peer->hwid,
            'nic_link_mbps' => 100,
            'nic_flap_events' => 1,
            'nic_flap_payload' => ['from_mbps' => 1000, 'to_mbps' => 100],
        ])->assertOk()
            ->assertJsonPath('nic_flap_acked', 1);

        $this->peer->refresh();
        $this->assertSame(2, (int) $this->peer->nic_flap_count);

        $row = DB::table('incidents')->first();
        $this->assertNotNull($row);
        $this->assertSame('nic_link_flap', $row->type);
        $this->assertSame($this->peer->id, (int) $row->computer_id);
        $this->assertStringContainsString('Заменить патч-корд на ПК-02', $row->description);

        $this->actingAs($this->admin, 'admin')
            ->get('/admin/incidents')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Incidents')
                ->where('incidents.0.type', 'nic_link_flap')
                ->where('incidents.0.type_label', 'Деградация патч-корда')
            );
    }

    public function test_seed_offers_patch_pull_when_peer_build_is_older(): void
    {
        $this->postJson('/api/shell/power/heartbeat', [
            'hwid' => $this->seed->hwid,
            'super_client' => true,
            'lan_ip' => '192.168.20.51',
            'patch_seed_port' => 8745,
            'games_inventory_hash' => 'seedhash',
            'games_inventory' => [
                ['p' => 'steam', 'id' => '730', 'b' => '200', 'n' => 'Counter-Strike 2'],
            ],
        ])->assertOk()
            ->assertJsonPath('patch_seed.enabled', true)
            ->assertJsonPath('patch_seed.port', 8745);

        $this->seed->refresh();
        $this->assertSame('192.168.20.51', $this->seed->lan_ip);
        $this->assertSame(8745, (int) $this->seed->patch_seed_port);

        $response = $this->postJson('/api/shell/power/heartbeat', [
            'hwid' => $this->peer->hwid,
            'lan_ip' => '192.168.20.52',
            'games_inventory_hash' => 'peerhash',
            'games_inventory' => [
                ['p' => 'steam', 'id' => '730', 'b' => '100', 'n' => 'Counter-Strike 2'],
            ],
        ])->assertOk();

        $response->assertJsonPath('status', 'success');
        $pull = $response->json('patch_pull');
        $this->assertIsArray($pull);
        $this->assertNotEmpty($pull['apps'] ?? []);
        $this->assertSame('730', $pull['apps'][0]['id']);
        $this->assertSame('200', $pull['apps'][0]['b']);
        $this->assertSame('192.168.20.51', $pull['apps'][0]['peer_ip']);
        $this->assertSame(8745, $pull['apps'][0]['peer_port']);

        $commandId = (int) $pull['command_id'];
        $this->postJson('/api/shell/power/heartbeat', [
            'hwid' => $this->peer->hwid,
            'patch_pull_ack_id' => $commandId,
            'patch_pull_result' => 'ok',
            'patch_pull_message' => 'Стянуто 1/1',
            'games_inventory' => [
                ['p' => 'steam', 'id' => '730', 'b' => '200', 'n' => 'Counter-Strike 2'],
            ],
        ])->assertOk()
            ->assertJsonPath('patch_pull', null);

        $this->peer->refresh();
        $this->assertNull($this->peer->patch_pull_command_id);
        $this->assertSame('ok', $this->peer->patch_pull_result);
    }

    public function test_station_health_warns_on_nic_flap_threshold(): void
    {
        Computer::query()->where('id', $this->peer->id)->update([
            'last_seen_at' => now(),
            'power_state' => 'on',
            'cache_ok' => true,
            'nic_link_mbps' => 1000,
            'nic_flap_count' => 2,
            'ssd_health' => 'healthy',
        ]);

        $result = app(OwnerSystemTestService::class)->run('station_health', $this->club);
        $this->assertSame('warn', $result['status']);
        $this->assertStringContainsString('патч-корда', $result['message']);
    }
}
