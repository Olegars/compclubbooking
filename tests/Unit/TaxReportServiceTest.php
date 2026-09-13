<?php

namespace Tests\Unit;

use App\Services\TaxReportService;
use Tests\TestCase;

class TaxReportServiceTest extends TestCase
{
    public function test_usn_with_employees_cannot_cut_tax_below_half(): void
    {
        $tax = app(TaxReportService::class);

        $first = $tax->usnPayable(132000, 112390, true);
        $this->assertSame(66000.0, $first['deduction_cap']);
        $this->assertSame(66000.0, $first['deduction']);
        $this->assertSame(66000.0, $first['to_pay']);

        $halfYear = $tax->usnPayable(270000, 142390, true);
        $this->assertSame(135000.0, $halfYear['deduction_cap']);
        $this->assertSame(135000.0, $halfYear['to_pay']);
    }

    public function test_usn_without_employees_can_go_to_zero(): void
    {
        $tax = app(TaxReportService::class);
        $result = $tax->usnPayable(54000, 62390, false);

        $this->assertSame(54000.0, $result['deduction']);
        $this->assertSame(0.0, $result['to_pay']);
    }

    public function test_vat_is_extracted_from_gross_receipt(): void
    {
        $tax = app(TaxReportService::class);
        $split = $tax->splitGross(105000, 5);

        $this->assertSame(5000.0, $split['vat']);
        $this->assertSame(100000.0, $split['net']);
    }

    public function test_ip_extra_1_percent_and_cap(): void
    {
        $tax = app(TaxReportService::class);
        $rates = $tax->rates(2026);

        $this->assertSame(57390.0, $rates['ip_fixed']);
        $this->assertSame(7000.0, $tax->ipExtra(1_000_000, $rates));
        $this->assertSame(0.0, $tax->ipExtra(250000, $rates));
        $this->assertSame((float) $rates['ip_extra_max'], $tax->ipExtra(80_000_000, $rates));
    }

    public function test_ndfl_13_percent_then_15(): void
    {
        $tax = app(TaxReportService::class);
        $brackets = $tax->rates(2026)['ndfl'];

        $this->assertSame(13000.0, $tax->ndflOn(100000, $brackets));
        $this->assertSame(312000.0, $tax->ndflOn(2_400_000, $brackets));
        $this->assertSame(312000.0 + 90000.0, $tax->ndflOn(3_000_000, $brackets));
    }

    public function test_employer_30_then_15_1_over_base(): void
    {
        $tax = app(TaxReportService::class);
        $rates = $tax->rates(2026);

        $within = $tax->employerOn(0, 100000, $rates);
        $this->assertSame(30000.0, $within['amount']);

        $over = $tax->employerOn(2_979_000, 100000, $rates);
        $this->assertSame(15100.0, $over['amount']);
    }

    public function test_vat_starts_next_month_after_20m_if_prior_year_was_below(): void
    {
        $tax = app(TaxReportService::class);
        $rates = $tax->rates(2026);
        $months = array_fill(1, 12, 0.0);
        $months[1] = 15_000_000;
        $months[2] = 10_000_000;
        $months[3] = 10_500_000;

        $plan = $tax->vatPlan($months, 10_000_000, $rates, 'special');

        $this->assertFalse($plan['exempt']);
        $this->assertSame(3, $plan['from_month']);
        $this->assertSame(0.0, $plan['months'][1]['vat_rate']);
        $this->assertSame(0.0, $plan['months'][2]['vat_rate']);
        $this->assertSame(5.0, $plan['months'][3]['vat_rate']);
        $this->assertSame(500000.0, $plan['months'][3]['vat']);
        $this->assertSame(10_000_000.0, $plan['months'][3]['net']);
    }

    public function test_vat_from_january_if_prior_year_over_threshold(): void
    {
        $tax = app(TaxReportService::class);
        $rates = $tax->rates(2026);
        $months = array_fill(1, 12, 0.0);
        $months[1] = 105000;

        $plan = $tax->vatPlan($months, 25_000_000, $rates, 'special');

        $this->assertFalse($plan['exempt']);
        $this->assertSame(1, $plan['from_month']);
        $this->assertSame(5.0, $plan['months'][1]['vat_rate']);
        $this->assertSame(5000.0, $plan['months'][1]['vat']);
    }

    public function test_deadline_moves_from_weekend_to_monday(): void
    {
        $tax = app(TaxReportService::class);
        $this->assertSame('2026-06-29', $tax->shiftDeadline('2026-06-27'));
        $this->assertSame('2026-06-29', $tax->shiftDeadline('2026-06-28'));
        $this->assertSame('2026-04-28', $tax->shiftDeadline('2026-04-28'));
    }

    public function test_calendar_splits_vat_into_three_payments(): void
    {
        $tax = app(TaxReportService::class);
        $quarters = [
            1 => ['usn_advance' => 3000.0, 'vat' => 5000.0],
            2 => ['usn_advance' => 0.0, 'vat' => 0.0],
            3 => ['usn_advance' => 0.0, 'vat' => 0.0],
            4 => ['usn_advance' => 0.0, 'vat' => 0.0],
        ];
        $cal = $tax->calendar(2026, $quarters, [
            'fixed' => 57390.0,
            'extra' => 0.0,
        ], ['months' => []]);

        $vat = array_values(array_filter($cal['items'], fn ($row) => $row['kind'] === 'vat'));
        $this->assertCount(3, $vat);
        $this->assertSame(1667.0, $vat[0]['amount']);
        $this->assertSame(1667.0, $vat[1]['amount']);
        $this->assertSame(1666.0, $vat[2]['amount']);
        $this->assertSame('2026-04-28', $vat[0]['deadline']);
        $this->assertSame('2026-05-28', $vat[1]['deadline']);
        $this->assertSame('2026-06-29', $vat[2]['deadline']);
        $this->assertSame(57390.0, collect($cal['items'])->firstWhere('id', 'ip-fixed')['amount']);
    }
}
