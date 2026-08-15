<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Club;
use App\Models\Computer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShellMapBindTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    private Computer $seat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create([
            'name' => 'Map Club',
            'slug' => 'map-club',
            'map_config' => [
                'walls' => [['d' => 'M0 0 L10 0']],
                'zoneRects' => [['x' => 0, 'y' => 0, 'w' => 20, 'h' => 20, 'type' => 'singl']],
            ],
        ]);
        $this->seat = Computer::create([
            'club_id' => $this->club->id,
            'name' => 'PC-01',
            'status' => 'available',
            'kind' => 'pc',
            'x' => 12.5,
            'y' => 8.0,
            'hwid' => 'old-machine-guid',
            'mac_address' => 'AA:BB:CC:DD:EE:01',
        ]);
    }

    public function test_register_by_name_keeps_map_seat(): void
    {
        $this->postJson('/api/shell/register-terminal', [
            'hwid' => 'new-smbios-uuid',
            'zone_type' => 'singl',
            'name' => 'PC-01',
            'mac_address' => 'AA:BB:CC:DD:EE:01',
        ])->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('terminal_id', $this->seat->id);

        $this->assertSame(1, Computer::query()->count());
        $this->seat->refresh();
        $this->assertSame('new-smbios-uuid', $this->seat->hwid);
        $this->assertEquals(12.5, (float) $this->seat->x);
        $this->assertEquals(8.0, (float) $this->seat->y);
        $this->assertSame('PC-01', $this->seat->name);
    }

    public function test_register_by_name_when_mac_unknown(): void
    {
        $this->postJson('/api/shell/register-terminal', [
            'hwid' => 'new-smbios-uuid',
            'zone_type' => 'singl',
            'name' => 'PC-01',
            'mac_address' => '11:22:33:44:55:66',
        ])->assertOk()
            ->assertJsonPath('terminal_id', $this->seat->id);

        $this->assertSame(1, Computer::query()->count());
        $this->seat->refresh();
        $this->assertSame('new-smbios-uuid', $this->seat->hwid);
        $this->assertEquals(12.5, (float) $this->seat->x);
        $this->assertSame('11:22:33:44:55:66', $this->seat->mac_address);
    }

    public function test_check_migrates_hwid_by_mac(): void
    {
        $this->postJson('/api/shell/check', [
            'hwid' => 'new-smbios-uuid',
            'mac_address' => 'AA:BB:CC:DD:EE:01',
        ])->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('computer_id', $this->seat->id)
            ->assertJsonPath('name', 'PC-01')
            ->assertJsonPath('club_name', 'Map Club');

        $this->seat->refresh();
        $this->assertSame('new-smbios-uuid', $this->seat->hwid);
        $this->assertSame(1, Computer::query()->count());
    }

    public function test_check_migrates_hwid_by_legacy_machine_guid(): void
    {
        $this->postJson('/api/shell/check', [
            'hwid' => 'new-smbios-uuid',
            'legacy_hwid' => 'old-machine-guid',
        ])->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('computer_id', $this->seat->id);

        $this->seat->refresh();
        $this->assertSame('new-smbios-uuid', $this->seat->hwid);
    }

    public function test_empty_save_map_does_not_wipe_club_computers(): void
    {
        $admin = Admin::create([
            'name' => 'Map Admin',
            'email' => 'map-admin@test.local',
            'password' => 'password',
            'role' => 'supervisor',
        ]);

        $this->actingAs($admin, 'admin')
            ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
            ->postJson('/admin/save-map', [
                'club_id' => $this->club->id,
                'config' => ['walls' => [], 'zoneRects' => []],
                'pcs' => [],
            ])
            ->assertStatus(422);

        $this->assertSame(1, Computer::query()->count());
        $this->assertDatabaseHas('computers', [
            'id' => $this->seat->id,
            'name' => 'PC-01',
        ]);
        $this->club->refresh();
        $this->assertNotEmpty($this->club->map_config['walls'] ?? []);
    }

    public function test_save_map_geometry_without_pcs_keeps_computers(): void
    {
        $admin = Admin::create([
            'name' => 'Map Admin',
            'email' => 'map-geom@test.local',
            'password' => 'password',
            'role' => 'supervisor',
        ]);

        $this->actingAs($admin, 'admin')
            ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
            ->postJson('/admin/save-map', [
                'club_id' => $this->club->id,
                'config' => [
                    'walls' => [['d' => 'M0 0 L40 0']],
                    'zoneRects' => [[
                        'x' => 0, 'y' => 0, 'w' => 16, 'h' => 10,
                        'type' => 'duo',
                    ]],
                    'labels' => [],
                    'viewbox' => '-10 -10 120 200',
                ],
                'pcs' => [],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertSame(1, Computer::query()->count());
        $this->assertDatabaseHas('computers', [
            'id' => $this->seat->id,
            'name' => 'PC-01',
        ]);
        $this->club->refresh();
        $this->assertSame('duo', $this->club->map_config['zoneRects'][0]['type'] ?? null);
    }

    public function test_save_map_still_removes_pcs_not_in_payload(): void
    {
        $other = Computer::create([
            'club_id' => $this->club->id,
            'name' => 'PC-02',
            'status' => 'available',
            'kind' => 'pc',
            'x' => 1,
            'y' => 1,
        ]);
        $admin = Admin::create([
            'name' => 'Map Admin',
            'email' => 'map-admin2@test.local',
            'password' => 'password',
            'role' => 'supervisor',
        ]);

        $this->actingAs($admin, 'admin')
            ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
            ->postJson('/admin/save-map', [
                'club_id' => $this->club->id,
                'config' => $this->club->map_config,
                'pcs' => [[
                    'id' => $this->seat->id,
                    'name' => 'PC-01',
                    'x' => 12.5,
                    'y' => 8.0,
                    'kind' => 'pc',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('computers', ['id' => $this->seat->id]);
        $this->assertDatabaseMissing('computers', ['id' => $other->id]);
    }
}
