<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Shift;
use App\Models\ShiftIntern;
use App\Models\StaffLedger;
use App\Services\StaffPayrollService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDutyAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_admin_can_open_salary_but_not_dashboard(): void
    {
        $admin = $this->makeAdmin('admin');

        $this->actingAs($admin, 'admin')->get('/admin/salary')->assertOk();
        $this->actingAs($admin, 'admin')->get('/admin/dashboard')->assertRedirect('/admin/salary');
        $this->actingAs($admin, 'admin')->get('/admin/orders')->assertRedirect('/admin/salary');
    }

    public function test_intern_can_open_salary_but_not_club_ops(): void
    {
        $intern = $this->makeAdmin('intern', 1500);

        $this->actingAs($intern, 'admin')->get('/admin/salary')->assertOk();
        $this->actingAs($intern, 'admin')->get('/admin/dashboard')->assertRedirect('/admin/salary');
        $this->actingAs($intern, 'admin')->get('/admin/shifts/transfer')->assertRedirect('/admin/salary');
    }

    public function test_active_admin_on_shift_can_open_club_ops(): void
    {
        $admin = $this->makeAdmin('admin');
        $this->openShift($admin);

        $this->actingAs($admin, 'admin')->get('/admin/salary')->assertOk();
        $this->actingAs($admin, 'admin')->get('/admin/incidents')->assertOk();
    }

    public function test_intern_joins_open_shift_and_cannot_take_it(): void
    {
        $lead = $this->makeAdmin('admin');
        $intern = $this->makeAdmin('intern', 1500);
        $shift = $this->openShift($lead);

        $this->actingAs($intern, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->from('/admin/salary')
            ->post('/admin/shifts/intern/join')
            ->assertRedirect('/admin/salary');

        $this->assertDatabaseHas('shift_interns', [
            'shift_id' => $shift->id,
            'admin_id' => $intern->id,
        ]);

        $this->actingAs($intern, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/admin/api/shifts/complete', [
                'cash_counted' => 0,
                'items' => [['id' => 1, 'name' => 'X', 'stock' => 0, 'actual' => 0]],
            ])
            ->assertRedirect('/admin/salary');
    }

    public function test_intern_is_accrued_when_shift_closes(): void
    {
        $lead = $this->makeAdmin('admin', 2000);
        $intern = $this->makeAdmin('intern', 1500);
        $shift = Shift::create([
            'admin_id' => $lead->id,
            'status' => 'closed',
            'started_at' => now()->subHours(8),
            'ended_at' => now(),
            'cash_start' => 0,
            'cash_end' => 0,
        ]);
        ShiftIntern::create([
            'shift_id' => $shift->id,
            'admin_id' => $intern->id,
            'joined_at' => $shift->started_at,
            'left_at' => $shift->ended_at,
        ]);

        app(StaffPayrollService::class)->syncFor($intern);

        $this->assertDatabaseHas('staff_ledgers', [
            'admin_id' => $intern->id,
            'shift_id' => $shift->id,
            'type' => StaffLedger::TYPE_ACCRUAL,
            'amount' => 1500,
        ]);
    }

    public function test_inactive_admin_salary_page_has_no_club_ops_nav(): void
    {
        $admin = $this->makeAdmin('admin');

        $this->actingAs($admin, 'admin')->get('/admin/salary')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can_access_club', false)
                ->where('is_salary_only', true)
                ->where('has_full_club_ops', false)
            );
    }

    public function test_active_admin_on_shift_sees_club_ops_nav(): void
    {
        $admin = $this->makeAdmin('admin');
        $this->openShift($admin);

        $this->actingAs($admin, 'admin')->get('/admin/salary')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can_access_club', true)
                ->where('is_salary_only', false)
                ->where('has_full_club_ops', true)
            );
    }

    public function test_supervisor_keeps_management_access_without_floor_ops_nav(): void
    {
        $super = $this->makeAdmin('supervisor', 3000);

        $this->actingAs($super, 'admin')->get('/admin/docs')->assertOk();
        $this->actingAs($super, 'admin')->get('/admin/salary')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can_access_club', false)
                ->where('is_salary_only', false)
                ->where('has_full_club_ops', true)
            );
        $this->assertFalse($super->isSalaryOnly());
        $this->assertTrue($super->hasFullClubOps());
    }

    private function makeAdmin(string $role, ?float $rate = 2000): Admin
    {
        return Admin::create([
            'name' => ucfirst($role).' '.uniqid(),
            'email' => $role.'.'.uniqid().'@duty.test',
            'password' => 'password',
            'role' => $role,
            'base_rate' => $rate,
            'pay_type' => 'shift',
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
