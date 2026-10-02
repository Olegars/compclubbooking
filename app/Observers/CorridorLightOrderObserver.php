<?php

namespace App\Observers;

use App\Models\Order;
use App\Services\Light\WledCueService;

class CorridorLightOrderObserver
{
    public function created(Order $order): void
    {
        if (! in_array($order->status, [Order::STATUS_PENDING, Order::STATUS_COOKING], true)) {
            return;
        }

        $this->signal($order);
    }

    public function updated(Order $order): void
    {
        if (! $order->wasChanged('status')) {
            return;
        }
        if ($order->status !== Order::STATUS_PENDING) {
            return;
        }
        if ($order->getOriginal('status') !== Order::STATUS_SCHEDULED) {
            return;
        }

        $this->signal($order);
    }

    private function signal(Order $order): void
    {
        try {
            app(WledCueService::class)->fireForOrder($order);
        } catch (\Throwable $e) {
            WledCueService::reportFailure($e, 'order');
        }
    }
}
