<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Club;
use App\Models\Computer;
use App\Models\ComputerMemorySample;
use App\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemoryLoadTest extends TestCase
{
    use RefreshDatabase;

    public function test_shell_stores_memory_sample_and_drops_old_rows(): void
    {
        $club = $this->club('Hall');
        $pc = $this->pc($club, 'PC-12');

        ComputerMemorySample::query()->create([
            'club_id' => $club->id,
            'computer_id' => $pc->id,
            'load_pct' => 10,
            'total_mb' => 32768,
            'used_mb' => 3000,
            'available_mb' => 29768,
            'reported_at' => now()->subHours(30),
        ]);

        $this->postJson('/api/shell/memory', [
            'terminal_id' => $pc->id,
            'load_pct' => 81,
            'total_mb' => 32768,
            'used_mb' => 26500,
            'available_mb' => 6268,
        ])->assertOk()
            ->assertJsonPath('status', 'success');

        $fresh = ComputerMemorySample::query()->where('computer_id', $pc->id)->get();
        $this->assertCount(1, $fresh);
        $this->assertSame(81, (int) $fresh->first()->load_pct);
        $this->assertSame(32768, (int) $fresh->first()->total_mb);
        $this->assertSame($club->id, (int) $fresh->first()->club_id);
    }

    public function test_shell_rejects_unknown_terminal(): void
    {
        $this->postJson('/api/shell/memory', [
            'terminal_id' => 99999,
            'load_pct' => 10,
            'total_mb' => 16384,
            'used_mb' => 1000,
            'available_mb' => 15384,
        ])->assertStatus(422);
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->get('/admin/memory')->assertRedirect('/admin/login');
    }

    public function test_owner_sees_only_current_location_pcs(): void
    {
        $club = $this->club('0451');
        $other = $this->club('Другой');
        $owner = $this->makeAdmin('owner', $club->id);
        $pc = $this->pc($club, 'PC-1');
        $foreign = $this->pc($other, 'PC-9');

        ComputerMemorySample::query()->create([
            'club_id' => $club->id,
            'computer_id' => $pc->id,
            'load_pct' => 64,
            'total_mb' => 16384,
            'used_mb' => 10480,
            'available_mb' => 5904,
            'reported_at' => now(),
        ]);
        ComputerMemorySample::query()->create([
            'club_id' => $other->id,
            'computer_id' => $foreign->id,
            'load_pct' => 99,
            'total_mb' => 16384,
            'used_mb' => 16000,
            'available_mb' => 384,
            'reported_at' => now(),
        ]);

        $this->actingAs($owner, 'admin')
            ->get('/admin/memory')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Memory')
                ->where('location.id', $club->id)
                ->has('stations', 1)
                ->where('stations.0.name', 'PC-1')
                ->where('stations.0.load_pct', 64)
                ->where('stations.0.stale', false)
                ->where('summary.reporting', 1)
                ->where('summary.peak_name', 'PC-1')
            );
    }

    public function test_stale_sample_is_not_counted_as_live(): void
    {
        $club = $this->club('Hall');
        $owner = $this->makeAdmin('owner', $club->id);
        $pc = $this->pc($club, 'PC-2');

        ComputerMemorySample::query()->create([
            'club_id' => $club->id,
            'computer_id' => $pc->id,
            'load_pct' => 92,
            'total_mb' => 8192,
            'used_mb' => 7500,
            'available_mb' => 692,
            'reported_at' => now()->subMinutes(10),
        ]);

        $this->actingAs($owner, 'admin')
            ->get('/admin/memory')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stations.0.stale', true)
                ->where('stations.0.load_pct', 92)
                ->where('summary.reporting', 0)
                ->where('summary.hot', 0)
            );
    }

    public function test_supervisor_and_hall_admin_cannot_open(): void
    {
        $supervisor = $this->makeAdmin('supervisor');
        $this->actingAs($supervisor, 'admin')
            ->get('/admin/memory')
            ->assertForbidden();

        $admin = $this->makeAdmin('admin');
        Shift::query()->create([
            'admin_id' => $admin->id,
            'status' => 'open',
            'started_at' => now()->subHour(),
            'cash_start' => 0,
        ]);
        $this->actingAs($admin, 'admin')
            ->get('/admin/memory')
            ->assertForbidden();
    }

    public function test_off_duty_admin_and_intern_are_kept_out(): void
    {
        $admin = $this->makeAdmin('admin');
        $this->actingAs($admin, 'admin')
            ->get('/admin/memory')
            ->assertRedirect('/admin/salary');

        $intern = $this->makeAdmin('intern');
        $this->actingAs($intern, 'admin')
            ->get('/admin/memory')
            ->assertRedirect('/admin/salary');
    }

    private function club(string $name): Club
    {
        return Club::query()->create([
            'name' => $name,
            'slug' => 'club-'.uniqid(),
            'type' => 'club',
        ]);
    }

    private function pc(Club $club, string $name): Computer
    {
        return Computer::query()->create([
            'club_id' => $club->id,
            'name' => $name,
            'status' => 'available',
            'kind' => 'pc',
        ]);
    }

    private function makeAdmin(string $role, ?int $clubId = null): Admin
    {
        return Admin::query()->create([
            'name' => ucfirst($role).' '.uniqid(),
            'email' => $role.'.'.uniqid().'@tests.test',
            'password' => 'password',
            'role' => $role,
            'pay_type' => 'shift',
            'club_id' => $clubId,
        ]);
    }
}
