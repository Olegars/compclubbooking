<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Computer;

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
}
