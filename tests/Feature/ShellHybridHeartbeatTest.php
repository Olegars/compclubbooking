<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Computer;
use App\Services\ComputerPowerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShellHybridHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    private Computer $computer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create(['name' => 'Hybrid Club', 'slug' => 'hybrid-club']);
        $this->computer = Computer::create([
            'club_id' => $this->club->id,
            'name' => 'PC-01',
            'status' => 'available',
            'kind' => 'pc',
            'hwid' => 'aabbccdd-eeff-1122-3344-556677889900',
        ]);
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
}
