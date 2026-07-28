<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingGame;
use App\Models\BookingGroup;
use App\Models\Club;
use App\Models\ClubGame;
use App\Models\Computer;
use App\Models\ComputerGame;
use App\Models\GameAccount;
use App\Models\GameAccountReservation;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GameBookingService
{
    public function __construct(
        private readonly TariffService $tariffs,
        private readonly MapZoneResolver $zones,
    ) {
    }

    public function availability(
        int $clubId,
        array $computerIds,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt
    ): array {
        $this->validatePeriod($startsAt, $endsAt);
        $computers = $this->validateComputers($clubId, $computerIds);
        $quantity = $computers->count();

        return ClubGame::query()
            ->with('game')
            ->where('club_id', $clubId)
            ->where('is_enabled', true)
            ->paid()
            ->orderBy('id')
            ->get()
            ->map(function (ClubGame $offer) use ($computerIds, $quantity, $startsAt, $endsAt) {
                $installedCount = ComputerGame::query()
                    ->whereIn('computer_id', $computerIds)
                    ->where('game_id', $offer->game_id)
                    ->where('is_installed', true)
                    ->distinct()
                    ->count('computer_id');

                $accountCount = $this->availableAccountsQuery(
                    $offer->game_id,
                    $offer->club_id,
                    $startsAt,
                    $endsAt
                )->count();

                $isInstalled = $installedCount === $quantity;
                $hasAccounts = $accountCount >= $quantity;

                return [
                    'id' => $offer->game_id,
                    'club_game_id' => $offer->id,
                    'title' => $offer->game->title,
                    'platform' => $offer->game->platform,
                    'poster' => $offer->game->poster,
                    'is_paid' => true,
                    'billing_mode' => $offer->billing_mode,
                    'unit_price_minor' => $offer->unit_price_minor,
                    'billing_unit_minutes' => $offer->billing_unit_minutes,
                    'currency' => $offer->currency,
                    'available_accounts' => $accountCount,
                    'required_accounts' => $quantity,
                    'is_installed' => $isInstalled,
                    // Без свободных аккаунтов игра остаётся в списке, но не выбирается.
                    'is_available' => $isInstalled && $hasAccounts,
                ];
            })
            ->values()
            ->all();
    }

    public function quote(
        int $clubId,
        array $computerIds,
        array $gameIds,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        string $mode = 'hourly',
        ?int $tariffId = null,
    ): array {
        $this->validatePeriod($startsAt, $endsAt);
        $computers = $this->validateComputers($clubId, $computerIds);
        $this->assertBoothSelectionValid($computers);
        $this->assertComputersAvailable($computerIds, $startsAt, $endsAt);
        $durationMinutes = $startsAt->diffInMinutes($endsAt);
        $durationHours = $durationMinutes / 60;
        $club = Club::query()->findOrFail($clubId);
        $seatQuote = $this->seatTariffQuote($club, $computers, $durationHours, $mode, $tariffId);
        $baseComputerMinor = (int) $seatQuote['total_minor'];
        $ps5SurchargeMinor = $this->ps5SurchargeMinor($durationHours, $computers);
        $computerTotalMinor = $baseComputerMinor + $ps5SurchargeMinor;
        $availability = collect($this->availability($clubId, $computerIds, $startsAt, $endsAt))
            ->keyBy('id');

        $lines = collect(array_values(array_unique(array_map('intval', $gameIds))));
        if ($lines->count() > 1) {
            throw ValidationException::withMessages([
                'game_ids' => 'Можно забронировать только одну платную игру.',
            ]);
        }

        $lines = $lines
            ->map(function (int $gameId) use ($availability, $durationMinutes, $computers, $clubId) {
                $offer = ClubGame::query()
                    ->where('club_id', $clubId)
                    ->where('game_id', $gameId)
                    ->first();

                if ($offer && ! $offer->is_paid) {
                    $offer->loadMissing('game');
                    throw ValidationException::withMessages([
                        'game_ids' => "Игра «{$offer->game?->title}» бесплатная и бронируется только через shell.",
                    ]);
                }

                $game = $availability->get($gameId);
                if (!$game || !$game['is_available']) {
                    throw ValidationException::withMessages([
                        'game_ids' => "Игра #{$gameId} недоступна на всех выбранных местах в это время.",
                    ]);
                }

                $lineTotal = $this->gameLinePriceMinor(
                    $game['billing_mode'],
                    $game['unit_price_minor'],
                    $game['billing_unit_minutes'],
                    $durationMinutes,
                    $computers->count()
                );

                return array_merge($game, [
                    'quantity' => $computers->count(),
                    'line_total_minor' => $lineTotal,
                ]);
            })
            ->values();

        $gamesTotalMinor = (int) $lines->sum('line_total_minor');

        return [
            'currency' => 'RUB',
            'starts_at' => $startsAt->toIso8601String(),
            'ends_at' => $endsAt->toIso8601String(),
            'duration_minutes' => $durationMinutes,
            'mode' => $mode,
            'tariff_id' => $tariffId,
            'tariff' => $seatQuote,
            'computers_total_minor' => $computerTotalMinor,
            'computers_base_minor' => $baseComputerMinor,
            'ps5_surcharge_minor' => $ps5SurchargeMinor,
            'games_total_minor' => $gamesTotalMinor,
            'total_minor' => $computerTotalMinor + $gamesTotalMinor,
            'total_price' => ($computerTotalMinor + $gamesTotalMinor) / 100,
            'games' => $lines->all(),
        ];
    }

    public function reserve(
        User $user,
        int $clubId,
        array $computerIds,
        array $gameIds,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        string $mode = 'hourly',
        ?int $tariffId = null,
    ): BookingGroup {
        return DB::transaction(function () use (
            $user,
            $clubId,
            $computerIds,
            $gameIds,
            $startsAt,
            $endsAt,
            $mode,
            $tariffId
        ) {
            // Снимаем просроченные confirmed/active, иначе EXCLUDE-ограничение
            // PostgreSQL продолжает считать слот занятым.
            $this->closeExpiredBookings();

            $quote = $this->quote($clubId, $computerIds, $gameIds, $startsAt, $endsAt, $mode, $tariffId);

            $user->syncBalanceToWallet();
            $wallet = Wallet::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $amount = $quote['total_minor'] / 100;
            if ($wallet->depositAmount() < $amount) {
                throw ValidationException::withMessages([
                    'balance' => 'Недостаточно средств на балансе.',
                ]);
            }

            $localStart = $startsAt->timezone(config('app.timezone'));
            $localEnd = $endsAt->timezone(config('app.timezone'));

            $group = BookingGroup::create([
                'user_id' => $user->id,
                'club_id' => $clubId,
                'starts_at' => $localStart,
                'ends_at' => $localEnd,
                'status' => 'pending_payment',
                'payment_status' => 'unpaid',
                'currency' => $quote['currency'],
                'computers_total_minor' => $quote['computers_total_minor'],
                'games_total_minor' => $quote['games_total_minor'],
                'total_minor' => $quote['total_minor'],
                'pricing_snapshot' => $quote,
            ]);

            $durationHours = $quote['duration_minutes'] / 60;
            $seatPrices = $this->seatPriceMinorsFromQuote($quote, $computerIds);
            $bookings = collect();

            foreach (array_values($computerIds) as $computerId) {
                $computerPriceMinor = $seatPrices[(int) $computerId] ?? 0;
                $bookings->push(Booking::create([
                    'booking_group_id' => $group->id,
                    'user_id' => $user->id,
                    'computer_id' => $computerId,
                    'pc_ids' => [(int) $computerId],
                    'date' => $localStart->toDateString(),
                    'start_time' => $localStart->hour + ($localStart->minute / 60),
                    'duration' => $durationHours,
                    'price' => (int) round($computerPriceMinor / 100),
                    'price_minor' => $computerPriceMinor,
                    'starts_at' => $localStart,
                    'ends_at' => $localEnd,
                    'status' => 'confirmed',
                    'pin_code' => (string) random_int(1000, 9999),
                ]));
            }

            foreach ($quote['games'] as $line) {
                $bookingGame = BookingGame::create([
                    'booking_group_id' => $group->id,
                    'club_game_id' => $line['club_game_id'],
                    'quantity' => $line['quantity'],
                    'game_title' => $line['title'],
                    'platform' => $line['platform'],
                    'billing_mode' => $line['billing_mode'],
                    'unit_price_minor' => $line['unit_price_minor'],
                    'billing_unit_minutes' => $line['billing_unit_minutes'],
                    'line_total_minor' => $line['line_total_minor'],
                ]);

                $accounts = $this->availableAccountsQuery(
                    $line['id'],
                    $clubId,
                    $startsAt,
                    $endsAt
                )
                    ->orderBy('id')
                    ->lock('for update skip locked')
                    ->limit($bookings->count())
                    ->get();

                if ($accounts->count() !== $bookings->count()) {
                    throw ValidationException::withMessages([
                        'game_ids' => "Для игры {$line['title']} уже не хватает свободных аккаунтов.",
                    ]);
                }

                foreach ($bookings->values() as $index => $booking) {
                    GameAccountReservation::create([
                        'booking_game_id' => $bookingGame->id,
                        'booking_id' => $booking->id,
                        'game_account_id' => $accounts[$index]->id,
                        'starts_at' => $localStart,
                        'ends_at' => $localEnd,
                        'status' => 'confirmed',
                    ]);
                }
            }

            $wallet->debitSpendable($amount);

            $gameTitles = collect($quote['games'] ?? [])
                ->pluck('title')
                ->filter()
                ->unique()
                ->values();
            $description = $gameTitles->isNotEmpty()
                ? "Бронь #{$group->id}: ".$gameTitles->implode(', ')
                : "Бронь #{$group->id}: компьютеры";

            Transaction::create([
                'user_id' => $user->id,
                'booking_group_id' => $group->id,
                'amount' => -$amount,
                'type' => 'booking',
                'source' => 'balance',
                'description' => $description,
                'payload' => $quote,
                'idempotency_key' => "booking-group:{$group->id}:charge",
            ]);

            $group->update([
                'status' => 'confirmed',
                'payment_status' => 'paid',
                'paid_total_minor' => $quote['total_minor'],
                'paid_at' => now(),
            ]);

            return $group->fresh(['bookings', 'games.reservations']);
        }, 3);
    }

    public function cancel(User $user, BookingGroup $group): BookingGroup
    {
        return DB::transaction(function () use ($user, $group) {
            $group = BookingGroup::query()->lockForUpdate()->findOrFail($group->id);
            if ($group->user_id !== $user->id) {
                abort(403);
            }

            if ($group->status === 'cancelled') {
                return $group;
            }
            if (in_array($group->status, ['active', 'completed'], true)) {
                throw ValidationException::withMessages([
                    'booking' => 'Активную или завершённую бронь отменить нельзя.',
                ]);
            }

            $refundMinor = max(0, $group->paid_total_minor - $group->refunded_total_minor);
            if ($refundMinor > 0) {
                $wallet = Wallet::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
                $wallet->creditSpendable($refundMinor / 100);

                Transaction::firstOrCreate(
                    ['idempotency_key' => "booking-group:{$group->id}:refund"],
                    [
                        'user_id' => $user->id,
                        'booking_group_id' => $group->id,
                        'amount' => $refundMinor / 100,
                        'type' => 'refund',
                        'source' => 'balance',
                        'description' => "Возврат за бронь #{$group->id}",
                        'payload' => ['refund_minor' => $refundMinor, 'currency' => $group->currency],
                    ]
                );
            }

            $group->bookings()->update(['status' => 'cancelled']);
            GameAccountReservation::query()
                ->whereHas('bookingGame', fn ($query) => $query->where('booking_group_id', $group->id))
                ->update(['status' => 'cancelled', 'released_at' => now()]);

            $group->update([
                'status' => 'cancelled',
                'payment_status' => $refundMinor > 0 ? 'refunded' : $group->payment_status,
                'refunded_total_minor' => $group->refunded_total_minor + $refundMinor,
                'cancelled_at' => now(),
            ]);

            return $group->fresh();
        }, 3);
    }

    private function validateComputers(int $clubId, array $computerIds): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $computerIds)));
        if ($ids === []) {
            throw ValidationException::withMessages(['pc_ids' => 'Выберите хотя бы один компьютер.']);
        }

        $computers = Computer::query()
            ->where('club_id', $clubId)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();

        if ($computers->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'pc_ids' => 'Один или несколько компьютеров не принадлежат выбранному клубу.',
            ]);
        }

        return $computers;
    }

    private function validatePeriod(CarbonImmutable $startsAt, CarbonImmutable $endsAt): void
    {
        if ($endsAt <= $startsAt) {
            throw ValidationException::withMessages(['ends_at' => 'Окончание должно быть позже начала.']);
        }
        if ($startsAt->isPast()) {
            throw ValidationException::withMessages(['starts_at' => 'Нельзя создать бронь в прошлом.']);
        }
    }

    private function availableAccountsQuery(
        int $gameId,
        int $clubId,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt
    ) {
        return GameAccount::query()
            ->where('game_id', $gameId)
            ->where('is_enabled', true)
            ->where(function ($query) use ($startsAt) {
                $query->where('status', 'free')
                    ->orWhere(function ($inUse) use ($startsAt) {
                        $inUse->where('status', 'in_use')
                            ->whereExists(function ($booking) use ($startsAt) {
                                $booking->selectRaw('1')
                                    ->from('bookings')
                                    ->whereColumn('bookings.computer_id', 'game_accounts.current_pc_id')
                                    ->where('bookings.status', 'active')
                                    ->whereNotNull('bookings.ends_at')
                                    ->where('bookings.ends_at', '<=', $startsAt);
                            });
                    });
            })
            ->where(function ($query) use ($clubId) {
                $query->where('club_id', $clubId)->orWhereNull('club_id');
            })
            ->whereDoesntHave('reservations', function ($query) use ($startsAt, $endsAt) {
                $startsAtUtc = $startsAt->utc()->toIso8601String();
                $endsAtUtc = $endsAt->utc()->toIso8601String();
                $query->whereIn('status', ['held', 'confirmed', 'active'])
                    ->whereRaw('starts_at < ?::timestamptz', [$endsAtUtc])
                    ->whereRaw('ends_at > ?::timestamptz', [$startsAtUtc]);
            });
    }

    /**
     * @param  array<int, int>  $computerIds
     * @return array<int, int>
     */
    public function occupiedComputerIds(
        array $computerIds,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt
    ): array {
        $ids = array_values(array_unique(array_map('intval', $computerIds)));
        if ($ids === []) {
            return [];
        }

        $relatedIds = $this->expandBoothSiblings($ids);
        $direct = $this->directOccupiedComputerIds($relatedIds, $startsAt, $endsAt);

        return $this->expandBoothSiblings($direct);
    }

    /**
     * @param  array<int, int>  $computerIds
     * @return array<int, int>
     */
    public function expandBoothSiblings(array $computerIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $computerIds)));
        if ($ids === []) {
            return [];
        }

        $boothIds = Computer::query()
            ->whereIn('id', $ids)
            ->whereNotNull('booth_id')
            ->where('booth_id', '!=', '')
            ->pluck('booth_id')
            ->unique()
            ->filter()
            ->values()
            ->all();

        if ($boothIds === []) {
            return $ids;
        }

        $siblingIds = Computer::query()
            ->whereIn('booth_id', $boothIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_unique([...$ids, ...$siblingIds]));
    }

    /**
     * @param  array<int, int>  $computerIds
     * @return array<int, int>
     */
    private function directOccupiedComputerIds(
        array $computerIds,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt
    ): array {
        $ids = array_values(array_unique(array_map('intval', $computerIds)));
        if ($ids === []) {
            return [];
        }

        if ($endsAt <= $startsAt) {
            return $ids;
        }

        $startsAtUtc = $startsAt->utc()->toIso8601String();
        $endsAtUtc = $endsAt->utc()->toIso8601String();
        $local = $startsAt->timezone(config('app.timezone'));
        $startHour = $local->hour + ($local->minute / 60);
        $endHour = $startHour + ($startsAt->diffInMinutes($endsAt) / 60);

        return Booking::query()
            ->whereIn('computer_id', $ids)
            ->whereIn('status', ['confirmed', 'active', 'paid'])
            ->where(function ($query) use ($startsAtUtc, $endsAtUtc, $local, $startHour, $endHour) {
                $query
                    ->where(function ($modern) use ($startsAtUtc, $endsAtUtc) {
                        $modern->whereNotNull('starts_at')
                            ->whereRaw('starts_at < ?::timestamptz', [$endsAtUtc])
                            ->whereRaw('ends_at > ?::timestamptz', [$startsAtUtc]);
                    })
                    ->orWhere(function ($legacy) use ($local, $startHour, $endHour) {
                        $legacy->whereNull('starts_at')
                            ->whereDate('date', $local->toDateString())
                            ->whereRaw('start_time < ? AND (start_time + duration) > ?', [$endHour, $startHour]);
                    });
            })
            ->pluck('computer_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function assertBoothSelectionValid(Collection $computers): void
    {
        $duplicates = $computers
            ->filter(fn (Computer $pc) => filled($pc->booth_id))
            ->groupBy('booth_id')
            ->filter(fn (Collection $group) => $group->count() > 1);

        if ($duplicates->isNotEmpty()) {
            throw ValidationException::withMessages([
                'pc_ids' => 'Нельзя выбрать ТВ и PS одной кабины одновременно. Выберите один маркер.',
            ]);
        }
    }

    private function assertComputersAvailable(
        array $computerIds,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt
    ): void {
        $related = $this->expandBoothSiblings($computerIds);
        if ($this->occupiedComputerIds($related, $startsAt, $endsAt) !== []) {
            throw ValidationException::withMessages([
                'pc_ids' => 'Один из выбранных компьютеров или кабина ТВ/PS уже заняты на это время.',
            ]);
        }
    }

    private function seatTariffQuote(
        Club $club,
        Collection $computers,
        float $hours,
        string $mode,
        ?int $tariffId
    ): array {
        $mode = $mode === 'packages' ? 'packages' : 'hourly';
        $zones = $this->zones->resolveForComputers($club, $computers);

        return $this->tariffs->quoteSeats($zones, $hours, $mode, $tariffId);
    }

    /**
     * @param  array<string, mixed>  $quote
     * @param  array<int, int>  $computerIds
     * @return array<int, int>
     */
    private function seatPriceMinorsFromQuote(array $quote, array $computerIds): array
    {
        $perSeat = collect($quote['tariff']['per_seat'] ?? [])->keyBy('computer_id');
        $prices = [];
        $allocated = 0;

        foreach ($computerIds as $computerId) {
            $id = (int) $computerId;
            $base = (int) round(((float) ($perSeat->get($id)['total_rub'] ?? 0)) * 100);
            $prices[$id] = $base;
            $allocated += $base;
        }

        // PS5 surcharge — equally across PS5 seats if present in snapshot; else remainder on first seats.
        $ps5Minor = (int) ($quote['ps5_surcharge_minor'] ?? 0);
        if ($ps5Minor > 0) {
            $ps5Ids = Computer::query()
                ->whereIn('id', $computerIds)
                ->where('kind', Computer::KIND_PS5)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $targets = $ps5Ids !== [] ? $ps5Ids : array_map('intval', $computerIds);
            $share = intdiv($ps5Minor, count($targets));
            $rem = $ps5Minor - ($share * count($targets));
            foreach (array_values($targets) as $index => $id) {
                $prices[$id] = ($prices[$id] ?? 0) + $share + ($index < $rem ? 1 : 0);
            }
            $allocated += $ps5Minor;
        }

        $expected = (int) ($quote['computers_total_minor'] ?? $allocated);
        $diff = $expected - array_sum($prices);
        if ($diff !== 0 && $computerIds !== []) {
            $first = (int) $computerIds[0];
            $prices[$first] = ($prices[$first] ?? 0) + $diff;
        }

        return $prices;
    }

    private function ps5SurchargeMinor(float $hours, Collection $computers): int
    {
        $ps5Count = $computers->where('kind', Computer::KIND_PS5)->count();
        if ($ps5Count === 0) {
            return 0;
        }

        $perHour = (float) config('booking.ps5_surcharge_per_hour', 100);

        return (int) round($perHour * $hours * $ps5Count * 100);
    }

    private function gameLinePriceMinor(
        string $mode,
        int $unitPriceMinor,
        int $unitMinutes,
        int $durationMinutes,
        int $quantity
    ): int {
        $units = (int) ceil($durationMinutes / max(1, $unitMinutes));

        return match ($mode) {
            'free' => 0,
            'per_seat_hour' => $unitPriceMinor * $units * $quantity,
            'per_seat_booking' => $unitPriceMinor * $quantity,
            'per_booking_hour' => $unitPriceMinor * $units,
            'fixed' => $unitPriceMinor,
            default => throw ValidationException::withMessages([
                'game_ids' => "Неизвестная модель тарификации игры: {$mode}.",
            ]),
        };
    }

    private function closeExpiredBookings(): void
    {
        $nowIso = now()->utc()->toIso8601String();

        $expiredIds = Booking::query()
            ->whereIn('status', ['confirmed', 'active'])
            ->whereNotNull('ends_at')
            ->whereRaw('ends_at <= ?::timestamptz', [$nowIso])
            ->pluck('id');

        if ($expiredIds->isEmpty()) {
            return;
        }

        Booking::query()
            ->whereIn('id', $expiredIds)
            ->update([
                'status' => 'completed',
                'actual_ended_at' => now(),
            ]);

        GameAccountReservation::query()
            ->whereIn('booking_id', $expiredIds)
            ->whereIn('status', ['held', 'confirmed', 'active'])
            ->update([
                'status' => 'completed',
                'released_at' => now(),
            ]);

        BookingGroup::query()
            ->whereIn('status', ['confirmed', 'active'])
            ->whereRaw('ends_at <= ?::timestamptz', [$nowIso])
            ->update(['status' => 'completed']);
    }
}
