<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use App\Support\OrderDeliveryTarget;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Заказ бара к будущей брони: админ не видит задание, пока не наступит
 * окно (за 7 минут до старта) или гость не нажмёт «Я на месте».
 */
class PreSessionOrderService
{
    public const ADMIN_LEAD_MINUTES = 7;

    public const CLIENT_DELIVERY_MINUTES = 5;

    public const BOOKING_ORDER_MESSAGE = 'Заказ будет доставлен за 5 минут до начала к забронированному компьютеру.';

    public static function bookingDeliveredMessage(?string $pcName = null): string
    {
        $target = filled($pcName) ? $pcName : 'забронированному компьютеру';

        return "Заказ будет доставлен за 5 минут до начала к {$target}.";
    }

    /**
     * @return array{
     *     mode: 'session'|'booking'|'none',
     *     message: string,
     *     pc_name: ?string,
     *     booking: ?Booking,
     *     immediate: bool,
     *     fulfill_at: ?CarbonInterface,
     *     session_starts_at: ?CarbonInterface
     * }
     */
    public function resolveForUser(int $userId): array
    {
        $active = OrderDeliveryTarget::activeBookingForUser($userId);
        if ($active) {
            return $this->pack('session', $active, true);
        }

        $upcoming = OrderDeliveryTarget::upcomingBookingForUser($userId);
        if ($upcoming) {
            $startsAt = OrderDeliveryTarget::startsAtFromBooking($upcoming);
            $immediate = $this->shouldReleaseImmediately($startsAt);

            return $this->pack('booking', $upcoming, $immediate, self::BOOKING_ORDER_MESSAGE);
        }

        return $this->pack('none', null, false, 'Нет активной сессии в клубе. Заказ можно оформить только когда вы сидите за ПК');
    }

    /**
     * @return array{
     *     mode: 'session'|'booking'|'none',
     *     message: string,
     *     pc_name: ?string,
     *     booking: ?Booking,
     *     immediate: bool,
     *     fulfill_at: ?CarbonInterface,
     *     session_starts_at: ?CarbonInterface
     * }
     */
    public function resolveForComputer(int $computerId): array
    {
        $active = OrderDeliveryTarget::activeBookingOnComputer($computerId);
        if ($active) {
            return $this->pack('session', $active, true);
        }

        $upcoming = OrderDeliveryTarget::upcomingBookingOnComputer($computerId);
        if ($upcoming) {
            $startsAt = OrderDeliveryTarget::startsAtFromBooking($upcoming);
            $immediate = $this->shouldReleaseImmediately($startsAt);

            return $this->pack('booking', $upcoming, $immediate, self::BOOKING_ORDER_MESSAGE);
        }

        return $this->pack('none', null, false, 'Активная сессия не найдена.');
    }

