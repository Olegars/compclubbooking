<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Computer;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Пересадка активной сессии на другой ПК (самообслуживание).
 * Дороже → доплата с баланса или укорочение времени; дешевле → без возврата.
 */
class BookingSeatTransferService
{
    public function __construct(
        private readonly TariffService $tariffs,
        private readonly GameBookingService $bookings,
        private readonly ComputerStatusService $statuses,
        private readonly MapPresentationService $mapPresentation,
    ) {
    }

    /**
     * @return list<array{id:int,name:string,zone:string|null,hourly_rate:float}>
     */
    public function freeTargets(Booking $booking): array
    {
        return array_values(array_map(
            fn (array $row) => [
                'id' => $row['id'],
                'name' => $row['name'],
                'zone' => $row['zone'],
                'hourly_rate' => $row['hourly_rate'],
            ],
            $this->candidateRows($booking)['free']
        ));
    }

    /**
     * Карта клуба + занятость для модалки пересадки (ЛК).
     *
     * @return array{
     *   map_config: mixed,
     *   computers: list<array<string,mixed>>,
     *   occupied_ids: list<string>,
     *   selectable_ids: list<string>,
     *   from_computer_id: int,
     *   targets: list<array{id:int,name:string,zone:string|null,hourly_rate:float}>
     * }
     */
    public function mapForTransfer(Booking $booking): array
    {
        $pack = $this->candidateRows($booking);
        $from = Computer::query()->find((int) $booking->computer_id);
        $clubId = (int) ($from?->club_id ?? 0);

        $club = $clubId
            ? \App\Models\Club::query()->find($clubId)
            : null;
        $mapConfig = $club?->map_config;
        if (is_string($mapConfig)) {
            $mapConfig = json_decode($mapConfig, true) ?: [];
        }
        if (! is_array($mapConfig)) {
            $mapConfig = [];
        }
        $mapConfig = $this->mapPresentation->decorate($mapConfig, $clubId);

        $computers = Computer::query()
            ->where('club_id', $clubId)
            ->get(['id', 'name', 'x', 'y', 'kind', 'space_id', 'status'])
            ->map(fn (Computer $pc) => [
                'id' => (int) $pc->id,
                'name' => (string) $pc->name,
                'x' => (float) $pc->x,
                'y' => (float) $pc->y,
                'kind' => $pc->kind,
            ])
            ->values()
            ->all();

        $selectable = array_map(fn (array $t) => (string) $t['id'], $pack['free']);
        $fromId = (string) ($from?->id ?? 0);
        $allIds = array_map(fn ($c) => (string) $c['id'], $computers);
        // Текущий ПК не в occupied: на карте подсвечивается selectedIds; клик игнорируется на фронте.
        $occupied = array_values(array_diff($allIds, $selectable, $fromId !== '0' ? [$fromId] : []));

        return [
            'map_config' => $mapConfig,
            'computers' => $computers,
            'occupied_ids' => $occupied,
            'selectable_ids' => $selectable,
            'from_computer_id' => (int) ($from?->id ?? 0),
            'targets' => array_values(array_map(
                fn (array $row) => [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'zone' => $row['zone'],
                    'hourly_rate' => $row['hourly_rate'],
                ],
                $pack['free']
            )),
        ];
    }

