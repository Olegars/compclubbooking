<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Computer;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Куда нести заказ: точка доставки для админ-очереди.
 *
 * - Терминал / шелл: ПК, с которого оформлен заказ.
 * - Сайт / приложение: ПК активной сессии пользователя.
 */
class OrderDeliveryTarget
{
    public static function labelForComputerId(?int $computerId): string
    {
        if (! $computerId || $computerId < 1) {
            return 'ПК неизвестен';
        }

        $computer = Computer::query()->find($computerId);
        if ($computer && filled($computer->name)) {
            return (string) $computer->name;
        }

        return 'ПК №'.$computerId;
    }

    /**
     * Все варианты pc_name, под которыми заказ мог быть записан для этого ПК
     * (новый формат = имя компьютера, старый = «ПК №{id}»).
     *
     * @return list<string>
     */
    public static function matchLabels(int $computerId): array
    {
        $labels = ['ПК №'.$computerId];
        $computer = Computer::query()->find($computerId);
        if ($computer && filled($computer->name)) {
            $labels[] = (string) $computer->name;
        }

        return array_values(array_unique($labels));
    }

    public static function activeBookingForUser(int $userId): ?Booking
    {
        $now = now();
        $nowIso = $now->utc()->toIso8601String();
        $today = $now->toDateString();
        $nowH = $now->hour + ($now->minute / 60);

        // status=active недостаточно: просроченные брони могут оставаться active,
        // пока не отработает reactor:update-statuses. Для доставки смотрим окно времени.
        return Booking::query()
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->where(function ($query) use ($nowIso, $today, $nowH) {
                $query->where(function ($modern) use ($nowIso) {
                    $modern->whereNotNull('ends_at')
                        ->whereRaw('ends_at > ?::timestamptz', [$nowIso]);
                })->orWhere(function ($legacy) use ($today, $nowH) {
                    $legacy->whereNull('ends_at')
                        ->where('date', $today)
                        ->where('start_time', '<=', $nowH)
                        ->whereRaw('(start_time + duration) > ?', [$nowH]);
                });
            })
            ->orderByDesc('id')
            ->first();
    }

    public static function computerIdFromBooking(Booking $booking): ?int
    {
        if (! empty($booking->computer_id)) {
            return (int) $booking->computer_id;
        }

        $pcIds = $booking->pc_ids;
        if (is_array($pcIds)) {
            foreach ($pcIds as $pcId) {
                $id = (int) $pcId;
                if ($id > 0) {
                    return $id;
                }
            }
        }

        return null;
    }

    /**
     * Активная сессия пользователя → label для orders.pc_name.
     * null, если сессии нет или ПК не определён.
     */
    public static function labelFromUserActiveSession(int $userId): ?string
    {
        $booking = self::activeBookingForUser($userId);
        if (! $booking) {
            return null;
        }

        $computerId = self::computerIdFromBooking($booking);
        if (! $computerId) {
            return null;
        }

        return self::labelForComputerId($computerId);
    }

    public static function labelFromBooking(Booking $booking): ?string
    {
        $computerId = self::computerIdFromBooking($booking);
        if (! $computerId) {
            return null;
        }

        return self::labelForComputerId($computerId);
    }

    /**
     * Ближайшая оплаченная бронь без живой сессии (ещё можно войти).
     */
    public static function upcomingBookingForUser(int $userId): ?Booking
    {
        $now = now();

        return Booking::query()
            ->where('user_id', $userId)
            ->whereIn('status', ['confirmed', 'paid'])
            ->whereNull('actual_started_at')
            ->where(function ($query) use ($now) {
                $query->where(function ($modern) use ($now) {
                    $modern->whereNotNull('ends_at')
                        ->where('ends_at', '>', $now);
                })->orWhere(function ($legacy) use ($now) {
                    $legacy->whereNull('ends_at')
                        ->where('date', '>=', $now->toDateString());
                });
            })
            ->orderBy('starts_at')
            ->orderBy('date')
            ->orderBy('start_time')
            ->first();
    }

    public static function activeBookingOnComputer(int $computerId): ?Booking
    {
        return self::bookingOnComputer($computerId, ['active']);
    }

    public static function upcomingBookingOnComputer(int $computerId): ?Booking
    {
        $now = now();

        return Booking::query()
            ->whereIn('status', ['confirmed', 'paid'])
            ->whereNull('actual_started_at')
            ->where(function ($query) use ($computerId) {
                $query->where('computer_id', $computerId)
                    ->orWhereJsonContains('pc_ids', (string) $computerId);
            })
            ->where(function ($query) use ($now) {
                $query->where(function ($modern) use ($now) {
                    $modern->whereNotNull('ends_at')
                        ->where('ends_at', '>', $now);
                })->orWhereNull('ends_at');
            })
            ->orderBy('starts_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  list<string>  $statuses
     */
    public static function bookingOnComputer(int $computerId, array $statuses): ?Booking
    {
        return Booking::query()
            ->whereIn('status', $statuses)
            ->where(function ($query) use ($computerId) {
                $query->where('computer_id', $computerId)
                    ->orWhereJsonContains('pc_ids', (string) $computerId);
            })
            ->orderByDesc('id')
            ->first();
    }

    public static function startsAtFromBooking(Booking $booking): ?CarbonInterface
    {
        if ($booking->starts_at) {
            return CarbonImmutable::parse($booking->starts_at);
        }

        if ($booking->date && $booking->start_time !== null) {
            $hours = (float) $booking->start_time;
            $h = (int) $hours;
            $m = (int) round(($hours - $h) * 60);

            $date = $booking->date instanceof \DateTimeInterface
                ? CarbonImmutable::instance($booking->date)->toDateString()
                : (string) $booking->date;

            return CarbonImmutable::parse(
                sprintf('%s %02d:%02d:00', $date, $h, $m),
                config('app.timezone')
            );
        }

        return null;
    }
}
