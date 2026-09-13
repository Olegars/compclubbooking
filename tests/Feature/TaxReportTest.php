<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\StaffLedger;
use App\Models\Transaction;
use App\Services\TaxReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxReportTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_owner_sees_tax_page_with_employee_and_vat_profile(): void
    {
        Carbon::setTestNow('2026-09-13 12:00:00');
        $owner = $this->makeAdmin('owner', false);

        $this->actingAs($owner, 'admin')
            ->get('/admin/taxes?year=2026')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Taxes')
                ->where('year', 2026)
                ->where('profile.has_employees', true)
                ->where('profile.usn_rate_percent', 6.0)
                ->where('premiums.fixed', 57390.0)
                ->where('vat.exempt', true)
                ->has('calendar.months')
                ->has('calendar.items')
            );
    }

    public function test_floor_admin_cannot_open_taxes(): void
    {
        $admin = $this->makeAdmin('admin', true);

        $this->actingAs($admin, 'admin')
            ->get('/admin/taxes')
            ->assertRedirect('/admin/salary');
    }

    public function test_report_uses_real_deposits_excludes_bonuses_and_wallet_refunds(): void
    {
        Carbon::setTestNow('2026-03-10 12:00:00');

        $this->deposit('cash', 100000, '2026-01-20');
        $this->deposit('achievement', 50000, '2026-01-21');
        $this->deposit('card', 40000, '2026-01-21', stub: true);
        $refund = Transaction::query()->create([
            'amount' => 20000,
            'type' => 'refund',
            'source' => 'balance',
            'description' => 'Возврат на кошелёк',
            'is_taxable' => true,
        ]);
        $refund->timestamps = false;
        $refund->created_at = Carbon::parse('2026-01-22 12:00:00');
        $refund->save();

        $report = app(TaxReportService::class)->forYear(2026);

        $this->assertSame(100000.0, $report['income']['gross']);
        $this->assertSame(40000.0, $report['income']['stub_gross']);
        $this->assertSame(100000.0, $report['quarters'][1]['gross']);
        $this->assertSame(0.0, $report['quarters'][2]['gross']);
    }

    public function test_report_applies_50_percent_cap_and_payroll_taxes_for_official_staff(): void
    {
        Carbon::setTestNow('2026-04-02 12:00:00');
        $this->deposit('card', 2_200_000, '2026-02-10');

        $worker = $this->makeAdmin('admin', true);
        $this->accrual($worker->id, 100000, 'Оклад март', '2026-03-31 18:00:00', '2026-03');

        $unofficial = $this->makeAdmin('admin', false);
        $this->accrual($unofficial->id, 80000, 'Неофициально', '2026-03-31 18:00:00');

        $report = app(TaxReportService::class)->forYear(2026);
        $q1 = $report['quarters'][1];

        $this->assertTrue($report['profile']['has_employees']);
        $this->assertSame(1, $report['payroll']['employee_count']);
        $this->assertSame(100000.0, $report['payroll']['year']['gross']);
        $this->assertSame(13000.0, $report['payroll']['year']['ndfl']);
        $this->assertSame(30000.0, $report['payroll']['year']['employer']);
        $this->assertSame(200.0, $report['payroll']['year']['injury']);
        $this->assertSame(30200.0, $q1['payroll']['employer_total']);

        $this->assertSame(132000.0, $q1['usn_raw']);
        $this->assertSame(66000.0, $q1['deduction_cap']);
        $this->assertSame(66000.0, $q1['usn_advance']);
        $this->assertSame(66000.0, $report['totals']['usn']);

        $cal = collect($report['calendar']['items']);
        $injury = $cal->firstWhere('id', 'injury-3');
        $this->assertNotNull($injury);
        $this->assertSame(200.0, $injury['amount']);
        $this->assertSame('2026-04-15', $injury['deadline']);
        $this->assertSame('due_soon', $injury['status']);
        $this->assertSame('injury-3', $report['calendar']['next']['id']);
    }

    public function test_vat_is_removed_from_usn_base_when_prior_year_over_limit(): void
    {
        $this->deposit('sbp', 25_000_000, '2025-06-01');
        $this->deposit('sbp', 105000, '2026-01-15');

        $report = app(TaxReportService::class)->forYear(2026);

        $this->assertFalse($report['vat']['exempt']);
        $this->assertSame(1, $report['vat']['from_month']);
        $this->assertSame(5.0, $report['vat']['rate']);
        $this->assertSame(5000.0, $report['quarters'][1]['vat']);
        $this->assertSame(100000.0, $report['quarters'][1]['net']);
        $this->assertSame(6000.0, $report['quarters'][1]['usn_raw']);
        $this->assertSame(5000.0, $report['totals']['vat']);
    }

    private function deposit(string $source, float $amount, string $when, bool $stub = false): void
    {
        $tx = Transaction::query()->create([
            'amount' => $amount,
            'type' => 'deposit',
            'source' => $source,
            'description' => 'Пополнение '.$source,
            'is_taxable' => true,
        ]);
        $tx->timestamps = false;
        $tx->created_at = Carbon::parse($when.' 12:00:00');
        if ($stub) {
            $tx->fiscal_status = 'skipped';
            $tx->fiscal_receipt_url = '/receipt/stub/'.$tx->id;
        } else {
            $tx->fiscal_status = 'success';
            $tx->fiscal_receipt_url = 'https://ofd.example.test/r/'.$tx->id;
        }
        $tx->save();
    }

    private function accrual(int $adminId, float $amount, string $reason, string $when, ?string $periodKey = null): void
    {
        $row = StaffLedger::query()->create([
            'admin_id' => $adminId,
            'type' => StaffLedger::TYPE_ACCRUAL,
            'amount' => $amount,
            'reason' => $reason,
            'period_key' => $periodKey,
        ]);
        $row->timestamps = false;
        $row->created_at = Carbon::parse($when);
        $row->save();
    }

    private function makeAdmin(string $role, bool $official): Admin
    {
        return Admin::query()->create([
            'name' => ucfirst($role).' '.uniqid(),
            'email' => $role.'.'.uniqid().'@tax.test',
            'password' => 'password',
            'role' => $role,
            'is_official_employee' => $official,
        ]);
    }
}
