<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderKitchenPrint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Кухонный чек-ордер ESC/POS: очередь в облаке, печать делает LAN-агент (Ethernet :9100).
 */
class KitchenOrderPrintService
{
    public function enabled(): bool
    {
        return (bool) config('kitchen_print.enabled', false);
    }

    public function enqueue(Order|int $order): ?OrderKitchenPrint
    {
        if (! $this->enabled()) {
            return null;
        }

        $order = $order instanceof Order
            ? $order
            : Order::query()->find($order);

        if (! $order) {
            return null;
        }

        try {
            if ($this->isPreorder($order)) {
                return $this->enqueuePreorderGroup($order);
            }

            return $this->createJob((int) $order->id, $this->buildSlipText($order));
        } catch (\Throwable $e) {
            Log::warning('Kitchen print enqueue failed: '.$e->getMessage(), [
                'order_id' => $order->id,
            ]);

            return null;
        }
    }

    public function buildSlipText(Order $order): string
    {
        return $this->buildGroupedSlipText(collect([$order]));
    }

    /**
     * @param  Collection<int, Order>  $orders
     */
    public function buildGroupedSlipText(Collection $orders): string
    {
        $orders = $orders->values();
        $first = $orders->first();
        if (! $first) {
            return '';
        }

        $pc = trim((string) ($first->pc_name ?: 'ПК'));
        $ids = $orders->pluck('id')->map(fn ($id) => '#'.$id)->implode(', ');
        $lines = [
            $pc.' | '.$ids,
            str_repeat('-', 32),
        ];

        foreach ($this->mergedLineItems($orders) as $row) {
            $lines[] = $row['qty'].'x '.$row['name'];
        }

        $lines[] = str_repeat('-', 32);
        if ($orders->count() > 1 || $this->isPreorder($first)) {
            $lines[] = 'ПРЕДЗАКАЗ';
        }
        $startsAt = $orders->first(fn (Order $order) => $order->session_starts_at)?->session_starts_at;
        if ($startsAt) {
            $lines[] = 'К СЕССИИ '.$startsAt->timezone(config('app.timezone'))->format('H:i');
        }
        $lines[] = now()->format('d.m.Y H:i');
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * ESC/POS: init + CP866 + bold header + body + partial cut.
     */
    public function buildEscPos(string $text): string
    {
        $esc = "\x1B";
        $gs = "\x1D";

        $out = $esc.'@'; // init
        $out .= $esc.'t'."\x11"; // code page 17 ≈ CP866 on many Epson-compat
        $out .= $esc.'a'."\x01"; // center
        $out .= $esc.'E'."\x01"; // bold on

        $rows = preg_split("/\r\n|\n|\r/", $text) ?: [$text];
        $first = true;
        foreach ($rows as $row) {
            $encoded = $this->encodeLine($row);
            if ($first) {
                $out .= $encoded."\n";
                $out .= $esc.'E'."\x00"; // bold off
                $out .= $esc.'a'."\x00"; // left
                $first = false;
            } else {
                $out .= $encoded."\n";
            }
        }

        $out .= "\n\n";
        $out .= $gs.'V'."\x00"; // full cut (or partial on some models)

        return $out;
    }

    private function encodeLine(string $line): string
    {
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'CP866//TRANSLIT//IGNORE', $line);
            if ($converted !== false) {
                return $converted;
            }
            $converted = @iconv('UTF-8', 'CP1251//TRANSLIT//IGNORE', $line);
            if ($converted !== false) {
                return $converted;
            }
        }

