<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\StaffLedger;
use App\Models\Transaction;
use App\Support\ClubBrand;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;

class TaxReportService
{
    /**
     * @return array<string, mixed>
     */
    public function forYear(int $year): array
    {
        $rates = $this->rates($year);
        $hasEmployees = (bool) config('taxes.has_employees', true);
        $usnRate = (float) config('taxes.usn_rate', 0.06);
        $injuryRate = (float) config('taxes.injury_rate', 0.002);
        $vatMode = (string) config('taxes.vat_mode', 'special');

        $monthsGross = [];
        for ($month = 1; $month <= 12; $month++) {
            $start = Carbon::create($year, $month, 1)->startOfDay();
            $end = $start->copy()->endOfMonth();
            $monthsGross[$month] = $this->incomeBetween($start, $end);
        }

        $grossYear = $this->money(array_sum($monthsGross));
        $yearStart = Carbon::create($year, 1, 1)->startOfDay();
        $yearEnd = Carbon::create($year, 12, 31)->endOfDay();
        $stubGross = $this->demoIncomeBetween($yearStart, $yearEnd);
        $priorGross = $this->incomeBetween(
            Carbon::create($year - 1, 1, 1)->startOfDay(),
            Carbon::create($year - 1, 12, 31)->endOfDay()
        );

        $vatPlan = $this->vatPlan($monthsGross, $priorGross, $rates, $vatMode);
        $payroll = $this->payrollYear($year, $rates, $injuryRate);
        if ($payroll['employee_count'] > 0) {
            $hasEmployees = true;
        }

        $quarters = [];
        $cumGross = 0.0;
        $cumNet = 0.0;
        $cumVat = 0.0;
        $cumPayrollContrib = 0.0;
        $prevUsnPaid = 0.0;

        for ($q = 1; $q <= 4; $q++) {
            $qGross = 0.0;
            $qNet = 0.0;
            $qVat = 0.0;
            for ($m = ($q - 1) * 3 + 1; $m <= $q * 3; $m++) {
                $qGross += $monthsGross[$m];
                $qNet += $vatPlan['months'][$m]['net'];
                $qVat += $vatPlan['months'][$m]['vat'];
            }
            $qGross = $this->money($qGross);
            $qNet = $this->money($qNet);
            $qVat = $this->money($qVat);

            $cumGross = $this->money($cumGross + $qGross);
            $cumNet = $this->money($cumNet + $qNet);
            $cumVat = $this->money($cumVat + $qVat);

            $qPayroll = $payroll['quarters'][$q];
            $cumPayrollContrib = $this->money($cumPayrollContrib + $qPayroll['employer_total']);

            $extra1pct = $this->ipExtra($cumNet, $rates);
            $ipPremiums = $this->money($rates['ip_fixed'] + $extra1pct);
            $deductiblePool = $this->money($ipPremiums + $cumPayrollContrib);

            $usnRaw = $this->money($cumNet * $usnRate);
            $usn = $this->usnPayable($usnRaw, $deductiblePool, $hasEmployees);
            $advance = $this->rub(max(0, $usn['to_pay'] - $prevUsnPaid));
            $prevUsnPaid = $usn['to_pay'];

            $deadline = $this->usnDeadline($year, $q);

            $quarters[$q] = [
                'gross' => $qGross,
                'vat' => $qVat,
                'net' => $qNet,
                'cumulative_gross' => $cumGross,
                'cumulative_net' => $cumNet,
                'cumulative_vat' => $cumVat,
                'usn_raw' => $this->rub($usnRaw),
                'deduction' => $usn['deduction'],
                'deduction_cap' => $usn['deduction_cap'],
                'deduction_pool' => $deductiblePool,
                'usn_cumulative' => $usn['to_pay'],
                'usn_advance' => $advance,
                'payroll' => $qPayroll,
                'deadline' => $deadline,
                'vat_deadline' => $this->vatDeadline($year, $q),
            ];
        }

        $extra1pct = $this->ipExtra($cumNet, $rates);
        $ipPremiums = [
            'fixed' => $rates['ip_fixed'],
            'extra' => $extra1pct,
            'total' => $this->money($rates['ip_fixed'] + $extra1pct),
            'fixed_deadline' => sprintf('%d-12-28', $year),
            'extra_deadline' => sprintf('%d-07-01', $year + 1),
        ];

        $yearUsn = $quarters[4];
        $warnings = $this->warnings($cumGross, $cumNet, $priorGross, $rates, $vatPlan);
        if ($stubGross > 0 || ! config('fiscal.enabled', false)) {
            array_unshift($warnings, [
                'level' => 'info',
                'text' => $stubGross > 0
                    ? 'Демо-чеки (касса выкл. / тестовый QR) не входят в УСН и НДС: '.number_format($stubGross, 0, ',', ' ').' ₽ за год. В базу попадают только фискализированные пополнения.'
                    : 'Касса выключена (FISCAL_ENABLED=false): тестовые чеки в налог не считаются. После включения ККТ в базу пойдут только чеки со статусом success.',
            ]);
        }
        $calendar = $this->calendar($year, $quarters, $ipPremiums, $payroll);

        return [
            'year' => $year,
            'profile' => [
                'entity' => 'ИП',
                'regime' => 'УСН доходы',
                'usn_rate_percent' => round($usnRate * 100, 2),
                'has_employees' => $hasEmployees,
                'vat_mode' => $vatMode,
                'injury_rate_percent' => round($injuryRate * 100, 3),
            ],
            'rates' => [
                'ip_fixed' => $rates['ip_fixed'],
                'ip_extra_max' => $rates['ip_extra_max'],
                'vat_exempt' => $rates['vat_exempt'],
                'vat_5_until' => $rates['vat_5_until'],
                'vat_7_until' => $rates['vat_7_until'],
                'usn_limit' => $rates['usn_limit'],
                'employer_base' => $rates['employer_base'],
                'standard_vat' => $rates['standard_vat'],
            ],
            'income' => [
                'gross' => $grossYear,
                'net' => $cumNet,
                'vat' => $cumVat,
                'prior_year_gross' => $priorGross,
                'months' => $monthsGross,
                'stub_gross' => $stubGross,
                'fiscal_live' => (bool) config('fiscal.enabled', false),
            ],
            'vat' => $vatPlan,
            'quarters' => $quarters,
            'premiums' => $ipPremiums,
            'payroll' => $payroll,
            'totals' => [
                'usn' => $yearUsn['usn_cumulative'],
                'vat' => $this->rub($cumVat),
                'ip_premiums' => $ipPremiums['total'],
                'employer_contributions' => $payroll['year']['employer_total'],
                'ndfl' => $payroll['year']['ndfl'],
                'injury' => $payroll['year']['injury'],
                'all' => $this->money(
                    $yearUsn['usn_cumulative']
                    + $this->rub($cumVat)
                    + $ipPremiums['total']
                    + $payroll['year']['employer_total']
                    + $payroll['year']['ndfl']
                ),
            ],
            'warnings' => $warnings,
            'calendar' => $calendar,
        ];
    }

