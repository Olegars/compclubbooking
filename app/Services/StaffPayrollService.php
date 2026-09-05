<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Shift;
use App\Models\StaffLedger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StaffPayrollService
{
    public function syncFor(Admin $admin): void
    {
        $this->accrueClosedShifts($admin);
        $this->accrueMonthlyPeriods($admin);
    }

    public function accrueClosedShift(Shift $shift): ?StaffLedger
    {
        if ($shift->status !== 'closed' || ! $shift->ended_at) {
            return null;
        }

        $shift->loadMissing('admin');
        $admin = $shift->admin;
        if (! $admin || $admin->pay_type !== 'shift') {
            return null;
        }

        $rate = round((float) $admin->base_rate, 2);
        if ($rate <= 0) {
            return null;
        }

        return StaffLedger::query()->firstOrCreate(
            [
                'admin_id' => $admin->id,
                'shift_id' => $shift->id,
                'type' => StaffLedger::TYPE_ACCRUAL,
            ],
            [
                'amount' => $rate,
                'reason' => 'Смена #'.$shift->id,
            ]
        );
    }

    public function addFine(Admin $target, float $amount, string $reason, Admin $by): StaffLedger
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new RuntimeException('Сумма штрафа должна быть больше нуля.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('Укажите причину штрафа.');
        }

        if ((int) $target->id === (int) $by->id) {
            throw new RuntimeException('Нельзя выписать штраф самому себе.');
        }

        return StaffLedger::query()->create([
            'admin_id' => $target->id,
            'type' => StaffLedger::TYPE_FINE,
            'amount' => $amount,
            'reason' => $reason,
            'created_by' => $by->id,
        ]);
    }

    public function withdraw(Admin $admin, ?float $amount = null): StaffLedger
    {
        return DB::transaction(function () use ($admin, $amount) {
            /** @var Admin $locked */
            $locked = Admin::query()->lockForUpdate()->findOrFail($admin->id);
            $this->syncFor($locked);

            $available = $this->available($locked);
            $sum = $amount === null ? $available : round($amount, 2);

            if ($sum <= 0) {
                throw new RuntimeException('Нет суммы к выводу.');
            }

            if ($sum > $available + 0.001) {
                throw new RuntimeException('Недостаточно средств к выводу.');
            }

            return StaffLedger::query()->create([
                'admin_id' => $locked->id,
                'type' => StaffLedger::TYPE_PAYOUT,
                'amount' => $sum,
                'reason' => 'Вывод зарплаты',
                'created_by' => $locked->id,
            ]);
        });
    }

    public function available(Admin $admin): float
    {
        return max(0, $this->balance($admin));
    }

    public function balance(Admin $admin): float
    {
        $accrued = (float) StaffLedger::query()
            ->where('admin_id', $admin->id)
            ->where('type', StaffLedger::TYPE_ACCRUAL)
            ->sum('amount');
        $fines = (float) StaffLedger::query()
            ->where('admin_id', $admin->id)
            ->where('type', StaffLedger::TYPE_FINE)
            ->sum('amount');
        $payouts = (float) StaffLedger::query()
            ->where('admin_id', $admin->id)
            ->where('type', StaffLedger::TYPE_PAYOUT)
            ->sum('amount');

        return round($accrued - $fines - $payouts, 2);
    }

    /**
     * @return array{
     *     pay_type: ?string,
     *     base_rate: ?float,
     *     accrued_total: float,
     *     fines_total: float,
     *     payouts_total: float,
     *     balance: float,
     *     available: float,
     *     shifts: list<array<string, mixed>>,
     *     fines: list<array<string, mixed>>,
     *     payouts: list<array<string, mixed>>,
     *     monthly_accruals: list<array<string, mixed>>
     * }
     */
    public function snapshot(Admin $admin): array
    {
        $this->syncFor($admin);

        $shifts = Shift::query()
            ->where('admin_id', $admin->id)
            ->with('ledgerAccrual')
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(function (Shift $shift) {
                $started = $shift->started_at;
                $ended = $shift->ended_at;
                $isOpen = $shift->status !== 'closed';
                $endForDuration = $ended ?? ($isOpen ? now() : null);
                $minutes = ($started && $endForDuration)
                    ? (int) $started->diffInMinutes($endForDuration)
                    : null;

                return [
                    'id' => $shift->id,
                    'started_at' => $started?->toIso8601String(),
                    'ended_at' => $ended?->toIso8601String(),
                    'status' => $shift->status,
                    'is_open' => $isOpen,
                    'duration_minutes' => $minutes,
                    'accrued' => $shift->ledgerAccrual ? (float) $shift->ledgerAccrual->amount : 0.0,
                ];
            })
            ->values()
            ->all();

        $mapEntry = function (StaffLedger $row) {
            return [
                'id' => $row->id,
                'amount' => (float) $row->amount,
                'reason' => $row->reason,
                'created_at' => $row->created_at?->toIso8601String(),
                'author' => $row->author?->name,
            ];
        };

        $fines = StaffLedger::query()
            ->where('admin_id', $admin->id)
            ->where('type', StaffLedger::TYPE_FINE)
            ->with('author:id,name')
            ->orderByDesc('id')
            ->get()
            ->map($mapEntry)
            ->values()
            ->all();

        $payouts = StaffLedger::query()
            ->where('admin_id', $admin->id)
            ->where('type', StaffLedger::TYPE_PAYOUT)
            ->orderByDesc('id')
            ->get()
            ->map($mapEntry)
            ->values()
            ->all();

        $monthly = StaffLedger::query()
            ->where('admin_id', $admin->id)
            ->where('type', StaffLedger::TYPE_ACCRUAL)
            ->whereNull('shift_id')
            ->orderByDesc('id')
            ->get()
            ->map($mapEntry)
            ->values()
            ->all();

        $accruedTotal = round((float) StaffLedger::query()
            ->where('admin_id', $admin->id)
            ->where('type', StaffLedger::TYPE_ACCRUAL)
            ->sum('amount'), 2);
        $finesTotal = round((float) StaffLedger::query()
            ->where('admin_id', $admin->id)
            ->where('type', StaffLedger::TYPE_FINE)
            ->sum('amount'), 2);
        $payoutsTotal = round((float) StaffLedger::query()
            ->where('admin_id', $admin->id)
            ->where('type', StaffLedger::TYPE_PAYOUT)
            ->sum('amount'), 2);
        $balance = round($accruedTotal - $finesTotal - $payoutsTotal, 2);

        return [
            'pay_type' => $admin->pay_type,
            'base_rate' => $admin->base_rate !== null ? (float) $admin->base_rate : null,
            'accrued_total' => $accruedTotal,
            'fines_total' => $finesTotal,
            'payouts_total' => $payoutsTotal,
            'balance' => $balance,
            'available' => max(0, $balance),
            'shifts' => $shifts,
            'fines' => $fines,
            'payouts' => $payouts,
            'monthly_accruals' => $monthly,
        ];
    }

    private function accrueClosedShifts(Admin $admin): void
    {
        if ($admin->pay_type !== 'shift') {
            return;
        }

        Shift::query()
            ->where('admin_id', $admin->id)
            ->where('status', 'closed')
            ->whereNotNull('ended_at')
            ->whereDoesntHave('ledgerAccrual')
            ->with('admin')
            ->get()
            ->each(fn (Shift $shift) => $this->accrueClosedShift($shift));
    }

    private function accrueMonthlyPeriods(Admin $admin): void
    {
        if ($admin->pay_type !== 'monthly') {
            return;
        }

        $rate = round((float) $admin->base_rate, 2);
        if ($rate <= 0) {
            return;
        }

        $hired = ($admin->created_at ?? now())->copy()->startOfMonth();
        $lastCompleted = now()->copy()->startOfMonth()->subMonth();

        if ($hired->gt($lastCompleted)) {
            return;
        }

        for ($cursor = $hired->copy(); $cursor->lte($lastCompleted); $cursor->addMonth()) {
            $key = $cursor->format('Y-m');

            StaffLedger::query()->firstOrCreate(
                [
                    'admin_id' => $admin->id,
                    'type' => StaffLedger::TYPE_ACCRUAL,
                    'period_key' => $key,
                ],
                [
                    'amount' => $rate,
                    'reason' => 'Оклад за '.$cursor->format('m.Y'),
                ]
            );
        }
    }
}
