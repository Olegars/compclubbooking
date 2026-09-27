<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Computer;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftSlotBooking;
use App\Models\StaffBonusSetting;
use App\Models\StaffBonusSettlement;
use App\Models\StaffLedger;
use App\Models\StaffQuarterReserve;
use App\Models\StaffXpTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Баллы эффективности смены.
 * 70% месяца проводится в ведомость, 30% копится в квартальном фонде надёжности.
 * Прогул по ЛНА обнуляет фонд и незакрытые баллы квартала. Уже проведённая премия не отзывается.
 */
class StaffBonusService
{
    public function awardClosedShift(Shift $shift, array $shortageLines = []): void
    {
        if (! Schema::hasTable('staff_xp_transactions') || ! $shift->admin_id || ! $shift->ended_at) {
            return;
        }
        if (StaffXpTransaction::query()->where('shift_id', $shift->id)->exists()) {
            return;
        }

        $shift->loadMissing('admin');
        $admin = $shift->admin;
        if (! $admin || ! in_array($admin->role, [Admin::ROLE_ADMIN, Admin::ROLE_SUPERVISOR], true)) {
            return;
        }
        if ($admin->isFired()) {
            return;
        }

        $ended = $shift->ended_at instanceof Carbon ? $shift->ended_at : Carbon::parse($shift->ended_at);
        if ($this->quarterIsBurned((int) $admin->id, $ended)) {
            return;
        }

        $period = $ended->format('Y-m');
        $lines = $this->scoreShift($shift, $admin, $shortageLines);

        DB::transaction(function () use ($admin, $shift, $period, $lines) {
            if (StaffXpTransaction::query()->where('shift_id', $shift->id)->exists()) {
                return;
            }
            foreach ($lines as $line) {
                StaffXpTransaction::query()->create([
                    'admin_id' => $admin->id,
                    'club_id' => $admin->club_id,
                    'shift_id' => $shift->id,
                    'amount_xp' => (int) $line['amount_xp'],
                    'action_type' => $line['action_type'],
                    'description' => $line['description'],
                    'period_key' => $period,
                ]);
            }
        });
    }

    public function manualAdjust(Admin $actor, Admin $target, int $xp, string $reason): StaffXpTransaction
    {
        if (! in_array($actor->role, [Admin::ROLE_SUPERVISOR, Admin::ROLE_OWNER], true)) {
            throw new RuntimeException('Баллы правит управляющий или владелец.');
        }
        if ((int) $actor->id === (int) $target->id) {
            throw new RuntimeException('Нельзя менять баллы себе.');
        }
        if ($target->isFired()) {
            throw new RuntimeException('Уволенному баллы не начисляются.');
        }
        if (! in_array($target->role, [Admin::ROLE_ADMIN, Admin::ROLE_SUPERVISOR], true)) {
            throw new RuntimeException('Баллы эффективности есть у админа зала и управляющего.');
        }
        if ($xp === 0 || $xp < -500 || $xp > 500) {
            throw new RuntimeException('Разовая правка — от −500 до 500 XP, кроме нуля.');
        }

        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new RuntimeException('Укажите причину правки.');
        }
        if (preg_match('/штраф|удержан|вычет/ui', $reason)) {
            throw new RuntimeException('В причине используйте формулировки положения о баллах эффективности.');
        }
        if ($this->quarterIsBurned((int) $target->id, now())) {
            throw new RuntimeException('Квартальный фонд этого сотрудника аннулирован по ЛНА.');
        }