    /**
     * @return array{free: list<array{id:int,name:string,zone:string|null,hourly_rate:float}>, from:?Computer}
     */
    private function candidateRows(Booking $booking): array
    {
        $from = Computer::query()->with('space')->find((int) $booking->computer_id);
        if (! $from) {
            return ['free' => [], 'from' => null];
        }

        $clubId = (int) $from->club_id;
        $endsAt = $this->endsAt($booking);
        $now = CarbonImmutable::now(config('app.timezone'));

        $candidates = Computer::query()
            ->with('space.zone')
            ->where('club_id', $clubId)
            ->where('id', '!=', (int) $from->id)
            ->where(function ($q) {
                $q->whereNull('kind')->orWhere('kind', Computer::KIND_PC);
            })
            ->orderBy('name')
            ->get();

        $occupied = $this->bookings->occupiedComputerIds(
            $candidates->pluck('id')->map(fn ($id) => (int) $id)->all(),
            $now,
            $endsAt,
            [(int) $booking->id]
        );
        $occupiedMap = array_fill_keys($occupied, true);

        $free = [];
        foreach ($candidates as $pc) {
            if (isset($occupiedMap[(int) $pc->id])) {
                continue;
            }
            if ($pc->isTvBoothSeat()) {
                continue;
            }
            $rate = $this->hourlyRateForComputer($pc, $clubId, $now);
            $free[] = [
                'id' => (int) $pc->id,
                'name' => (string) $pc->name,
                'zone' => $pc->space?->zone?->name,
                'hourly_rate' => $rate,
            ];
        }

        return ['free' => $free, 'from' => $from];
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(Booking $booking, int $targetComputerId): array
    {
        return $this->plan($booking, $targetComputerId, apply: false);
    }

    /**
     * @return array<string, mixed>
     */
    public function transfer(Booking $booking, int $targetComputerId, User $actor): array
    {
        return DB::transaction(function () use ($booking, $targetComputerId, $actor) {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            if ($booking->status !== 'active') {
                throw new RuntimeException('Пересадка только для активной сессии');
            }
            if ((int) $booking->user_id !== (int) $actor->id) {
                throw new RuntimeException('Сессия принадлежит другому игроку');
            }

            $plan = $this->plan($booking, $targetComputerId, apply: true);

            return $plan;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function plan(Booking $booking, int $targetComputerId, bool $apply): array
    {
        if ($booking->status !== 'active') {
            throw new RuntimeException('Пересадка только для активной сессии');
        }

        $from = Computer::query()->with('space.zone')->find((int) $booking->computer_id);
        $to = Computer::query()->with('space.zone')->find($targetComputerId);

        if (! $from || ! $to) {
            throw new RuntimeException('ПК не найден');
        }
        if ((int) $from->club_id !== (int) $to->club_id) {
            throw new RuntimeException('ПК из другого клуба');
        }
        if ((int) $from->id === (int) $to->id) {
            throw new RuntimeException('Уже сидите за этим ПК');
        }
        if ($to->isTvBoothSeat()) {
            throw new RuntimeException('На ТВ/PS пересадка через этот сценарий недоступна');
        }

        $tz = config('app.timezone');
        $now = CarbonImmutable::now($tz);
        $endsAt = $this->endsAt($booking);
        if ($endsAt <= $now) {
            throw new RuntimeException('Сессия уже истекла');
        }

        $occupied = $this->bookings->occupiedComputerIds(
            [(int) $to->id],
            $now,
            $endsAt,
            [(int) $booking->id]
        );
        if ($occupied !== []) {
            throw new RuntimeException('Целевой ПК занят');
        }

        $clubId = (int) $from->club_id;
        $rateFrom = $this->hourlyRateForComputer($from, $clubId, $now);
        $rateTo = $this->hourlyRateForComputer($to, $clubId, $now);

        $remainingHours = max(0.01, $now->diffInSeconds($endsAt) / 3600);
        $prepaidValue = round($rateFrom * $remainingHours, 2);
        $deltaPerHour = round($rateTo - $rateFrom, 2);
        $extraIfKeepTime = round(max(0, $deltaPerHour * $remainingHours), 2);

        $user = User::query()->findOrFail($booking->user_id);
        $balance = (float) $user->availableBalance();

        $charge = 0.0;
        $newEndsAt = $endsAt;
        $action = 'same';
        $warning = 'Тариф тот же — можно пересесть без доплаты.';

        if ($deltaPerHour > 0.009) {
            if ($balance + 0.009 >= $extraIfKeepTime) {
                $charge = $extraIfKeepTime;
                $action = 'charge';
                $warning = sprintf(
                    'ПК дороже (+%.0f ₽/ч). Будет списано %.2f ₽ с баланса, время сессии сохранится.',
                    $deltaPerHour,
                    $charge
                );
            } else {
                $affordable = $prepaidValue + max(0, $balance);
                $hNew = $rateTo > 0 ? ($affordable / $rateTo) : $remainingHours;
                $hNew = max(0.05, min($remainingHours, $hNew));
                $newEndsAt = $now->addSeconds((int) round($hNew * 3600));
                $charge = round(max(0, min($balance, ($rateTo * $hNew) - $prepaidValue)), 2);
                $action = 'shorten';
                $minsLost = max(0, (int) round(($remainingHours - $hNew) * 60));
                $warning = sprintf(
                    'ПК дороже, на балансе не хватает на полное время. Сессия сократится примерно на %d мин%s.',
                    $minsLost,
                    $charge > 0 ? sprintf(', дополнительно спишется %.2f ₽', $charge) : ''
                );
            }
        } elseif ($deltaPerHour < -0.009) {
            $action = 'cheaper';
            $warning = 'ПК дешевле — возврата денег нет, время не увеличивается.';
        }

        $result = [
            'from' => [
                'id' => (int) $from->id,
                'name' => $from->name,
                'zone' => $from->space?->zone?->name,
                'hourly_rate' => $rateFrom,
            ],
            'to' => [
                'id' => (int) $to->id,
                'name' => $to->name,
                'zone' => $to->space?->zone?->name,
                'hourly_rate' => $rateTo,
            ],
            'remaining_hours' => round($remainingHours, 3),
            'delta_per_hour' => $deltaPerHour,
            'charge' => $charge,
            'action' => $action,
            'warning' => $warning,
            'ends_at' => $newEndsAt->toIso8601String(),
            'ends_at_before' => $endsAt->toIso8601String(),
            'balance' => $balance,
            'balance_after' => round($balance - $charge, 2),
        ];

        if (! $apply) {
            return $result;
        }

        if ($charge > 0) {
            $user->syncBalanceToWallet();
            $wallet = \App\Models\Wallet::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();
            if (! $wallet || (float) $user->fresh()->availableBalance() + 0.009 < $charge) {
                throw new RuntimeException('Недостаточно средств для доплаты');
            }
            $wallet->debitSpendable($charge);
            Transaction::create([
                'user_id' => $user->id,
                'amount' => -$charge,
                'type' => 'purchase',
                'source' => 'seat_transfer',
                'description' => sprintf(
                    'Пересадка %s → %s (доплата тарифа)',
                    $from->name,
                    $to->name
                ),
                'payload' => [
                    'booking_id' => $booking->id,
                    'from_computer_id' => $from->id,
                    'to_computer_id' => $to->id,
                ],
            ]);
        }

        $oldId = (int) $from->id;
        $booking->update([
            'computer_id' => (int) $to->id,
            'pc_ids' => [(string) $to->id],
            'ends_at' => $newEndsAt,
        ]);

        // duration / legacy window — подтянуть под новый ends_at
        if ($booking->actual_started_at || $booking->starts_at) {
            $start = $booking->actual_started_at
                ? CarbonImmutable::parse($booking->actual_started_at, $tz)
                : CarbonImmutable::parse($booking->starts_at, $tz);
            $mins = max(1, (int) ceil($start->diffInSeconds($newEndsAt) / 60));
            $booking->update(['duration' => round($mins / 60, 2)]);
        }

        \App\Models\GameAccount::query()
            ->where('current_pc_id', $oldId)
            ->update(['status' => 'free', 'current_pc_id' => null]);

        $this->statuses->syncFor($oldId);
        $this->statuses->syncFor((int) $to->id);

        $result['booking_id'] = (int) $booking->id;
        $result['applied'] = true;
        $result['balance_after'] = (float) $user->fresh()->availableBalance();

        return $result;
    }

    private function endsAt(Booking $booking): CarbonImmutable
    {
        $tz = config('app.timezone');
        if ($booking->ends_at) {
            return CarbonImmutable::parse($booking->ends_at)->timezone($tz);
        }

        return CarbonImmutable::now($tz);
    }

    private function hourlyRateForComputer(Computer $pc, int $clubId, CarbonImmutable $at): float
    {
        $space = $pc->relationLoaded('space') ? $pc->space : Space::query()->find($pc->space_id);
        $zoneId = $space?->zone_id ? (int) $space->zone_id : null;
        $base = $this->tariffs->hourlyRateRub($clubId, $zoneId, $at);
        $surcharge = $space ? (float) $space->effectiveAlwaysSurchargePerHour($clubId) : 0.0;

        return round($base + max(0, $surcharge), 2);
    }
}
