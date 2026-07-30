<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductReservation;
use App\Models\ProductUnit;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProductStockService
{
    public function availableUnitsCount(int $productId): int
    {
        return ProductUnit::query()
            ->where('product_id', $productId)
            ->where('status', ProductUnit::STATUS_AVAILABLE)
            ->count();
    }

    public function reservedQty(int $productId): int
    {
        return (int) ProductReservation::query()
            ->where('product_id', $productId)
            ->sum('qty');
    }

    /** Units free to sell (not held by pending/cooking orders). */
    public function sellableUnitsCount(int $productId): int
    {
        return max(0, $this->availableUnitsCount($productId) - $this->reservedQty($productId));
    }

    public function syncMarkedStock(Product|int $product): int
    {
        $productId = $product instanceof Product ? (int) $product->id : (int) $product;
        $count = $this->sellableUnitsCount($productId);

        Product::query()->where('id', $productId)->update(['stock' => $count]);

        if ($product instanceof Product) {
            $product->stock = $count;
        }

        return $count;
    }

    /**
     * Ensure product has enough sellable quantity.
     * Marked: available units minus reservations. Unmarked: products.stock.
     */
    public function assertAvailable(Product $product, int $qty): void
    {
        if ($qty < 1) {
            throw new RuntimeException('Количество должно быть больше нуля');
        }

        if ($product->requires_marking) {
            $available = $this->sellableUnitsCount((int) $product->id);
            if ($available < $qty) {
                throw new RuntimeException(
                    "Недостаточно «{$product->name}» на складе (нужно {$qty}, есть {$available})"
                );
            }

            return;
        }

        if ((int) $product->stock < $qty) {
            throw new RuntimeException(
                "Недостаточно «{$product->name}» на складе (нужно {$qty}, есть {$product->stock})"
            );
        }
    }

    /**
     * Hold marked qty for an open order until fulfill or cancel.
     *
     * @param  array<int, array{product_id?:?int, qty?:int}>  $items
     */
    public function reserveMarkedForOrder(int $orderId, array $items): void
    {
        DB::transaction(function () use ($orderId, $items) {
            foreach ($items as $row) {
                $productId = isset($row['product_id']) ? (int) $row['product_id'] : 0;
                $qty = max(1, (int) ($row['qty'] ?? 1));
                if ($productId < 1) {
                    continue;
                }

                /** @var Product|null $product */
                $product = Product::query()->lockForUpdate()->find($productId);
                if (! $product || ! $product->requires_marking) {
                    continue;
                }

                if ($this->sellableUnitsCount($productId) < $qty) {
                    throw new RuntimeException(
                        "Недостаточно «{$product->name}» на складе (нужно {$qty}, свободно {$this->sellableUnitsCount($productId)})"
                    );
                }

                ProductReservation::create([
                    'order_id' => $orderId,
                    'product_id' => $productId,
                    'qty' => $qty,
                ]);

                $this->syncMarkedStock($product);
            }
        });
    }

    public function releaseReservationsForOrder(int $orderId): void
    {
        $rows = ProductReservation::query()->where('order_id', $orderId)->get();
        if ($rows->isEmpty()) {
            return;
        }

        $productIds = $rows->pluck('product_id')->unique()->all();
        ProductReservation::query()->where('order_id', $orderId)->delete();

        foreach ($productIds as $productId) {
            $this->syncMarkedStock((int) $productId);
        }
    }

    /**
     * Receive one marked unit by DataMatrix. Returns the unit.
     */
    public function receiveByMarkingCode(Product $product, string $rawCode, int $adminId): ProductUnit
    {
        if (! $product->requires_marking) {
            throw new RuntimeException('Эта позиция не требует поэкземплярной маркировки');
        }

        $code = ProductUnit::normalizeCode($rawCode);
        if ($code === '' || mb_strlen($code) < 10) {
            throw new RuntimeException('Код маркировки слишком короткий или пустой');
        }

        if (ProductUnit::query()->where('marking_code', $code)->exists()) {
            throw new RuntimeException('Этот код маркировки уже принят на склад');
        }

        return DB::transaction(function () use ($product, $code, $adminId) {
            $unit = ProductUnit::create([
                'product_id' => $product->id,
                'marking_code' => $code,
                'status' => ProductUnit::STATUS_AVAILABLE,
                'received_by' => $adminId,
                'received_at' => now(),
            ]);

            $this->syncMarkedStock($product);

            return $unit;
        });
    }

    /**
     * Sell unmarked product quantity (decrement stock at checkout).
     */
    public function decrementUnmarked(Product $product, int $qty): void
    {
        if ($product->requires_marking) {
            return;
        }

        if ((int) $product->stock < $qty) {
            throw new RuntimeException("Недостаточно «{$product->name}» на складе");
        }

        $product->decrement('stock', $qty);
    }

    /**
     * Bind available unit to order (fulfillment scan).
     */
    public function sellUnitByMarkingCode(int $orderId, string $rawCode, ?int $expectedProductId = null): ProductUnit
    {
        $code = ProductUnit::normalizeCode($rawCode);
        if ($code === '') {
            throw new RuntimeException('Пустой код маркировки');
        }

        return DB::transaction(function () use ($orderId, $code, $expectedProductId) {
            /** @var ProductUnit|null $unit */
            $unit = ProductUnit::query()
                ->where('marking_code', $code)
                ->lockForUpdate()
                ->first();

            if (! $unit) {
                throw new RuntimeException('Код маркировки не найден на складе');
            }

            if ($unit->status !== ProductUnit::STATUS_AVAILABLE) {
                throw new RuntimeException('Этот код уже выдан или списан');
            }

            if ($expectedProductId && (int) $unit->product_id !== (int) $expectedProductId) {
                throw new RuntimeException('Код относится к другому товару');
            }

            $unit->update([
                'status' => ProductUnit::STATUS_SOLD,
                'sold_order_id' => $orderId,
                'sold_at' => now(),
            ]);

            $this->consumeReservation($orderId, (int) $unit->product_id, 1);
            $this->syncMarkedStock((int) $unit->product_id);

            return $unit->fresh(['product']);
        });
    }

    /** Shrink hold after a KM is scanned for the order. */
    public function consumeReservation(int $orderId, int $productId, int $qty = 1): void
    {
        $left = $qty;
        $rows = ProductReservation::query()
            ->where('order_id', $orderId)
            ->where('product_id', $productId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($rows as $row) {
            if ($left <= 0) {
                break;
            }
            if ($row->qty <= $left) {
                $left -= $row->qty;
                $row->delete();
            } else {
                $row->update(['qty' => $row->qty - $left]);
                $left = 0;
            }
        }
    }

    public function writeOffUnit(string $rawCode, int $adminId, string $reason): ProductUnit
    {
        $code = ProductUnit::normalizeCode($rawCode);
        if ($code === '') {
            throw new RuntimeException('Пустой код маркировки');
        }

        return DB::transaction(function () use ($code, $adminId, $reason) {
            /** @var ProductUnit|null $unit */
            $unit = ProductUnit::query()
                ->where('marking_code', $code)
                ->lockForUpdate()
                ->first();

            if (! $unit) {
                throw new RuntimeException('Код маркировки не найден');
            }

            if ($unit->status !== ProductUnit::STATUS_AVAILABLE) {
                throw new RuntimeException('Списать можно только доступную единицу');
            }

            $unit->update([
                'status' => ProductUnit::STATUS_WRITTEN_OFF,
                'written_off_by' => $adminId,
                'write_off_reason' => $reason,
                'written_off_at' => now(),
            ]);

            $this->syncMarkedStock((int) $unit->product_id);

            return $unit->fresh(['product']);
        });
    }

    /**
     * Marked qty still required for order (from items JSON vs already sold units).
     *
     * @param  array<int, array{product_id?:?int, name?:string, qty?:int}>  $items
     * @return array<int, array{product_id:int, name:string, required:int, scanned:int, remaining:int}>
     */
    public function markingFulfillmentProgress(int $orderId, array $items): array
    {
        $needed = [];
        foreach ($items as $row) {
            $productId = isset($row['product_id']) ? (int) $row['product_id'] : 0;
            $qty = max(1, (int) ($row['qty'] ?? 1));
            if ($productId < 1) {
                continue;
            }

            $product = Product::query()->find($productId);
            if (! $product || ! $product->requires_marking) {
                continue;
            }

            if (! isset($needed[$productId])) {
                $needed[$productId] = [
                    'product_id' => $productId,
                    'name' => $product->name,
                    'required' => 0,
                    'scanned' => 0,
                    'remaining' => 0,
                ];
            }
            $needed[$productId]['required'] += $qty;
        }

        if ($needed === []) {
            return [];
        }

        $scanned = ProductUnit::query()
            ->where('sold_order_id', $orderId)
            ->where('status', ProductUnit::STATUS_SOLD)
            ->selectRaw('product_id, COUNT(*) as cnt')
            ->groupBy('product_id')
            ->pluck('cnt', 'product_id');

        foreach ($needed as $productId => &$row) {
            $row['scanned'] = (int) ($scanned[$productId] ?? 0);
            $row['remaining'] = max(0, $row['required'] - $row['scanned']);
        }
        unset($row);

        return array_values($needed);
    }

    public function orderMarkingFullyScanned(int $orderId, array $items): bool
    {
        foreach ($this->markingFulfillmentProgress($orderId, $items) as $row) {
            if ($row['remaining'] > 0) {
                return false;
            }
        }

        return true;
    }
}