        return StaffXpTransaction::query()->create([
            'admin_id' => $target->id,
            'club_id' => $target->club_id,
            'amount_xp' => $xp,
            'action_type' => StaffXpTransaction::MANUAL,
            'description' => $reason,
            'period_key' => now()->format('Y-m'),
            'created_by' => $actor->id,
        ]);
    }

    /**
     * @return array{months: int, quarters: int}
     */
    public function closeDuePeriods(?Carbon $now = null): array
    {
        $now = ($now ?? now())->copy();
        $yesterday = $now->copy()->subDay();
        $months = 0;
        $quarters = 0;

        if ($yesterday->day === $yesterday->daysInMonth) {
            $months = $this->closeMonth($yesterday->copy()->startOfMonth());
            if ($yesterday->month % 3 === 0) {
                $quarters = $this->closeQuarter((int) $yesterday->year, (int) ceil($yesterday->month / 3));
            }
        }

        return ['months' => $months, 'quarters' => $quarters];
    }

    public function closeMonth(Carbon $month, ?Admin $actor = null): int
    {
        if (! Schema::hasTable('staff_xp_transactions')) {
            return 0;
        }

        $month = $month->copy()->startOfMonth();
        if ($month->greaterThan(now()->copy()->startOfMonth())) {
            throw new RuntimeException('Нельзя закрыть будущий месяц.');
        }

        $period = $month->format('Y-m');
        $adminIds = StaffXpTransaction::query()
            ->where('period_key', $period)
            ->whereNull('settlement_id')
            ->distinct()
            ->pluck('admin_id');

        $count = 0;
        foreach ($adminIds as $adminId) {
            if ($this->settleMonthFor((int) $adminId, $month, $actor)) {
                $count++;
            }
        }

        return $count;
    }

    public function closeQuarter(int $year, int $quarter, ?Admin $actor = null): int
    {
        if ($quarter < 1 || $quarter > 4) {
            throw new RuntimeException('Неизвестный квартал.');
        }

        $end = Carbon::create($year, $quarter * 3, 1)->endOfMonth();
        if (now()->lessThanOrEqualTo($end)) {
            throw new RuntimeException('Квартал ещё не закончился.');
        }

        $startMonth = ($quarter - 1) * 3 + 1;
        for ($m = $startMonth; $m < $startMonth + 3; $m++) {
            $this->closeMonth(Carbon::create($year, $m, 1)->startOfMonth(), $actor);
        }

        $count = 0;
        $reserves = StaffQuarterReserve::query()
            ->where('year', $year)
            ->where('quarter', $quarter)
            ->get();

        foreach ($reserves as $reserve) {
            if ($this->releaseReserve($reserve, $actor)) {
                $count++;
            }
        }

        return $count;
    }

    public function forfeitQuarter(int $adminId, ?Carbon $at = null): float
    {
        $at = $at ?? now();
        $year = (int) $at->year;
        $quarter = (int) ceil($at->month / 3);

        $reserve = StaffQuarterReserve::query()->firstOrCreate(
            ['admin_id' => $adminId, 'year' => $year, 'quarter' => $quarter],
            ['points' => 0]
        );
        $points = (float) $reserve->points;
        $reserve->points = 0;
        if (Schema::hasColumn('staff_quarter_reserves', 'burned_at')) {
            $reserve->burned_at = $at;
        }
        $reserve->save();

        $this->offsetUnsettledQuarter($adminId, $year, $quarter, $at);

        return $points;
    }

    public function updateSettings(float $rate, float $barTarget): StaffBonusSetting
    {
        if ($rate < 0.01 || $rate > 1000) {
            throw new RuntimeException('Курс баллов — от 0,01 до 1000 ₽.');
        }
        if ($barTarget < 0 || $barTarget > 10000000) {
            throw new RuntimeException('План бара вне допустимого диапазона.');
        }

        $row = $this->settingsRow();
        $row->xp_to_rub_rate = round($rate, 2);
        $row->bar_target_rub = round($barTarget, 2);
        $row->save();

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function cabinet(Admin $admin): ?array
    {
        if ($admin->isOwner() || $admin->isStoreRole() || ! Schema::hasTable('staff_xp_transactions')) {
            return null;
        }

        $rate = $this->rate();
        $now = now();
        $period = $now->format('Y-m');
        $year = (int) $now->year;
        $quarter = (int) ceil($now->month / 3);
        $open = $this->openXp((int) $admin->id, $period);
        $reserve = $this->reserve((int) $admin->id, $year, $quarter);
        $burned = $reserve->burned_at !== null;
        $split = $this->splitRub(max(0, $open), $rate);
        $lifetime = (int) StaffXpTransaction::query()
            ->where('admin_id', $admin->id)
            ->where('amount_xp', '>', 0)
            ->sum('amount_xp');

        $history = StaffXpTransaction::query()
            ->where('admin_id', $admin->id)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (StaffXpTransaction $row) => [
                'id' => $row->id,
                'amount_xp' => (int) $row->amount_xp,
                'description' => $row->description,
                'created_at' => $row->created_at?->timezone(config('app.timezone'))->format('d.m.Y H:i'),
            ])
            ->all();

        return [
            'level' => 1 + intdiv(max(0, $lifetime), 500),
            'open_xp' => $open,
            'rate' => $rate,
            'month_label' => $period,
            'month_rub' => $burned ? 0.0 : $split['paid'],
            'month_xp' => $burned ? 0 : (int) round(max(0, $open) * 0.70),
            'safe_rub' => $burned ? 0.0 : round((float) $reserve->points, 2),
            'safe_preview_rub' => $burned ? 0.0 : $split['held'],
            'days_until_open' => $this->daysUntilQuarterOpens($year, $quarter),
            'quarter_label' => sprintf('%d-Q%d', $year, $quarter),
            'burned' => $burned,
            'history' => $history,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $rate = $this->rate();
        $target = $this->barTarget();
        $now = now();
        $period = $now->format('Y-m');
        $year = (int) $now->year;
        $quarter = (int) ceil($now->month / 3);

        $open = StaffXpTransaction::query()
            ->where('period_key', $period)
            ->whereNull('settlement_id')
            ->selectRaw('admin_id, SUM(amount_xp) as xp')
            ->groupBy('admin_id')
            ->pluck('xp', 'admin_id');

        $reserves = StaffQuarterReserve::query()
            ->where('year', $year)
            ->where('quarter', $quarter)
            ->get()
            ->keyBy('admin_id');

        $rows = Admin::query()
            ->whereIn('role', [Admin::ROLE_ADMIN, Admin::ROLE_SUPERVISOR])
            ->whereNull('fired_at')
            ->orderBy('name')
            ->get(['id', 'name', 'role'])
            ->map(function (Admin $admin) use ($open, $reserves, $rate) {
                $xp = (int) ($open[$admin->id] ?? 0);
                $reserve = $reserves->get($admin->id);
                $burned = $reserve?->burned_at !== null;
                $split = $this->splitRub(max(0, $xp), $rate);

                return [
                    'id' => $admin->id,
                    'name' => $admin->name,
                    'role' => $admin->role,
                    'open_xp' => $xp,
                    'month_rub' => $burned ? 0.0 : $split['paid'],
                    'safe_rub' => $burned ? 0.0 : round((float) ($reserve->points ?? 0), 2),
                    'burned' => $burned,
                ];
            })
            ->sortByDesc('open_xp')
            ->values()
            ->all();

        return [
            'rate' => $rate,
            'bar_target_rub' => $target,
            'month_label' => $period,
            'quarter_label' => sprintf('%d-Q%d', $year, $quarter),
            'days_until_open' => $this->daysUntilQuarterOpens($year, $quarter),
            'previous_month' => $now->copy()->subMonthNoOverflow()->format('Y-m'),
            'previous_quarter' => $this->lastCompletedQuarterLabel($now),
            'rows' => $rows,
        ];
    }

    /**
     * @param  list<array{name?:string,expected?:int,actual?:int}>  $shortageLines
     * @return list<array{action_type:string,amount_xp:int,description:string}>
     */
    private function scoreShift(Shift $shift, Admin $admin, array $shortageLines): array
    {
        $awards = config('staff_bonus.awards');
        $lines = [];
        $late = $this->isLate($shift, $admin);

        if ($late) {
            $lines[] = [
                'action_type' => StaffXpTransaction::LATE_SHIFT,
                'amount_xp' => 0,
                'description' => 'Неначисление переменной части: вход позже слота',
            ];
        } else {
            $lines[] = [
                'action_type' => StaffXpTransaction::SHIFT_BASE,
                'amount_xp' => (int) $awards['shift_base'],
                'description' => 'Базовые баллы эффективности смены',
            ];
        }

        $missing = 0;
        foreach ($shortageLines as $line) {
            $missing += max(0, (int) ($line['expected'] ?? 0) - (int) ($line['actual'] ?? 0));
        }

        if ($missing > 0) {
            $lines[] = [
                'action_type' => StaffXpTransaction::SHORTAGE,
                'amount_xp' => (int) $awards['shortage'],
                'description' => 'Неначисление: расхождение склада при пересменке',
            ];
        } else {
            $lines[] = [
                'action_type' => StaffXpTransaction::BAR_PERFECT,
                'amount_xp' => (int) $awards['bar_perfect'],
                'description' => 'Склад бара без расхождений',
            ];
        }

        if ($this->hardwareGreen($shift, $admin)) {
            $lines[] = [
                'action_type' => StaffXpTransaction::HARDWARE_GREEN,
                'amount_xp' => (int) $awards['hardware_green'],
                'description' => 'Станции зала без сбоев за смену',
            ];
        }

        if ($this->barRevenue($shift) + 0.001 >= $this->barTarget()) {
            $lines[] = [
                'action_type' => StaffXpTransaction::BAR_PLAN,
                'amount_xp' => (int) $awards['bar_plan'],
                'description' => 'План выручки бара выполнен',
            ];
        }

        return $lines;
    }

    private function isLate(Shift $shift, Admin $admin): bool
    {
        if (! $shift->started_at) {
            return false;
        }

        $started = $shift->started_at instanceof Carbon ? $shift->started_at : Carbon::parse($shift->started_at);
        $grace = (int) config('staff_edo.absence_grace_minutes', 15);

        $booking = ShiftSlotBooking::query()
            ->where('admin_id', $admin->id)
            ->where('kind', ShiftSlotBooking::KIND_LEAD)
            ->where('status', ShiftSlotBooking::STATUS_BOOKED)
            ->whereHas('slot', function ($query) use ($started) {
                $query->where('starts_at', '<=', $started->copy()->addHours(6))
                    ->where('ends_at', '>=', $started->copy()->subHour());
            })
            ->with('slot')
            ->first();

        $slotStart = $booking?->slot?->starts_at;
        if (! $slotStart) {
            return false;
        }

        return $started->greaterThan($slotStart->copy()->addMinutes($grace));
    }

    private function hardwareGreen(Shift $shift, Admin $admin): bool
    {
        $started = $shift->started_at ?? $shift->ended_at;
        $ended = $shift->ended_at ?? now();

        $pcs = Computer::query()
            ->when($admin->club_id, fn ($query) => $query->where('club_id', $admin->club_id))
            ->where('status', '!=', 'maintenance')
            ->where(function ($query) {
                $query->where('maintenance', false)->orWhereNull('maintenance');
            })
            ->get(['power_state']);

        foreach ($pcs as $pc) {
            if ((string) $pc->power_state === 'error') {
                return false;
            }
        }

        if (! $started) {
            return true;
        }

        $types = config('staff_bonus.hardware_incident_types', []);

        return ! DB::table('incidents')
            ->whereIn('type', $types)
            ->whereBetween('created_at', [$started, $ended])
            ->exists();
    }

    private function barRevenue(Shift $shift): float
    {
        if (! $shift->started_at || ! $shift->ended_at || ! Schema::hasTable('orders')) {
            return 0.0;
        }

        return (float) Order::query()
            ->where('status', '!=', Order::STATUS_CANCELLED)
            ->whereBetween('created_at', [$shift->started_at, $shift->ended_at])
            ->sum('price');
    }

    private function settleMonthFor(int $adminId, Carbon $month, ?Admin $actor): bool
    {
        $period = $month->format('Y-m');

        return DB::transaction(function () use ($adminId, $month, $period, $actor) {
            $rows = StaffXpTransaction::query()
                ->where('admin_id', $adminId)
                ->where('period_key', $period)
                ->whereNull('settlement_id')
                ->lockForUpdate()
                ->get();

            if ($rows->isEmpty()) {
                return false;
            }

            $net = (int) $rows->sum('amount_xp');
            $rate = $this->rate();
            $admin = Admin::query()->find($adminId);
            $burned = $this->quarterIsBurned($adminId, $month);
            $paid = 0.0;
            $held = 0.0;
            $ledgerId = null;

            if ($net > 0 && ! $burned) {
                $split = $this->splitRub($net, $rate);
                $paid = $split['paid'];
                $held = $split['held'];
                if ($paid > 0) {
                    $ledger = $this->postBonusLedger(
                        $adminId,
                        $paid,
                        'Премия эффективности '.$period,
                        'bonus-'.$period,
                        $actor
                    );
                    $ledgerId = $ledger->id;
                }
                if ($held > 0) {
                    $reserve = $this->reserve($adminId, (int) $month->year, (int) ceil($month->month / 3));
                    $reserve->points = round((float) $reserve->points + $held, 2);
                    $reserve->save();
                }
            } elseif ($net < 0 && ! $burned) {
                StaffXpTransaction::query()->create([
                    'admin_id' => $adminId,
                    'club_id' => $admin?->club_id,
                    'amount_xp' => $net,
                    'action_type' => StaffXpTransaction::CARRY,
                    'description' => 'Перенос остатка баллов эффективности на следующий месяц',
                    'period_key' => $month->copy()->addMonth()->format('Y-m'),
                ]);
            }

            $settlement = StaffBonusSettlement::query()->create([
                'admin_id' => $adminId,
                'club_id' => $admin?->club_id,
                'period_type' => StaffBonusSettlement::MONTHLY,
                'period_label' => $period,
                'total_xp' => max(0, $net),
                'rate' => $rate,
                'calculated_rub' => round(max(0, $net) * $rate, 2),
                'paid_rub' => $paid,
                'held_rub' => $burned ? 0 : $held,
                'status' => $burned ? StaffBonusSettlement::BURNED : StaffBonusSettlement::APPROVED,
                'approved_by' => $actor?->id,
                'ledger_id' => $ledgerId,
                'paid_at' => $paid > 0 ? now() : null,
            ]);

            StaffXpTransaction::query()
                ->whereIn('id', $rows->pluck('id'))
                ->update(['settlement_id' => $settlement->id]);

            return true;
        });
    }

    private function releaseReserve(StaffQuarterReserve $reserve, ?Admin $actor): bool
    {
        $label = sprintf('%d-Q%d', $reserve->year, $reserve->quarter);
        $exists = StaffBonusSettlement::query()
            ->where('admin_id', $reserve->admin_id)
            ->where('period_type', StaffBonusSettlement::QUARTERLY)
            ->where('period_label', $label)
            ->exists();
        if ($exists) {
            return false;
        }

        return DB::transaction(function () use ($reserve, $actor, $label) {
            $locked = StaffQuarterReserve::query()->lockForUpdate()->find($reserve->id);
            if (! $locked) {
                return false;
            }

            $admin = Admin::query()->find($locked->admin_id);
            $amount = round((float) $locked->points, 2);
            $burned = $locked->burned_at !== null;
            $ledgerId = null;

            if (! $burned && $amount > 0) {
                $ledger = $this->postBonusLedger(
                    (int) $locked->admin_id,
                    $amount,
                    'Квартальный фонд надежности '.$label,
                    'bonus-'.$label,
                    $actor
                );
                $ledgerId = $ledger->id;
                $locked->points = 0;
                $locked->save();
            }

            StaffBonusSettlement::query()->create([
                'admin_id' => $locked->admin_id,
                'club_id' => $admin?->club_id,
                'period_type' => StaffBonusSettlement::QUARTERLY,
                'period_label' => $label,
                'total_xp' => 0,
                'rate' => $this->rate(),
                'calculated_rub' => $burned ? 0 : $amount,
                'paid_rub' => $burned ? 0 : $amount,
                'held_rub' => 0,
                'status' => $burned ? StaffBonusSettlement::BURNED : StaffBonusSettlement::APPROVED,
                'approved_by' => $actor?->id,
                'ledger_id' => $ledgerId,
                'paid_at' => (! $burned && $amount > 0) ? now() : null,
            ]);

            return true;
        });
    }

    private function postBonusLedger(int $adminId, float $amount, string $reason, string $periodKey, ?Admin $actor): StaffLedger
    {
        return StaffLedger::query()->firstOrCreate(
            [
                'admin_id' => $adminId,
                'type' => StaffLedger::TYPE_ACCRUAL,
                'period_key' => $periodKey,
                'shift_id' => null,
            ],
            [
                'amount' => round($amount, 2),
                'reason' => $reason,
                'created_by' => $actor?->id,
            ]
        );
    }

    private function offsetUnsettledQuarter(int $adminId, int $year, int $quarter, Carbon $at): void
    {
        if (! Schema::hasTable('staff_xp_transactions')) {
            return;
        }

        $months = [];
        $start = ($quarter - 1) * 3 + 1;
        for ($m = $start; $m < $start + 3; $m++) {
            $months[] = sprintf('%04d-%02d', $year, $m);
        }

        $net = (int) StaffXpTransaction::query()
            ->where('admin_id', $adminId)
            ->whereNull('settlement_id')
            ->whereIn('period_key', $months)
            ->sum('amount_xp');

        if ($net === 0) {
            return;
        }

        $admin = Admin::query()->find($adminId);
        StaffXpTransaction::query()->create([
            'admin_id' => $adminId,
            'club_id' => $admin?->club_id,
            'amount_xp' => -$net,
            'action_type' => StaffXpTransaction::POOL_RESET,
            'description' => 'Обнуление баллов эффективности за расчётный период по ЛНА',
            'period_key' => $at->format('Y-m'),
        ]);
    }

    private function quarterIsBurned(int $adminId, Carbon $at): bool
    {
        if (! Schema::hasColumn('staff_quarter_reserves', 'burned_at')) {
            return false;
        }

        return StaffQuarterReserve::query()
            ->where('admin_id', $adminId)
            ->where('year', (int) $at->year)
            ->where('quarter', (int) ceil($at->month / 3))
            ->whereNotNull('burned_at')
            ->exists();
    }

    private function reserve(int $adminId, int $year, int $quarter): StaffQuarterReserve
    {
        return StaffQuarterReserve::query()->firstOrCreate(
            ['admin_id' => $adminId, 'year' => $year, 'quarter' => $quarter],
            ['points' => 0]
        );
    }

    private function openXp(int $adminId, string $period): int
    {
        return (int) StaffXpTransaction::query()
            ->where('admin_id', $adminId)
            ->where('period_key', $period)
            ->whereNull('settlement_id')
            ->sum('amount_xp');
    }

    /**
     * @return array{paid: float, held: float}
     */
    private function splitRub(int $xp, float $rate): array
    {
        $rub = round($xp * $rate, 2);
        $share = (float) config('staff_bonus.monthly_share', 0.70);
        $paid = round($rub * $share, 2);

        return [
            'paid' => $paid,
            'held' => round($rub - $paid, 2),
        ];
    }

    private function daysUntilQuarterOpens(int $year, int $quarter): int
    {
        $open = Carbon::create($year, $quarter * 3, 1)->endOfMonth()->addDay()->startOfDay();
        $today = now()->startOfDay();
        if ($today->greaterThanOrEqualTo($open)) {
            return 0;
        }

        return (int) $today->diffInDays($open);
    }

    private function lastCompletedQuarterLabel(Carbon $now): string
    {
        $cursor = $now->copy()->startOfMonth()->subMonth();
        while ($cursor->month % 3 !== 0) {
            $cursor->subMonth();
        }

        return sprintf('%d-Q%d', $cursor->year, (int) ceil($cursor->month / 3));
    }

    private function settingsRow(): StaffBonusSetting
    {
        $row = StaffBonusSetting::query()->first();
        if ($row) {
            return $row;
        }

        return StaffBonusSetting::query()->create([
            'xp_to_rub_rate' => (float) config('staff_bonus.xp_to_rub_rate', 10),
            'bar_target_rub' => (float) config('staff_bonus.bar_target_rub', 15000),
        ]);
    }

    private function rate(): float
    {
        if (! Schema::hasTable('staff_bonus_settings')) {
            return (float) config('staff_bonus.xp_to_rub_rate', 10);
        }

        return (float) $this->settingsRow()->xp_to_rub_rate;
    }

    private function barTarget(): float
    {
        if (! Schema::hasTable('staff_bonus_settings')) {
            return (float) config('staff_bonus.bar_target_rub', 15000);
        }

        return (float) $this->settingsRow()->bar_target_rub;
    }
}