        return $line;
    }

    /**
     * @return list<array{id:int,order_id:int,text:string,escpos_base64:string}>
     */
    public function claimPending(int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));

        return DB::transaction(function () use ($limit) {
            /** @var Collection<int, OrderKitchenPrint> $jobs */
            $jobs = OrderKitchenPrint::query()
                ->where('status', OrderKitchenPrint::STATUS_PENDING)
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            $out = [];
            foreach ($jobs as $job) {
                $job->status = OrderKitchenPrint::STATUS_CLAIMED;
                $job->claimed_at = now();
                $job->attempts = (int) $job->attempts + 1;
                $job->save();

                $raw = $this->buildEscPos((string) $job->payload_text);
                $out[] = [
                    'id' => (int) $job->id,
                    'order_id' => (int) $job->order_id,
                    'text' => (string) $job->payload_text,
                    'escpos_base64' => base64_encode($raw),
                ];
            }

            return $out;
        });
    }

    /**
     * @param  list<int>  $ids
     */
    public function markPrinted(array $ids): int
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return 0;
        }

        return OrderKitchenPrint::query()
            ->whereIn('id', $ids)
            ->whereIn('status', [OrderKitchenPrint::STATUS_CLAIMED, OrderKitchenPrint::STATUS_PENDING])
            ->update([
                'status' => OrderKitchenPrint::STATUS_PRINTED,
                'printed_at' => now(),
                'last_error' => null,
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  list<array{id:int|string, error?:string}>  $rows
     */
    public function markFailed(array $rows): int
    {
        $updated = 0;
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $error = mb_substr((string) ($row['error'] ?? 'print failed'), 0, 500);
            $n = OrderKitchenPrint::query()
                ->where('id', $id)
                ->update([
                    'status' => OrderKitchenPrint::STATUS_FAILED,
                    'last_error' => $error,
                    'updated_at' => now(),
                ]);
            $updated += $n;
        }

        return $updated;
    }

    /**
     * Re-queue stale claimed jobs (agent died mid-flight).
     */
    public function releaseStaleClaims(int $minutes = 2): int
    {
        return OrderKitchenPrint::query()
            ->where('status', OrderKitchenPrint::STATUS_CLAIMED)
            ->where('claimed_at', '<', now()->subMinutes(max(1, $minutes)))
            ->update([
                'status' => OrderKitchenPrint::STATUS_PENDING,
                'claimed_at' => null,
                'updated_at' => now(),
            ]);
    }

    private function isPreorder(Order $order): bool
    {
        return $order->fulfill_at !== null && (int) $order->user_id > 0;
    }

    private function enqueuePreorderGroup(Order $order): OrderKitchenPrint
    {
        return DB::transaction(function () use ($order) {
            $siblings = $this->preorderSiblings($order);
            $ids = $siblings->pluck('id')->map(fn ($id) => (int) $id)->all();

            OrderKitchenPrint::query()
                ->whereIn('order_id', $ids)
                ->where('status', OrderKitchenPrint::STATUS_PENDING)
                ->lockForUpdate()
                ->get()
                ->each(fn (OrderKitchenPrint $job) => $job->delete());

            $primaryId = (int) $siblings->min('id');

            return $this->createJob($primaryId, $this->buildGroupedSlipText($siblings));
        });
    }

    private function createJob(int $orderId, string $text): OrderKitchenPrint
    {
        return OrderKitchenPrint::create([
            'order_id' => $orderId,
            'status' => OrderKitchenPrint::STATUS_PENDING,
            'payload_text' => $text,
            'attempts' => 0,
        ]);
    }

    /**
     * @return Collection<int, Order>
     */
    private function preorderSiblings(Order $order): Collection
    {
        $rows = Order::query()
            ->where('user_id', $order->user_id)
            ->where('pc_name', $order->pc_name)
            ->whereNotNull('fulfill_at')
            ->whereIn('status', [Order::STATUS_PENDING, Order::STATUS_COOKING])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($rows->contains('id', $order->id)) {
            return $rows;
        }

        return $rows->push($order)->sortBy('id')->values();
    }

    /**
     * @param  Collection<int, Order>  $orders
     * @return list<array{name: string, qty: int}>
     */
    private function mergedLineItems(Collection $orders): array
    {
        $merged = [];
        foreach ($orders as $order) {
            $items = $order->lineItems();
            if (count($items) === 0 && $order->product_name) {
                $items = [[
                    'product_id' => null,
                    'name' => (string) $order->product_name,
                    'qty' => 1,
                ]];
            }
            foreach ($items as $item) {
                $name = (string) ($item['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $key = (int) ($item['product_id'] ?? 0).':'.$name;
                if (! isset($merged[$key])) {
                    $merged[$key] = ['name' => $name, 'qty' => 0];
                }
                $merged[$key]['qty'] += max(1, (int) ($item['qty'] ?? 1));
            }
        }

        return array_values($merged);
    }
}