    /**
     * Внутренний регистр доходов (не бланк Минфина) — для печати / PDF.
     *
     * @return array<string, mixed>
     */
    public function kudir(int $year): array
    {
        $report = $this->forYear($year);
        $start = Carbon::create($year, 1, 1)->startOfDay();
        $end = Carbon::create($year, 12, 31)->endOfDay();
        $vatMonths = $report['vat']['months'] ?? [];

        $lines = [];
        $query = $this->depositQuery($start, $end);
        if ($query !== null) {
            $this->constrainFiscalized($query);
            $rows = $query
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(['id', 'created_at', 'amount', 'source', 'description', 'fiscal_receipt_url', 'fiscal_status']);

            $n = 0;
            foreach ($rows as $tx) {
                $n++;
                $month = (int) $tx->created_at->month;
                $rate = (float) ($vatMonths[$month]['vat_rate'] ?? 0);
                $gross = $this->money((float) $tx->amount);
                $split = $this->splitGross($gross, $rate);
                $lines[] = [
                    'n' => $n,
                    'date' => $tx->created_at->format('d.m.Y'),
                    'id' => $tx->id,
                    'source' => $this->sourceLabel($tx->source),
                    'description' => $tx->description ?: 'Пополнение',
                    'gross' => $gross,
                    'vat_rate' => $rate,
                    'vat' => $split['vat'],
                    'net' => $split['net'],
                    'receipt' => $tx->fiscal_receipt_url,
                ];
            }
        }

        $legal = config('club.legal', []);

        return [
            'year' => $year,
            'generated_at' => now()->timezone(config('app.timezone'))->format('d.m.Y H:i'),
            'club' => ClubBrand::name(),
            'legal' => [
                'entity' => (string) ($legal['entity'] ?? ''),
                'inn' => (string) ($legal['inn'] ?? ''),
            ],
            'profile' => $report['profile'],
            'income' => [
                'gross' => $report['income']['gross'],
                'net' => $report['income']['net'],
                'vat' => $report['income']['vat'],
                'stub_gross' => $report['income']['stub_gross'],
                'fiscal_live' => $report['income']['fiscal_live'],
            ],
            'vat' => [
                'exempt' => $report['vat']['exempt'],
                'rate' => $report['vat']['rate'],
                'reason' => $report['vat']['reason'],
            ],
            'quarters' => $report['quarters'],
            'premiums' => $report['premiums'],
            'payroll' => [
                'employee_count' => $report['payroll']['employee_count'],
                'employees' => $report['payroll']['employees'],
                'year' => $report['payroll']['year'],
            ],
            'totals' => $report['totals'],
            'lines' => $lines,
        ];
    }

