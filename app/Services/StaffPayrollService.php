<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Shift;
use App\Models\ShiftIntern;
use App\Models\StaffLedger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StaffPayrollService
{
    public function syncFor(Admin $admin): void
    {
        $this->accrueClosedShifts($admin);
        $this->accrueClosedInternShifts($admin);
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

    public function accrueClosedShiftInterns(Shift $shift): void
    {
        $shift->loadMissing('internSlots.admin');

        foreach ($shift->internSlots as $slot) {
            $intern = $slot->admin;
            if (! $intern) {
                continue;
            }
            $this->accrueForParticipant($intern, $shift, 'Стажировка · смена #'.$shift->id);
        }
    }

    public function accrueForParticipant(Admin $admin, Shift $shift, string $reason): ?StaffLedger
    {
        if ($shift->status !== 'closed' || ! $shift->ended_at) {
            return null;
        }

        if ($admin->pay_type !== 'shift') {
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
                'reason' => $reason,
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

    /**
     * Недостача на пересменке, которую сдающий не списал со склада.
     *
     * @param  list<array{name:string,expected:int,actual:int,cost:float,price:float}>  $lines
     */
    public function chargeHandoverShortage(Admin $outgoing, Admin $incoming, ?Shift $shift, array $lines): ?StaffLedger
    {
        $parts = [];
        $total = 0.0;

        foreach ($lines as $line) {
            $missing = (int) $line['expected'] - (int) $line['actual'];
            if ($missing <= 0) {
                continue;
            }

            $unit = (float) $line['cost'] > 0 ? (float) $line['cost'] : (float) $line['price'];
            $sum = round($missing * max(0, $unit), 2);
            $total = round($total + $sum, 2);
            $parts[] = $line['name'].' −'.$missing.($sum > 0 ? ' ('.$sum.' ₽)' : '');
        }

        if ($parts === []) {
            return null;
        }

        $reason = 'Недостача при передаче смены: '.implode('; ', $parts);

        $attrs = [
            'admin_id' => $outgoing->id,
            'type' => StaffLedger::TYPE_FINE,
            'shift_id' => $shift?->id,
        ];

        return StaffLedger::query()->firstOrCreate(
            $attrs,
            [
                'amount' => $total,
                'reason' => $reason,
                'created_by' => $incoming->id,
            ]
        );
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

        $shifts = $admin->isIntern()
            ? $this->internShiftRows($admin)
            : $this->leadShiftRows($admin);

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

    private function accrueClosedInternShifts(Admin $admin): void
    {
        if ($admin->pay_type !== 'shift' || ! $admin->isIntern()) {
            return;
        }

        ShiftIntern::query()
            ->where('admin_id', $admin->id)
            ->whereHas('shift', fn ($q) => $q->where('status', 'closed')->whereNotNull('ended_at'))
            ->with('shift')
            ->get()
            ->each(function (ShiftIntern $slot) use ($admin) {
                if ($slot->shift) {
                    $this->accrueForParticipant($admin, $slot->shift, 'Стажировка · смена #'.$slot->shift_id);
                }
            });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function leadShiftRows(Admin $admin): array
    {
        return Shift::query()
            ->where('admin_id', $admin->id)
            ->with('ledgerAccrual')
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (Shift $shift) => $this->mapShiftRow(
                $shift,
                $shift->started_at,
                $shift->ended_at,
                $shift->ledgerAccrual ? (float) $shift->ledgerAccrual->amount : 0.0
            ))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function internShiftRows(Admin $admin): array
    {
        return ShiftIntern::query()
            ->where('admin_id', $admin->id)
            ->with(['shift.ledgerAccrual'])
            ->orderByDesc('joined_at')
            ->limit(100)
            ->get()
            ->map(function (ShiftIntern $slot) use ($admin) {
                $shift = $slot->shift;
                $accrual = StaffLedger::query()
                    ->where('admin_id', $admin->id)
                    ->where('shift_id', $slot->shift_id)
                    ->where('type', StaffLedger::TYPE_ACCRUAL)
                    ->first();

                return $this->mapShiftRow(
                    $shift,
                    $slot->joined_at,
                    $slot->left_at ?? $shift?->ended_at,
                    $accrual ? (float) $accrual->amount : 0.0,
                    $shift?->status ?? 'open'
                );
            })
            ->values()
            ->all();
    }

    private function mapShiftRow(
        ?Shift $shift,
        $started,
        $ended,
        float $accrued,
        ?string $status = null,
    ): array {
        $status = $status ?? $shift?->status ?? 'closed';
        $isOpen = $status !== 'closed';
        $endForDuration = $ended ?? ($isOpen ? now() : null);
        $minutes = ($started && $endForDuration)
            ? (int) $started->diffInMinutes($endForDuration)
            : null;

        return [
            'id' => $shift?->id,
            'started_at' => $started?->toIso8601String(),
            'ended_at' => $ended?->toIso8601String(),
            'status' => $status,
            'is_open' => $isOpen,
            'duration_minutes' => $minutes,
            'accrued' => $accrued,
        ];
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
