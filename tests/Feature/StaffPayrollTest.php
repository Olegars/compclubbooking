<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Shift;
use App\Models\StaffLedger;
use App\Services\StaffPayrollService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffPayrollTest extends TestCase
{
    use RefreshDatabase;

    public function test_closed_shift_accrues_base_rate(): void
    {
        $admin = $this->makeAdmin('admin', 2000, 'shift');
        $this->makeClosedShift($admin);

        $this->actingAs($admin, 'admin')
            ->get('/admin/salary')
            ->assertOk();

        $this->assertDatabaseHas('staff_ledgers', [
            'admin_id' => $admin->id,
            'type' => StaffLedger::TYPE_ACCRUAL,
            'amount' => 2000,
        ]);

        $this->assertSame(2000.0, app(StaffPayrollService::class)->available($admin));
    }

    public function test_does_not_double_accrue_the_same_shift(): void
    {
        $admin = $this->makeAdmin('admin', 2000, 'shift');
        $this->makeClosedShift($admin);
        $payroll = app(StaffPayrollService::class);

        $payroll->syncFor($admin);
        $payroll->syncFor($admin);

        $this->assertSame(1, StaffLedger::query()->where('admin_id', $admin->id)->count());
    }

    public function test_admin_sees_only_own_shifts_and_can_withdraw(): void
    {
        $admin = $this->makeAdmin('admin', 2000, 'shift');
        $other = $this->makeAdmin('supervisor', 3000, 'shift');
        $mine = $this->makeClosedShift($admin);
        $this->makeClosedShift($other);

        $this->actingAs($admin, 'admin')->get('/admin/salary')->assertOk();

        $this->assertDatabaseHas('staff_ledgers', ['admin_id' => $admin->id, 'shift_id' => $mine->id]);
        $this->assertDatabaseMissing('staff_ledgers', ['admin_id' => $other->id]);

        $this->actingAs($admin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/admin/salary/withdraw')
            ->assertRedirect();

        $this->assertDatabaseHas('staff_ledgers', [
            'admin_id' => $admin->id,
            'type' => StaffLedger::TYPE_PAYOUT,
            'amount' => 2000,
        ]);
        $this->assertSame(0.0, app(StaffPayrollService::class)->available($admin->fresh()));
    }

    public function test_cannot_withdraw_more_than_available(): void
    {
        $admin = $this->makeAdmin('admin', 2000, 'shift');
        $this->makeClosedShift($admin);
        app(StaffPayrollService::class)->syncFor($admin);

        $this->actingAs($admin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->from('/admin/salary')
            ->post('/admin/salary/withdraw', ['amount' => 5000])
            ->assertRedirect('/admin/salary')
            ->assertSessionHasErrors('amount');
    }

    public function test_fine_reduces_available_balance(): void
    {
        $admin = $this->makeAdmin('admin', 2000, 'shift');
        $owner = $this->makeAdmin('owner', null, null);
        $this->makeClosedShift($admin);
        app(StaffPayrollService::class)->syncFor($admin);

        $this->actingAs($owner, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post("/admin/staff/{$admin->id}/fines", [
                'amount' => 500,
                'reason' => 'Опоздание на смену',
            ])
            ->assertRedirect();

        $this->assertSame(1500.0, app(StaffPayrollService::class)->available($admin->fresh()));
        $this->assertDatabaseHas('staff_ledgers', [
            'admin_id' => $admin->id,
            'type' => StaffLedger::TYPE_FINE,
            'reason' => 'Опоздание на смену',
            'amount' => 500,
        ]);
    }

    public function test_admin_cannot_assign_fines(): void
    {
        $admin = $this->makeAdmin('admin', 2000, 'shift');
        $other = $this->makeAdmin('supervisor', 3000, 'shift');

        $this->actingAs($admin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post("/admin/staff/{$other->id}/fines", [
                'amount' => 100,
                'reason' => 'Тест',
            ])
            ->assertForbidden();
    }

    public function test_guest_is_redirected_from_salary(): void
    {
        $this->get('/admin/salary')->assertRedirect('/admin/login');
    }

    public function test_store_role_can_open_salary_page(): void
    {
        $manager = $this->makeAdmin('store_manager', 2500, 'shift');

        $this->actingAs($manager, 'admin')
            ->get('/admin/salary')
            ->assertOk();
    }

    public function test_closing_shift_accrues_salary_for_the_admin_who_worked_it(): void
    {
        $worker = $this->makeAdmin('admin', 2000, 'shift');
        $closer = $this->makeAdmin('supervisor', 3000, 'shift');

        $shift = Shift::create([
            'admin_id' => $worker->id,
            'status' => 'open',
            'started_at' => now()->subHours(8),
            'cash_start' => 0,
        ]);

        $shift->update([
            'status' => 'closed',
            'closed_by' => $closer->id,
            'ended_at' => now(),
            'cash_end' => 0,
        ]);

        app(StaffPayrollService::class)->accrueClosedShift($shift->fresh('admin'));

        $this->assertDatabaseHas('staff_ledgers', [
            'admin_id' => $worker->id,
            'type' => StaffLedger::TYPE_ACCRUAL,
            'amount' => 2000,
        ]);
        $this->assertDatabaseMissing('staff_ledgers', [
            'admin_id' => $closer->id,
            'type' => StaffLedger::TYPE_ACCRUAL,
        ]);
    }

    private function makeAdmin(string $role, ?float $rate, ?string $payType): Admin
    {
        return Admin::create([
            'name' => ucfirst($role).' '.uniqid(),
            'email' => $role.'.'.uniqid().'@payroll.test',
            'password' => 'password',
            'role' => $role,
            'base_rate' => $rate,
            'pay_type' => $payType,
        ]);
    }

    private function makeClosedShift(Admin $admin): Shift
    {
        return Shift::create([
            'admin_id' => $admin->id,
            'closed_by' => $admin->id,
            'status' => 'closed',
            'started_at' => now()->subHours(8),
            'ended_at' => now(),
            'cash_start' => 0,
            'cash_end' => 0,
        ]);
    }
}