    public function incomeBetween(CarbonInterface $from, CarbonInterface $to): float
    {
        $query = $this->depositQuery($from, $to);
        if ($query === null) {
            return 0.0;
        }

        $this->constrainFiscalized($query);

        return $this->money((float) $query->sum('amount'));
    }

    public function demoIncomeBetween(CarbonInterface $from, CarbonInterface $to): float
    {
        $query = $this->depositQuery($from, $to);
        if ($query === null) {
            return 0.0;
        }

        $this->constrainDemoFiscal($query);

        return $this->money((float) $query->sum('amount'));
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Transaction>|null
     */
    private function depositQuery(CarbonInterface $from, CarbonInterface $to)
    {
        if (! Schema::hasTable('transactions')) {
            return null;
        }

        $query = Transaction::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('type', 'deposit')
            ->where('amount', '>', 0);

        if (Schema::hasColumn('transactions', 'is_taxable')) {
            $query->where('is_taxable', true);
        }

        $skip = array_map('strtolower', array_merge(
            config('taxes.non_cash_sources', []),
            config('fiscal.skip_advance_sources', [])
        ));
        $skip = array_values(array_unique($skip));

        if ($skip !== [] && Schema::hasColumn('transactions', 'source')) {
            $query->where(function ($inner) use ($skip) {
                $inner->whereNull('source')
                    ->orWhereNotIn('source', $skip);
            });
        }

        return $query;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Transaction>  $query
     */
    private function constrainFiscalized($query): void
    {
        if (Schema::hasColumn('transactions', 'fiscal_status')) {
            $query->where('fiscal_status', 'success');
        }

        if (Schema::hasColumn('transactions', 'fiscal_receipt_url')) {
            $query->where(function ($inner) {
                $inner->whereNull('fiscal_receipt_url')
                    ->orWhere('fiscal_receipt_url', 'not like', '%/receipt/stub/%');
            });
        }
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Transaction>  $query
     */
    private function constrainDemoFiscal($query): void
    {
        $query->where(function ($inner) {
            if (Schema::hasColumn('transactions', 'fiscal_status')) {
                $inner->where('fiscal_status', 'skipped');
            }
            if (Schema::hasColumn('transactions', 'fiscal_receipt_url')) {
                $inner->orWhere('fiscal_receipt_url', 'like', '%/receipt/stub/%');
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function rates(int $year): array
    {
        $years = config('taxes.years', []);
        ksort($years);

        if (isset($years[$year])) {
            return $years[$year];
        }

        $fallback = null;
        foreach ($years as $key => $row) {
            if ($key <= $year) {
                $fallback = $row;
            }
        }

        if ($fallback !== null) {
            return $fallback;
        }

        return end($years) ?: [
            'ip_fixed' => 57390.00,
            'ip_extra_max' => 401730.00,
            'vat_exempt' => 20_000_000,
            'vat_5_until' => 272_500_000,
            'vat_7_until' => 490_500_000,
            'usn_limit' => 490_500_000,
            'employer_base' => 2_979_000,
            'employer_rate' => 0.30,
            'employer_rate_over' => 0.151,
            'standard_vat' => 22.0,
            'ndfl' => [
                ['up_to' => 2_400_000, 'rate' => 0.13],
                ['up_to' => null, 'rate' => 0.22],
            ],
        ];
    }

    /**
     * НДС в цене чека (B2C): выделяем из суммы, которую заплатил гость.
     *
     * @return array{vat: float, net: float}
     */
    public function splitGross(float $gross, float $ratePercent): array
    {
        $gross = $this->money($gross);
        if ($ratePercent <= 0 || $gross <= 0) {
            return ['vat' => 0.0, 'net' => max(0.0, $gross)];
        }

        $vat = $this->money($gross * $ratePercent / (100 + $ratePercent));

        return [
            'vat' => $vat,
            'net' => $this->money($gross - $vat),
        ];
    }

    /**
     * @return array{deduction: float, deduction_cap: float, to_pay: float}
     */
    public function usnPayable(float $taxRaw, float $deductiblePremiums, bool $hasEmployees): array
    {
        $taxRaw = $this->money(max(0, $taxRaw));
        $deductiblePremiums = $this->money(max(0, $deductiblePremiums));
        $cap = $hasEmployees ? $this->money($taxRaw * 0.5) : $taxRaw;
        $deduction = $this->money(min($deductiblePremiums, $cap));

        return [
            'deduction' => $deduction,
            'deduction_cap' => $cap,
            'to_pay' => $this->rub(max(0, $taxRaw - $deduction)),
        ];
    }

    public function ipExtra(float $usnBase, array $rates): float
    {
        $threshold = (float) config('taxes.income_1pct_threshold', 300000);
        if ($usnBase <= $threshold) {
            return 0.0;
        }

        $extra = ($usnBase - $threshold) * 0.01;

        return $this->money(min($extra, (float) $rates['ip_extra_max']));
    }

    public function ndflOn(float $income, array $brackets): float
    {
        $income = max(0, $income);
        $tax = 0.0;
        $prev = 0.0;

        foreach ($brackets as $row) {
            $limit = $row['up_to'];
            $rate = (float) $row['rate'];
            $slice = $limit === null
                ? max(0, $income - $prev)
                : max(0, min($income, (float) $limit) - $prev);
            $tax += $slice * $rate;
            $prev = $limit === null ? $income : (float) $limit;
            if ($income <= $prev) {
                break;
            }
        }

        return $this->rub($tax);
    }

    /**
     * @return array{base: float, over: float, amount: float}
     */
    public function employerOn(float $ytdBefore, float $gross, array $rates): array
    {
        $limit = (float) $rates['employer_base'];
        $room = max(0, $limit - max(0, $ytdBefore));
        $base = min(max(0, $gross), $room);
        $over = max(0, $gross - $base);
        $amount = $this->money(
            $base * (float) $rates['employer_rate']
            + $over * (float) $rates['employer_rate_over']
        );

        return [
            'base' => $this->money($base),
            'over' => $this->money($over),
            'amount' => $amount,
        ];
    }

    /**
     * @param  array<int, float>  $monthsGross
     * @return array<string, mixed>
     */
    public function vatPlan(array $monthsGross, float $priorGross, array $rates, string $vatMode): array
    {
        $exemptLimit = (float) $rates['vat_exempt'];
        $fromMonth = $priorGross > $exemptLimit ? 1 : null;
        $ytdGross = 0.0;

        if ($fromMonth === null) {
            for ($m = 1; $m <= 12; $m++) {
                $ytdGross = $this->money($ytdGross + $monthsGross[$m]);
                if ($ytdGross > $exemptLimit) {
                    $fromMonth = $m === 12 ? null : $m + 1;
                    break;
                }
            }
        }

        $standard = (float) $rates['standard_vat'];
        $months = [];
        $ytdNet = 0.0;
        $ytdVat = 0.0;

        for ($m = 1; $m <= 12; $m++) {
            $gross = $monthsGross[$m];
            $active = $fromMonth !== null && $m >= $fromMonth;
            $rate = 0.0;
            if ($active) {
                $rate = $vatMode === 'standard'
                    ? $standard
                    : $this->specialVatRate($ytdNet, $rates);
            }

            $split = $this->splitGross($gross, $rate);
            $ytdNet = $this->money($ytdNet + $split['net']);
            $ytdVat = $this->money($ytdVat + $split['vat']);

            $months[$m] = [
                'gross' => $gross,
                'vat_rate' => $rate,
                'vat' => $split['vat'],
                'net' => $split['net'],
                'liable' => $active,
            ];
        }

        $exempt = $fromMonth === null;
        $appliedRate = 0.0;
        foreach (array_reverse($months, true) as $row) {
            if ($row['liable']) {
                $appliedRate = $row['vat_rate'];
                break;
            }
        }

        return [
            'exempt' => $exempt,
            'from_month' => $fromMonth,
            'rate' => $appliedRate,
            'mode' => $vatMode,
            'input_vat_deductible' => $vatMode === 'standard',
            'year_vat' => $ytdVat,
            'year_net' => $ytdNet,
            'months' => $months,
            'reason' => $exempt
                ? 'Выручка прошлого года и текущая — в пределах освобождения от НДС.'
                : ($priorGross > $exemptLimit
                    ? 'Прошлый год выше порога освобождения — НДС с 1 января.'
                    : 'В этом году превышен порог освобождения — НДС со следующего месяца.'),
        ];
    }

    private function specialVatRate(float $ytdNet, array $rates): float
    {
        if ($ytdNet > (float) $rates['vat_5_until']) {
            return 7.0;
        }

        return 5.0;
    }

    /**
     * @return array<string, mixed>
     */
    private function payrollYear(int $year, array $rates, float $injuryRate): array
    {
        $emptyQuarter = [
            'gross' => 0.0,
            'ndfl' => 0.0,
            'employer' => 0.0,
            'injury' => 0.0,
            'employer_total' => 0.0,
        ];
        $emptyMonth = [
            'gross' => 0.0,
            'ndfl' => 0.0,
            'employer' => 0.0,
            'injury' => 0.0,
        ];
        $quarters = [1 => $emptyQuarter, 2 => $emptyQuarter, 3 => $emptyQuarter, 4 => $emptyQuarter];
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[$m] = $emptyMonth;
        }

        if (! Schema::hasTable('admins') || ! Schema::hasTable('staff_ledgers')) {
            return [
                'employee_count' => 0,
                'employees' => [],
                'quarters' => $quarters,
                'months' => $months,
                'year' => $emptyQuarter,
            ];
        }

        $yearStart = Carbon::create($year, 1, 1)->startOfDay();
        $yearEnd = Carbon::create($year, 12, 31)->endOfDay();

        $employees = Admin::query()
            ->where('is_official_employee', true)
            ->where('role', '!=', Admin::ROLE_OWNER)
            ->where(function ($q) use ($yearEnd) {
                $q->whereNull('hired_at')->orWhere('hired_at', '<=', $yearEnd);
            })
            ->where(function ($q) use ($yearStart) {
                $q->whereNull('fired_at')->orWhere('fired_at', '>=', $yearStart);
            })
            ->orderBy('name')
            ->get(['id', 'name', 'role', 'base_rate', 'pay_type']);

        $rows = [];
        $yearGross = 0.0;
        $yearNdfl = 0.0;
        $yearEmployer = 0.0;
        $yearInjury = 0.0;

        foreach ($employees as $admin) {
            $accruals = StaffLedger::query()
                ->where('admin_id', $admin->id)
                ->where('type', StaffLedger::TYPE_ACCRUAL)
                ->whereBetween('created_at', [$yearStart, $yearEnd])
                ->orderBy('created_at')
                ->get(['amount', 'created_at']);

            $ytd = 0.0;
            $personGross = 0.0;
            $personNdfl = 0.0;
            $personEmployer = 0.0;
            $personInjury = 0.0;
            $byQuarter = [1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0];

            foreach ($accruals as $entry) {
                $gross = $this->money((float) $entry->amount);
                if ($gross <= 0) {
                    continue;
                }
                $month = (int) $entry->created_at->month;
                $q = (int) ceil($month / 3);
                $ndflBefore = $this->ndflOn($ytd, $rates['ndfl']);
                $employer = $this->employerOn($ytd, $gross, $rates);
                $ytd = $this->money($ytd + $gross);
                $ndflDelta = $this->money($this->ndflOn($ytd, $rates['ndfl']) - $ndflBefore);
                $injury = $this->money($gross * $injuryRate);

                $personGross += $gross;
                $personNdfl += $ndflDelta;
                $personEmployer += $employer['amount'];
                $personInjury += $injury;
                $byQuarter[$q] += $gross;

                $quarters[$q]['gross'] += $gross;
                $quarters[$q]['ndfl'] += $ndflDelta;
                $quarters[$q]['employer'] += $employer['amount'];
                $quarters[$q]['injury'] += $injury;
                $quarters[$q]['employer_total'] += $employer['amount'] + $injury;

                $months[$month]['gross'] += $gross;
                $months[$month]['ndfl'] += $ndflDelta;
                $months[$month]['employer'] += $employer['amount'];
                $months[$month]['injury'] += $injury;
            }

            $rows[] = [
                'id' => $admin->id,
                'name' => $admin->name,
                'role' => $admin->role,
                'gross' => $this->money($personGross),
                'ndfl' => $this->rub($personNdfl),
                'net' => $this->money($personGross - $this->rub($personNdfl)),
                'employer' => $this->money($personEmployer),
                'injury' => $this->money($personInjury),
                'quarters' => [
                    1 => $this->money($byQuarter[1]),
                    2 => $this->money($byQuarter[2]),
                    3 => $this->money($byQuarter[3]),
                    4 => $this->money($byQuarter[4]),
                ],
            ];

            $yearGross += $personGross;
            $yearNdfl += $personNdfl;
            $yearEmployer += $personEmployer;
            $yearInjury += $personInjury;
        }

        for ($q = 1; $q <= 4; $q++) {
            $quarters[$q] = [
                'gross' => $this->money($quarters[$q]['gross']),
                'ndfl' => $this->rub($quarters[$q]['ndfl']),
                'employer' => $this->money($quarters[$q]['employer']),
                'injury' => $this->money($quarters[$q]['injury']),
                'employer_total' => $this->money($quarters[$q]['employer_total']),
            ];
        }

        for ($m = 1; $m <= 12; $m++) {
            $months[$m] = [
                'gross' => $this->money($months[$m]['gross']),
                'ndfl' => $this->rub($months[$m]['ndfl']),
                'employer' => $this->money($months[$m]['employer']),
                'injury' => $this->money($months[$m]['injury']),
            ];
        }

        return [
            'employee_count' => $employees->count(),
            'employees' => $rows,
            'quarters' => $quarters,
            'months' => $months,
            'year' => [
                'gross' => $this->money($yearGross),
                'ndfl' => $this->rub($yearNdfl),
                'employer' => $this->money($yearEmployer),
                'injury' => $this->money($yearInjury),
                'employer_total' => $this->money($yearEmployer + $yearInjury),
            ],
        ];
    }

    /**
     * Срок с выходного переносится на следующий рабочий день (ст. 6.1 НК РФ).
     */
    public function shiftDeadline(string $isoDate): string
    {
        $date = Carbon::parse($isoDate)->startOfDay();
        while ($date->isWeekend()) {
            $date->addDay();
        }

        return $date->toDateString();
    }

    /**
     * @param  array<int, array<string, mixed>>  $quarters
     * @param  array<string, mixed>  $premiums
     * @param  array<string, mixed>  $payroll
     * @return array<string, mixed>
     */
    public function calendar(int $year, array $quarters, array $premiums, array $payroll): array
    {
        $today = now()->startOfDay();
        $items = [];

        $quarterNames = [
            1 => '1 квартал',
            2 => 'полугодие',
            3 => '9 месяцев',
            4 => 'год',
        ];

        for ($q = 1; $q <= 4; $q++) {
            $periodEnd = Carbon::create($year, $q * 3, 1)->endOfMonth()->startOfDay();
            $usn = (float) ($quarters[$q]['usn_advance'] ?? 0);
            if ($usn > 0) {
                $items[] = $this->calendarItem(
                    'usn-q'.$q,
                    'usn',
                    $q === 4 ? 'УСН по итогам года' : 'Аванс УСН за '.$quarterNames[$q],
                    'ЕНП',
                    $usn,
                    $this->usnDeadline($year, $q),
                    $periodEnd,
                    $today
                );
            }

            $vat = $this->rub((float) ($quarters[$q]['vat'] ?? 0));
            if ($vat > 0) {
                $parts = $this->splitInThirds($vat);
                $firstMonth = $q * 3 + 1;
                foreach ($parts as $index => $part) {
                    $pay = Carbon::create($year, 1, 28)->addMonths($firstMonth + $index - 1);
                    $items[] = $this->calendarItem(
                        'vat-q'.$q.'-'.($index + 1),
                        'vat',
                        'НДС '.($index + 1).'/3 за '.$quarterNames[$q],
                        'ЕНП · 1/3 квартала',
                        $part,
                        $pay->toDateString(),
                        $periodEnd,
                        $today
                    );
                }
            }
        }

        if ((float) $premiums['fixed'] > 0) {
            $items[] = $this->calendarItem(
                'ip-fixed',
                'ip_fixed',
                'Фикс взносы ИП за '.$year,
                'ЕНП · за себя',
                (float) $premiums['fixed'],
                sprintf('%d-12-28', $year),
                Carbon::create($year, 12, 28)->startOfDay(),
                $today
            );
        }

        if ((float) $premiums['extra'] > 0) {
            $items[] = $this->calendarItem(
                'ip-extra',
                'ip_extra',
                '1% свыше 300 тыс. за '.$year,
                'ЕНП · за себя',
                (float) $premiums['extra'],
                sprintf('%d-07-01', $year + 1),
                Carbon::create($year, 12, 31)->startOfDay(),
                $today
            );
        }

        $monthNames = [1 => 'январь', 2 => 'февраль', 3 => 'март', 4 => 'апрель', 5 => 'май', 6 => 'июнь', 7 => 'июль', 8 => 'август', 9 => 'сентябрь', 10 => 'октябрь', 11 => 'ноябрь', 12 => 'декабрь'];
        $payrollMonths = $payroll['months'] ?? [];
        for ($m = 1; $m <= 12; $m++) {
            $row = $payrollMonths[$m] ?? ['ndfl' => 0, 'employer' => 0, 'injury' => 0];
            $periodEnd = Carbon::create($year, $m, 1)->endOfMonth()->startOfDay();
            $label = $monthNames[$m].' '.$year;

            if ((float) $row['ndfl'] > 0) {
                $items[] = $this->calendarItem(
                    'ndfl-'.$m,
                    'ndfl',
                    'НДФЛ за '.$label,
                    'ЕНП · агент',
                    (float) $row['ndfl'],
                    Carbon::create($year, $m, 28)->addMonth()->toDateString(),
                    $periodEnd,
                    $today
                );
            }
            if ((float) $row['employer'] > 0) {
                $items[] = $this->calendarItem(
                    'employer-'.$m,
                    'employer',
                    'Взносы за штат · '.$label,
                    'ЕНП · 30 / 15,1%',
                    (float) $row['employer'],
                    Carbon::create($year, $m, 28)->addMonth()->toDateString(),
                    $periodEnd,
                    $today
                );
            }
            if ((float) $row['injury'] > 0) {
                $items[] = $this->calendarItem(
                    'injury-'.$m,
                    'injury',
                    'Травматизм за '.$label,
                    'СФР · не ЕНП',
                    (float) $row['injury'],
                    Carbon::create($year, $m, 15)->addMonth()->toDateString(),
                    $periodEnd,
                    $today
                );
            }
        }

        usort($items, function (array $a, array $b) {
            return [$a['deadline'], $a['id']] <=> [$b['deadline'], $b['id']];
        });

        $monthsOut = [];
        foreach ($items as $item) {
            $key = substr($item['deadline'], 0, 7);
            if (! isset($monthsOut[$key])) {
                $cursor = Carbon::parse($item['deadline']);
                $monthsOut[$key] = [
                    'key' => $key,
                    'year' => (int) $cursor->year,
                    'month' => (int) $cursor->month,
                    'label' => $monthNames[(int) $cursor->month].' '.$cursor->year,
                    'total' => 0.0,
                    'items' => [],
                ];
            }
            $monthsOut[$key]['items'][] = $item;
            $monthsOut[$key]['total'] = $this->money($monthsOut[$key]['total'] + $item['amount']);
        }

        $open = array_values(array_filter($items, fn ($row) => in_array($row['status'], ['due_soon', 'upcoming', 'planned'], true)));
        $thisMonthKey = $today->format('Y-m');

        return [
            'today' => $today->toDateString(),
            'next' => $open[0] ?? null,
            'this_month_total' => (float) ($monthsOut[$thisMonthKey]['total'] ?? 0),
            'overdue_total' => $this->money(array_sum(array_map(
                fn ($row) => $row['status'] === 'overdue' ? $row['amount'] : 0,
                $items
            ))),
            'due_soon_count' => count(array_filter($items, fn ($row) => $row['status'] === 'due_soon')),
            'overdue_count' => count(array_filter($items, fn ($row) => $row['status'] === 'overdue')),
            'items' => array_values($items),
            'months' => array_values($monthsOut),
        ];
    }

    /**
     * @return array{id: string, kind: string, title: string, hint: string, amount: float, deadline: string, status: string}
     */
    private function calendarItem(
        string $id,
        string $kind,
        string $title,
        string $hint,
        float $amount,
        string $deadline,
        Carbon $periodEnd,
        Carbon $today,
    ): array {
        $due = Carbon::parse($this->shiftDeadline($deadline))->startOfDay();

        if ($today->lt($periodEnd)) {
            $status = 'planned';
        } elseif ($due->lt($today)) {
            $status = 'overdue';
        } elseif ($due->lte($today->copy()->addDays(14))) {
            $status = 'due_soon';
        } else {
            $status = 'upcoming';
        }

        return [
            'id' => $id,
            'kind' => $kind,
            'title' => $title,
            'hint' => $hint,
            'amount' => $this->money($amount),
            'deadline' => $due->toDateString(),
            'status' => $status,
        ];
    }

    /**
     * @return list<float>
     */
    private function splitInThirds(float $amount): array
    {
        $amount = $this->rub($amount);
        $one = $this->rub($amount / 3);
        $two = $one;
        $three = $this->rub($amount - $one - $two);

        return [$one, $two, $three];
    }

    /**
     * @return list<array{level: string, text: string}>
     */
    private function warnings(float $gross, float $net, float $prior, array $rates, array $vatPlan): array
    {
        $out = [];
        $exempt = (float) $rates['vat_exempt'];
        $usnLimit = (float) $rates['usn_limit'];

        if ($vatPlan['exempt'] && $gross > $exempt * 0.8) {
            $out[] = [
                'level' => 'warn',
                'text' => 'До порога НДС ('.number_format($exempt, 0, ',', ' ').' ₽) осталось меньше 20% — после превышения НДС со следующего месяца.',
            ];
        }

        if (! $vatPlan['exempt'] && $vatPlan['rate'] > 0) {
            $out[] = [
                'level' => 'info',
                'text' => $vatPlan['input_vat_deductible']
                    ? 'НДС '.rtrim(rtrim(number_format($vatPlan['rate'], 1, ',', ''), '0'), ',').'% с вычетом входного.'
                    : 'НДС '.rtrim(rtrim(number_format($vatPlan['rate'], 1, ',', ''), '0'), ',').'% спецставка: входной НДС к вычету не принимается, из базы УСН сумма НДС исключена.',
            ];
        }

        if ($net > $usnLimit * 0.9) {
            $out[] = [
                'level' => 'warn',
                'text' => 'Близко к лимиту УСН ('.number_format($usnLimit, 0, ',', ' ').' ₽). При превышении — общий режим.',
            ];
        }

        if ($prior > $exempt && $vatPlan['exempt'] === false) {
            $out[] = [
                'level' => 'info',
                'text' => 'Освобождение от НДС в этом году недоступно: выручка прошлого года выше порога.',
            ];
        }

        return $out;
    }

    private function usnDeadline(int $year, int $quarter): string
    {
        return match ($quarter) {
            1 => sprintf('%d-04-28', $year),
            2 => sprintf('%d-07-28', $year),
            3 => sprintf('%d-10-28', $year),
            default => sprintf('%d-04-28', $year + 1),
        };
    }

    private function vatDeadline(int $year, int $quarter): string
    {
        return match ($quarter) {
            1 => sprintf('%d-04-28', $year),
            2 => sprintf('%d-07-28', $year),
            3 => sprintf('%d-10-28', $year),
            default => sprintf('%d-01-28', $year + 1),
        };
    }

    private function sourceLabel(?string $source): string
    {
        return match (strtolower(trim((string) $source))) {
            'cash', 'admin_cash' => 'Касса',
            'card' => 'Карта',
            'sbp' => 'СБП',
            'yookassa' => 'ЮKassa',
            default => $source !== null && $source !== '' ? $source : 'Пополнение',
        };
    }

    private function money(float $value): float
    {
        return round($value, 2);
    }

    private function rub(float $value): float
    {
        return (float) round($value, 0);
    }
}
