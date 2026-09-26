<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Booking;
use App\Models\Club;
use App\Models\Computer;
use App\Models\ComputerInputDevice;
use App\Models\Shift;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftHardwareAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_begin_wakes_offline_pcs_and_leaves_guest_sessions_alone(): void
    {
        $outgoing = $this->makeAdmin();
        $incoming = $this->makeAdmin();
        $this->openShift($outgoing);
        $clubId = (int) $outgoing->club_id;

        $asleep = Computer::create([
            'club_id' => $clubId,
            'name' => 'ПК-08',
            'status' => 'available',
            'kind' => 'pc',
            'hwid' => 'hw-asleep',
            'mac_address' => 'AA:BB:CC:DD:EE:08',
            'power_state' => 'off',
            'power_desired' => 'off',
        ]);
        $busy = Computer::create([
            'club_id' => $clubId,
            'name' => 'ПК-03',
            'status' => 'available',
            'kind' => 'pc',
            'hwid' => 'hw-busy',
            'mac_address' => 'AA:BB:CC:DD:EE:03',
            'power_state' => 'off',
            'power_desired' => 'off',
        ]);
        $guest = User::create([
            'name' => 'Guest',
            'phone' => '+79990001122',
            'email' => 'guest-audit@test',
            'password' => 'password',
        ]);
        Booking::create([
            'user_id' => $guest->id,
            'pc_ids' => [$busy->id],
            'computer_id' => $busy->id,
            'date' => now()->toDateString(),
            'start_time' => 10,
            'duration' => 2,
            'price' => 100,
            'status' => 'active',
        ]);

        $this->actingAs($incoming, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/admin/api/shifts/begin', ['verified' => true])
            ->assertOk()
            ->assertJsonPath('hardware.total', 2)
            ->assertJsonPath('hardware.polled', 1)
            ->assertJsonPath('hardware.counts.pending', 1);

        $asleep->refresh();
        $busy->refresh();
        $this->assertTrue((bool) $asleep->shift_audit_hold);
        $this->assertSame('on', $asleep->power_desired);
        $this->assertFalse((bool) $busy->shift_audit_hold);
    }

    public function test_audit_heartbeat_flags_missing_mouse_and_slow_link(): void
    {
        $outgoing = $this->makeAdmin();
        $incoming = $this->makeAdmin();
        $this->openShift($outgoing);
        $pc = Computer::create([
            'club_id' => $outgoing->club_id,
            'name' => 'ПК-08',
            'status' => 'available',
            'kind' => 'pc',
            'hwid' => 'hw-audit',
            'mac_address' => 'AA:BB:CC:DD:EE:18',
            'power_state' => 'off',
            'power_desired' => 'off',
        ]);
        ComputerInputDevice::create([
            'computer_id' => $pc->id,
            'fingerprint' => [
                'mice' => [['kind' => 'mouse', 'vid' => '046D']],
                'keyboards' => [['kind' => 'keyboard', 'vid' => '046D']],
            ],
            'bound_at' => now(),
        ]);

        $this->actingAs($incoming, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/admin/api/shifts/begin', ['verified' => true])
            ->assertOk();

        $this->postJson('/api/shell/power/heartbeat', [
            'terminal_id' => $pc->id,
            'hwid' => 'hw-audit',
            'mac_address' => 'AA:BB:CC:DD:EE:18',
            'audit_complete' => true,
            'hid_present' => ['keyboard'],
            'nic_link_mbps' => 100,
            'nic_flap_events' => 0,
            'cache_ok' => true,
            'ssd_wear_pct' => 12,
            'ssd_health' => 'healthy',
            'hardware_switch_fault' => true,
        ])->assertOk()
            ->assertJsonPath('shift_audit', false)
            ->assertJsonPath('power_action', 'none');

        $status = $this->actingAs($incoming, 'admin')
            ->getJson('/admin/api/shifts/transfer/hardware-status')
            ->assertOk()
            ->json();

        $this->assertSame(1, $status['polled']);
        $this->assertSame('critical', $status['stations'][0]['status']);
        $this->assertContains('mouse', $status['stations'][0]['missing_devices']);
        $this->assertContains('hid.disconnected', $status['stations'][0]['active_incidents']);
        $this->assertContains('hardware_switch_fault', $status['stations'][0]['active_incidents']);
        $this->assertContains('nic_link_degraded', $status['stations'][0]['active_incidents']);
    }

    public function test_confirm_opens_incidents_and_pins_them_to_outgoing_salary(): void
    {
        $outgoing = $this->makeAdmin();
        $incoming = $this->makeAdmin();
        $this->openShift($outgoing);
        $pc = Computer::create([
            'club_id' => $outgoing->club_id,
            'name' => 'ПК-08',
            'status' => 'available',
            'kind' => 'pc',
            'hwid' => 'hw-confirm',
            'mac_address' => 'AA:BB:CC:DD:EE:28',
            'power_state' => 'off',
            'power_desired' => 'off',
        ]);
        ComputerInputDevice::create([
            'computer_id' => $pc->id,
            'fingerprint' => [
                'mice' => [['kind' => 'mouse']],
                'keyboards' => [['kind' => 'keyboard']],
            ],
            'bound_at' => now(),
        ]);

        $this->actingAs($incoming, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/admin/api/shifts/begin', ['verified' => true])
            ->assertOk();

        $this->postJson('/api/shell/power/heartbeat', [
            'terminal_id' => $pc->id,
            'audit_complete' => true,
            'hid_present' => ['keyboard'],
            'cache_ok' => true,
            'nic_link_mbps' => 1000,
            'ssd_health' => 'healthy',
        ])->assertOk();

        $this->actingAs($incoming, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/admin/shifts/transfer/confirm', ['cash_counted' => 0])
            ->assertRedirect('/admin/dashboard');

        $this->assertDatabaseHas('incidents', [
            'type' => 'hid_disconnected',
            'computer_id' => $pc->id,
            'responsible_admin_id' => $outgoing->id,
        ]);

        $pc->refresh();
        $this->assertFalse((bool) $pc->shift_audit_hold);
        $this->assertSame('off', $pc->power_desired);

        $this->actingAs($outgoing, 'admin')
            ->get('/admin/salary')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Salary')
                ->where('hardware_notes.0.description', fn ($text) => str_contains((string) $text, 'ПК-08'))
            );
    }

    public function test_unanswered_pc_becomes_wol_timeout_after_deadline(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-26 12:00:00'));
        try {
            $outgoing = $this->makeAdmin();
            $incoming = $this->makeAdmin();
            $this->openShift($outgoing);
            Computer::create([
                'club_id' => $outgoing->club_id,
                'name' => 'ПК-11',
                'status' => 'available',
                'kind' => 'pc',
                'hwid' => 'hw-timeout',
                'mac_address' => 'AA:BB:CC:DD:EE:11',
                'power_state' => 'off',
                'power_desired' => 'off',
            ]);

            $this->actingAs($incoming, 'admin')
                ->withoutMiddleware(ValidateCsrfToken::class)
                ->postJson('/admin/api/shifts/begin', ['verified' => true])
                ->assertOk()
                ->assertJsonPath('hardware.counts.pending', 1);

            Carbon::setTestNow(Carbon::parse('2026-09-26 12:02:05'));

            $this->actingAs($incoming, 'admin')
                ->getJson('/admin/api/shifts/transfer/hardware-status')
                ->assertOk()
                ->assertJsonPath('status', 'timed_out')
                ->assertJsonPath('stations.0.status', 'critical')
                ->assertJsonPath('stations.0.active_incidents.0', 'wol_timeout');
        } finally {
            Carbon::setTestNow();
        }
    }

    private function makeAdmin(): Admin
    {
        $club = Club::query()->first() ?? Club::query()->create([
            'name' => 'Audit Club',
            'slug' => 'audit-club',
            'type' => 'club',
        ]);

        return Admin::create([
            'name' => 'Admin '.uniqid(),
            'email' => uniqid('audit').'@test',
            'password' => 'password',
            'role' => 'admin',
            'base_rate' => 2000,
            'pay_type' => 'shift',
            'club_id' => $club->id,
        ]);
    }

    private function openShift(Admin $admin): Shift
    {
        return Shift::create([
            'admin_id' => $admin->id,
            'status' => 'open',
            'started_at' => now()->subHour(),
            'cash_start' => 0,
        ]);
    }
}