    /**
     * @return array<string, mixed>
     */
    public function deliveryContextForUser(int $userId): array
    {
        $resolved = $this->resolveForUser($userId);

        return [
            'mode' => $resolved['mode'],
            'message' => $resolved['message'],
            'pc_name' => $resolved['pc_name'],
            'immediate' => $resolved['immediate'],
            'session_starts_at' => $resolved['session_starts_at']?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function orderCreateAttributes(array $resolved, string $pcName): array
    {
        $booking = $resolved['booking'] ?? null;
        $immediate = (bool) ($resolved['immediate'] ?? true);
        $isPreorder = ($resolved['mode'] ?? '') === 'booking';
        $startsAt = $resolved['session_starts_at'] ?? null;
        $attrs = [
            'pc_name' => $pcName,
            'status' => $immediate ? Order::STATUS_PENDING : Order::STATUS_SCHEDULED,
            'booking_id' => $booking?->id,
            'fulfill_at' => $isPreorder ? ($resolved['fulfill_at'] ?? $this->fulfillAtFor($startsAt)) : null,
            'released_at' => $immediate ? now() : null,
            'session_starts_at' => $startsAt,
        ];

        return $attrs;
    }

    public function shouldReleaseImmediately(?CarbonInterface $startsAt): bool
    {
        if (! $startsAt) {
            return true;
        }

        return now()->greaterThanOrEqualTo($startsAt->copy()->subMinutes(self::ADMIN_LEAD_MINUTES));
    }

    public function fulfillAtFor(?CarbonInterface $startsAt): ?CarbonInterface
    {
        if (! $startsAt) {
            return null;
        }

        return $startsAt->copy()->subMinutes(self::ADMIN_LEAD_MINUTES);
    }

    public function enqueueKitchenIfPending(Order|int $order): void
    {
        $order = $order instanceof Order
            ? $order
            : Order::query()->find($order);

        if (! $order || $order->status !== Order::STATUS_PENDING) {
            return;
        }

        try {
            app(KitchenOrderPrintService::class)->enqueue($order);
        } catch (\Throwable $e) {
            Log::warning('Kitchen print enqueue (pre-session): '.$e->getMessage(), [
                'order_id' => $order->id,
            ]);
        }
    }

    public function releaseDueOrders(?CarbonInterface $now = null): int
    {
        $now = $now ?? now();

        $ids = Order::query()
            ->where('status', Order::STATUS_SCHEDULED)
            ->whereNotNull('fulfill_at')
            ->where('fulfill_at', '<=', $now)
            ->pluck('id');

        $released = 0;
        foreach ($ids as $id) {
            if ($this->releaseOrderById((int) $id)) {
                $released++;
            }
        }

        return $released;
    }

    public function releaseForComputer(int $computerId): int
    {
        $labels = OrderDeliveryTarget::matchLabels($computerId);
        $ids = Order::query()
            ->where('status', Order::STATUS_SCHEDULED)
            ->whereIn('pc_name', $labels)
            ->pluck('id');

        $released = 0;
        foreach ($ids as $id) {
            if ($this->releaseOrderById((int) $id)) {
                $released++;
            }
        }

        return $released;
    }

    public function releaseOrderById(int $orderId): bool
    {
        $order = Order::query()->find($orderId);
        if (! $order || $order->status !== Order::STATUS_SCHEDULED) {
            return false;
        }

        $order->update([
            'status' => Order::STATUS_PENDING,
            'released_at' => now(),
        ]);

        $this->enqueueKitchenIfPending($order->fresh());

        Log::info('Pre-session shop order released to admin queue', [
            'order_id' => $order->id,
            'pc_name' => $order->pc_name,
            'booking_id' => $order->booking_id,
        ]);

        return true;
    }

    /**
     * @return array{has_scheduled_order: bool, scheduled_order_id: ?int, scheduled_summary: string, scheduled_pc_name: ?string}
     */
    public function scheduledSnapshotForComputer(int $computerId): array
    {
        if ($computerId < 1) {
            return [
                'has_scheduled_order' => false,
                'scheduled_order_id' => null,
                'scheduled_summary' => '',
                'scheduled_pc_name' => null,
            ];
        }

        $order = Order::query()
            ->where('status', Order::STATUS_SCHEDULED)
            ->whereIn('pc_name', OrderDeliveryTarget::matchLabels($computerId))
            ->orderByDesc('id')
            ->first();

        return [
            'has_scheduled_order' => (bool) $order,
            'scheduled_order_id' => $order ? (int) $order->id : null,
            'scheduled_summary' => $order ? (string) $order->product_name : '',
            'scheduled_pc_name' => $order?->pc_name,
        ];
    }

    /**
     * @param  list<int>  $bookingIds
     */
    public function cancelScheduledForBookingIds(array $bookingIds): int
    {
        $bookingIds = array_values(array_filter(array_map('intval', $bookingIds)));
        if ($bookingIds === []) {
            return 0;
        }

        $orders = Order::query()
            ->where('status', Order::STATUS_SCHEDULED)
            ->whereIn('booking_id', $bookingIds)
            ->get();

        $count = 0;
        foreach ($orders as $order) {
            $this->cancelScheduledOrder($order);
            $count++;
        }

        return $count;
    }

    public function cancelScheduledOrder(Order $order): void
    {
        if ($order->status !== Order::STATUS_SCHEDULED) {
            return;
        }

        DB::transaction(function () use ($order) {
            $locked = Order::query()->lockForUpdate()->find($order->id);
            if (! $locked || $locked->status !== Order::STATUS_SCHEDULED) {
                return;
            }

            $items = $locked->lineItems();
            $stock = app(ProductStockService::class);
            $stock->releaseReservationsForOrder((int) $locked->id);
            $stock->restoreUnmarkedForOrder((int) $locked->id, $items);

            $amount = (float) $locked->price;
            if ($locked->user_id && $amount > 0) {
                $user = User::query()->lockForUpdate()->find($locked->user_id);
                if ($user) {
                    $user->syncBalanceToWallet();
                    $wallet = $user->wallet()->lockForUpdate()->first();
                    if ($wallet) {
                        $wallet->creditSpendable($amount);
                        Transaction::create([
                            'user_id' => $user->id,
                            'amount' => $amount,
                            'type' => 'refund',
                            'source' => 'market',
                            'description' => 'Возврат магазина (бронь отменена): '.$locked->product_name,
                            'payload' => ['order_id' => $locked->id],
                        ]);
                    }
                }
            }

            $locked->update(['status' => Order::STATUS_CANCELLED]);
        });
    }

    /**
     * @return array{
     *     mode: string,
     *     message: string,
     *     pc_name: ?string,
     *     booking: ?Booking,
     *     immediate: bool,
     *     fulfill_at: ?CarbonInterface,
     *     session_starts_at: ?CarbonInterface
     * }
     */
    private function pack(string $mode, ?Booking $booking, bool $immediate, string $message = ''): array
    {
        $startsAt = $booking ? OrderDeliveryTarget::startsAtFromBooking($booking) : null;
        $pcName = $booking ? OrderDeliveryTarget::labelFromBooking($booking) : null;

        if ($mode === 'session' && $pcName) {
            $message = $message !== '' ? $message : "Заказ оформлен! Доставим к {$pcName}.";
        }

        return [
            'mode' => $mode,
            'message' => $message,
            'pc_name' => $pcName,
            'booking' => $booking,
            'immediate' => $immediate,
            'fulfill_at' => $immediate ? null : $this->fulfillAtFor($startsAt),
            'session_starts_at' => $startsAt,
        ];
    }
}
