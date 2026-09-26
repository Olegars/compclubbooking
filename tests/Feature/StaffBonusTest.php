<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Club;
use App\Models\Computer;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftSlot;
use App\Models\ShiftSlotBooking;
use App\Models\ShiftSlotTemplate;
use App\Models\StaffLedger;
use App\Models\StaffQuarterReserve;
use App\Models\StaffXpTransaction;
use App\Models\User;
use App\Services\StaffBonusService;
use Carbon\Carbon;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffBonusTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_closed_shift_scores_base_stock_and_stations(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-20 18:00:00'));
        $admin = $this->makeAdmin('admin');
        $shift = $this->closedShift($admin, '2026-03-20 10:00:00', '2026-03-20 18:00:00');

        app(StaffBonusService::class)->awardClosedShift($shift);
        app(StaffBonusService::class)->awardClosedShift($shift->fresh());

        $this->assertSame(150, $this->openXp($admin));
        $this->assertSame(1, StaffXpTransaction::query()->where('action_type', StaffXpTransaction::SHIFT_BASE)->count());
        $this->assertDatabaseHas('staff_xp_transactions', [
            'admin_id' => $admin->id,
            'action_type' => StaffXpTransaction::BAR_PERFECT,
            'amount_xp' => 30,
        ]);
    }

    public function test_shortage_and_broken_station_change_the_score(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-20 18:00:00'));
        $admin = $this->makeAdmin('admin');
        Computer::query()->create([
            'club_id' => $admin->club_id,
            'name' => '01',
            'power_state' => 'error',
        ]);
        $shift = $this->closedShift($admin, '2026-03-20 10:00:00', '2026-03-20 18:00:00');

        app(StaffBonusService::class)->awardClosedShift($shift, [[
            'name' => 'Энергетик',
            'expected' => 2,
            'actual' => 0,
        ]]);

        $this->assertSame(50, $this->openXp($admin));
        $this->assertDatabaseMissing('staff_xp_transactions', [
            'shift_id' => $shift->id,
            'action_type' => StaffXpTransaction::BAR_PERFECT,
        ]);
        $this->assertDatabaseMissing('staff_xp_transactions', [
            'shift_id' => $shift->id,
            'action_type' => StaffXpTransaction::HARDWARE_GREEN,
        ]);
        $this->assertDatabaseHas('staff_xp_transactions', [
            'shift_id' => $shift->id,
            'action_type' => StaffXpTransaction::SHORTAGE,
            'amount_xp' => -50,
        ]);
    }

    public function test_late_slot_skips_base_points(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-20 12:00:00'));
        $admin = $this->makeAdmin('admin');
        $this->bookSlot($admin, Carbon::parse('2026-03-20 10:00:00'));
        $shift = $this->closedShift($admin, '2026-03-20 10:40:00', '2026-03-20 12:00:00');

        app(StaffBonusService::class)->awardClosedShift($shift);

        $this->assertDatabaseHas('staff_xp_transactions', [
            'shift_id' => $shift->id,
            'action_type' => StaffXpTransaction::LATE_SHIFT,
            'amount_xp' => 0,
        ]);
        $this->assertDatabaseMissing('staff_xp_transactions', [
            'shift_id' => $shift->id,
            'action_type' => StaffXpTransaction::SHIFT_BASE,
        ]);
        $this->assertSame(50, $this->openXp($admin));
    }

    public function test_bar_plan_adds_points_when_orders_hit_the_target(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-20 18:00:00'));
        $admin = $this->makeAdmin('admin');
        $user = User::query()->create([
            'name' => 'Гость',
            'email' => 'guest-'.uniqid().'@bonus.test',
            'password' => 'password',
            'phone' => '7999'.random_int(1000000, 9999999),
        ]);
        $shift = $this->closedShift($admin, '2026-03-20 10:00:00', '2026-03-20 18:00:00');
        $order = Order::query()->create([
            'user_id' => $user->id,
            'product_name' => 'Энергетик',
            'price' => 16000,
            'status' => Order::STATUS_DELIVERED,
        ]);
        $order->forceFill([
            'created_at' => '2026-03-20 14:00:00',
            'updated_at' => '2026-03-20 14:00:00',
        ])->save();

        app(StaffBonusService::class)->awardClosedShift($shift);

        $this->assertDatabaseHas('staff_xp_transactions', [
            'shift_id' => $shift->id,
            'action_type' => StaffXpTransaction::BAR_PLAN,
            'amount_xp' => 50,
        ]);
        $this->assertSame(200, $this->openXp($admin));
    }

    public function test_month_close_splits_seventy_thirty_and_quarter_pays_the_safe(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-20 18:00:00'));
        $admin = $this->makeAdmin('admin');
        $shift = $this->closedShift($admin, '2026-03-20 10:00:00', '2026-03-20 18:00:00');
        $bonus = app(StaffBonusService::class);
        $bonus->awardClosedShift($shift);

        Carbon::setTestNow(Carbon::parse('2026-04-02 09:00:00'));
        $this->assertSame(1, $bonus->closeMonth(Carbon::parse('2026-03-01')));
        $this->assertSame(0, $bonus->closeMonth(Carbon::parse('2026-03-01')));

        $this->assertDatabaseHas('staff_ledgers', [
            'admin_id' => $admin->id,
            'type' => StaffLedger::TYPE_ACCRUAL,
            'period_key' => 'bonus-2026-03',
            'amount' => 1050,
        ]);
        $this->assertSame(450.0, (float) StaffQuarterReserve::query()->where('admin_id', $admin->id)->value('points'));

        $this->assertSame(1, $bonus->closeQuarter(2026, 1));
        $this->assertDatabaseHas('staff_ledgers', [
            'admin_id' => $admin->id,
            'period_key' => 'bonus-2026-Q1',
            'amount' => 450,
        ]);
        $this->assertSame(0.0, (float) StaffQuarterReserve::query()->where('admin_id', $admin->id)->value('points'));
    }

    public function test_negative_month_carries_forward_without_a_salary_deduction(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-20 18:00:00'));
        $admin = $this->makeAdmin('admin');
        $supervisor = $this->makeAdmin('supervisor');
        app(StaffBonusService::class)->manualAdjust($supervisor, $admin, -40, 'Акт расхождения склада');

        Carbon::setTestNow(Carbon::parse('2026-04-02 09:00:00'));
        app(StaffBonusService::class)->closeMonth(Carbon::parse('2026-03-01'));

        $this->assertSame(0, StaffLedger::query()->where('admin_id', $admin->id)->where('period_key', 'like', 'bonus-%')->count());
        $this->assertDatabaseHas('staff_xp_transactions', [
            'admin_id' => $admin->id,
            'action_type' => StaffXpTransaction::CARRY,
            'amount_xp' => -40,
            'period_key' => '2026-04',
        ]);
    }

    public function test_dismiss_burns_the_safe_and_open_points(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-20 18:00:00'));
        $admin = $this->makeAdmin('admin');
        $shift = $this->closedShift($admin, '2026-03-20 10:00:00', '2026-03-20 18:00:00');
        app(StaffBonusService::class)->awardClosedShift($shift);
        StaffQuarterReserve::query()->where('admin_id', $admin->id)->delete();
        StaffQuarterReserve::query()->create([
            'admin_id' => $admin->id,
            'year' => 2026,
            'quarter' => 1,
            'points' => 80,
        ]);

        $forfeited = app(StaffBonusService::class)->forfeitQuarter($admin->id);

        $this->assertSame(80.0, $forfeited);
        $this->assertSame(0.0, (float) StaffQuarterReserve::query()->where('admin_id', $admin->id)->value('points'));
        $this->assertNotNull(StaffQuarterReserve::query()->where('admin_id', $admin->id)->value('burned_at'));
        $this->assertSame(0, $this->openXp($admin));
    }

    public function test_manual_adjust_rejects_forbidden_wording(): void
    {
        $admin = $this->makeAdmin('admin');
        $supervisor = $this->makeAdmin('supervisor');

        $this->expectException(\RuntimeException::class);
        app(StaffBonusService::class)->manualAdjust($supervisor, $admin, -10, 'штраф за опоздание');
    }

    public function test_salary_and_staff_pages_show_the_board(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-20 18:00:00'));
        $admin = $this->makeAdmin('admin');
        $shift = $this->closedShift($admin, '2026-03-20 10:00:00', '2026-03-20 18:00:00');
        app(StaffBonusService::class)->awardClosedShift($shift);

        $this->actingAs($admin, 'admin')
            ->get('/admin/salary')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('bonus.open_xp', 150)
                ->where('bonus.level', 1)
                ->where('bonus.burned', false));

        $supervisor = $this->makeAdmin('supervisor');
        $this->actingAs($supervisor, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/admin/staff/bonus/adjust', [
                'admin_id' => $admin->id,
                'amount_xp' => 20,
                'reason' => 'Проведение турнира',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAs($supervisor, 'admin')
            ->get('/admin/staff')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('bonus_board.rate', 10)
                ->where('bonus_board.rows', fn ($rows) => collect($rows)->contains(
                    fn ($row) => (int) $row['id'] === (int) $admin->id && (int) $row['open_xp'] === 170
                )));
    }

    private function openXp(Admin $admin): int
    {
        return (int) StaffXpTransaction::query()
            ->where('admin_id', $admin->id)
            ->whereNull('settlement_id')
            ->sum('amount_xp');
    }

    private function makeAdmin(string $role): Admin
    {
        $club = Club::query()->first() ?? Club::query()->create([
            'name' => 'Bonus Club',
            'slug' => 'bonus-club',
            'type' => 'club',
        ]);

        return Admin::query()->create([
            'name' => ucfirst($role).' '.uniqid(),
            'email' => $role.'.'.uniqid().'@bonus.test',
            'password' => 'password',
            'role' => $role,
            'club_id' => $club->id,
            'base_rate' => 2000,
            'pay_type' => 'shift',
        ]);
    }

    private function closedShift(Admin $admin, string $started, string $ended): Shift
    {
        return Shift::query()->create([
            'admin_id' => $admin->id,
            'status' => 'closed',
            'started_at' => $started,
            'ended_at' => $ended,
            'cash_start' => 0,
            'cash_end' => 0,
        ]);
    }

    private function bookSlot(Admin $admin, Carbon $starts): void
    {
        $template = ShiftSlotTemplate::query()->create([
            'club_id' => $admin->club_id,
            'name' => 'Слот '.uniqid(),
            'starts_time' => '10:00:00',
            'duration_hours' => 12,
            'intern_capacity' => 1,
            'is_active' => true,
        ]);
        $slot = ShiftSlot::query()->create([
            'club_id' => $admin->club_id,
            'template_id' => $template->id,
            'starts_at' => $starts,
            'ends_at' => $starts->copy()->addHours(12),
            'intern_capacity' => 1,
        ]);
        ShiftSlotBooking::query()->create([
            'shift_slot_id' => $slot->id,
            'admin_id' => $admin->id,
            'kind' => ShiftSlotBooking::KIND_LEAD,
            'status' => ShiftSlotBooking::STATUS_BOOKED,
        ]);
    }
}
