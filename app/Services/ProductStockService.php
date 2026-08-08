<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductReservation;
use App\Models\ProductUnit;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProductStockService
{
    /** @var list<string> */
    public const WRITE_OFF_REASON_CODES = [
        StockMovement::REASON_SPOILAGE,
        StockMovement::REASON_EXPIRED,
        StockMovement::REASON_BROKEN,
        StockMovement::REASON_COMP,
        StockMovement::REASON_OTHER,
    ];

    public function __construct(
        private readonly ?InventoryCostService $costs = null,
    ) {
    }

    private function costs(): InventoryCostService
    {
        return $this->costs ?? app(InventoryCostService::class);
    }

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
    public function receiveByMarkingCode(
        Product $product,
        string $rawCode,
        int $adminId,
        ?float $unitCost = null,
        ?int $supplierId = null,
        bool $createInvoice = true,
        ?string $invoiceNumber = null,
    ): ProductUnit {
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

        return DB::transaction(function () use ($product, $code, $adminId, $unitCost, $supplierId, $createInvoice, $invoiceNumber) {
            $unit = ProductUnit::create([
                'product_id' => $product->id,
                'marking_code' => $code,
                'status' => ProductUnit::STATUS_AVAILABLE,
                'received_by' => $adminId,
                'received_at' => now(),
            ]);

            $this->syncMarkedStock($product);

            $cost = $unitCost !== null
                ? (float) $unitCost
                : (float) ($product->fresh()->cost_price ?? 0);
            $supplier = $supplierId ?? $product->supplier_id;

            $this->costs()->receive(
                $product->fresh(),
                1,
                $cost,
                $supplier ? (int) $supplier : null,
                $adminId,
                (int) $unit->id,
                $createInvoice,
                $invoiceNumber,
            );

            return $unit;
        });
    }

    /**
     * Receive unmarked qty (+N) with cost/batch booking.
     */
    public function receiveUnmarked(
        Product $product,
        int $qty,
        int $adminId,
        ?float $unitCost = null,
        ?int $supplierId = null,
        bool $createInvoice = true,
        ?string $invoiceNumber = null,
    ): Product {
        if ($product->requires_marking) {
            throw new RuntimeException('Маркированный товар принимается сканом КМ');
        }

        $qty = max(1, $qty);

        return DB::transaction(function () use ($product, $qty, $adminId, $unitCost, $supplierId, $createInvoice, $invoiceNumber) {
            /** @var Product $locked */
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            $locked->increment('stock', $qty);
            $locked->refresh();

            $cost = $unitCost !== null
                ? (float) $unitCost
                : (float) ($locked->cost_price ?? 0);
            $supplier = $supplierId ?? $locked->supplier_id;

            $this->costs()->receive(
                $locked,
                $qty,
                $cost,
                $supplier ? (int) $supplier : null,
                $adminId,
                null,
                $createInvoice,
                $invoiceNumber,
            );

            return $locked->fresh();
        });
    }

    /**
     * Sell unmarked product quantity (decrement stock at checkout).
     */
    public function decrementUnmarked(Product $product, int $qty, ?int $orderId = null): void
    {
        if ($product->requires_marking) {
            return;
        }

        if ((int) $product->stock < $qty) {
            throw new RuntimeException("Недостаточно «{$product->name}» на складе");
        }

        DB::transaction(function () use ($product, $qty, $orderId) {
            /** @var Product $locked */
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            if ((int) $locked->stock < $qty) {
                throw new RuntimeException("Недостаточно «{$locked->name}» на складе");
            }

            $before = (int) $locked->stock;
            $locked->decrement('stock', $qty);
            $locked->refresh();

            $fifo = $this->costs()->consumeFifo((int) $locked->id, $qty);

            $this->recordMovement([
                'product_id' => (int) $locked->id,
                'order_id' => $orderId,
                'type' => StockMovement::TYPE_SALE,
                'qty' => -$qty,
                'stock_before' => $before,
                'stock_after' => (int) $locked->stock,
                'reason_code' => null,
                'reason' => 'Продажа',
                'meta' => [
                    'cogs' => $fifo['cogs'],
                    'fifo_layers' => $fifo['layers'],
                ],
            ]);
        });
    }

    /**
     * Restore unmarked qty when an order is cancelled (stock was decremented at checkout).
     *
     * @param  array<int, array{product_id?:?int, qty?:int}>  $items
     */
    public function restoreUnmarkedForOrder(int $orderId, array $items): void
    {
        foreach ($items as $row) {
            $productId = isset($row['product_id']) ? (int) $row['product_id'] : 0;
            $qty = max(1, (int) ($row['qty'] ?? 1));
            if ($productId < 1) {
                continue;
            }

            DB::transaction(function () use ($orderId, $productId, $qty) {
                /** @var Product|null $product */
                $product = Product::query()->lockForUpdate()->find($productId);
                if (! $product || $product->requires_marking) {
                    return;
                }

                $before = (int) $product->stock;
                $product->increment('stock', $qty);
                $product->refresh();

                $unitCost = (float) ($product->cost_price ?? 0);
                $this->costs()->restoreBatch($productId, $qty, $unitCost);

                $this->recordMovement([
                    'product_id' => $productId,
                    'order_id' => $orderId,
                    'type' => StockMovement::TYPE_SALE_RESTORE,
                    'qty' => $qty,
                    'stock_before' => $before,
                    'stock_after' => (int) $product->stock,
                    'reason_code' => StockMovement::REASON_CANCEL,
                    'reason' => StockMovement::formatReason(StockMovement::REASON_CANCEL, "заказ #{$orderId}"),
                    'meta' => ['unit_cost' => $unitCost],
                ]);
            });
        }
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

            $before = $this->sellableUnitsCount((int) $unit->product_id);

            $unit->update([
                'status' => ProductUnit::STATUS_SOLD,
                'sold_order_id' => $orderId,
                'sold_at' => now(),
            ]);

            $this->consumeReservation($orderId, (int) $unit->product_id, 1);
            $after = $this->syncMarkedStock((int) $unit->product_id);

            $fifo = $this->costs()->consumeUnit((int) $unit->id, (int) $unit->product_id);

            $this->recordMovement([
                'product_id' => (int) $unit->product_id,
                'product_unit_id' => (int) $unit->id,
                'order_id' => $orderId,
                'type' => StockMovement::TYPE_SALE,
                'qty' => -1,
                'stock_before' => $before,
                'stock_after' => $after,
                'reason' => 'Продажа (КМ)',
                'meta' => [
                    'cogs' => $fifo['cogs'],
                    'fifo_layers' => $fifo['layers'],
                ],
            ]);

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

    public function writeOffUnit(
        string $rawCode,
        int $adminId,
        string $reason,
        ?string $reasonCode = null,
        string $type = StockMovement::TYPE_WRITE_OFF
    ): ProductUnit {
        $code = ProductUnit::normalizeCode($rawCode);
        if ($code === '') {
            throw new RuntimeException('Пустой код маркировки');
        }

        if (! in_array($type, [StockMovement::TYPE_WRITE_OFF, StockMovement::TYPE_COMP], true)) {
            $type = StockMovement::TYPE_WRITE_OFF;
        }

        $reasonCode = $this->normalizeReasonCode($reasonCode, $type);
        $reasonText = StockMovement::formatReason($reasonCode, $reason);

        return DB::transaction(function () use ($code, $adminId, $reasonText, $reasonCode, $type) {
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

            $before = $this->sellableUnitsCount((int) $unit->product_id);

            $unit->update([
                'status' => ProductUnit::STATUS_WRITTEN_OFF,
                'written_off_by' => $adminId,
                'write_off_reason' => $reasonText,
                'written_off_at' => now(),
            ]);

            $after = $this->syncMarkedStock((int) $unit->product_id);

            $fifo = $this->costs()->consumeUnit((int) $unit->id, (int) $unit->product_id);

            $this->recordMovement([
                'product_id' => (int) $unit->product_id,
                'product_unit_id' => (int) $unit->id,
                'admin_id' => $adminId,
                'type' => $type,
                'reason_code' => $reasonCode,
                'reason' => $reasonText,
                'qty' => -1,
                'stock_before' => $before,
                'stock_after' => $after,
                'meta' => [
                    'cogs' => $fifo['cogs'],
                    'fifo_layers' => $fifo['layers'],
                ],
            ]);

            return $unit->fresh(['product']);
        });
    }

    /**
     * Write-off or complimentary drink for unmarked SKU (qty + reason).
     * Keeps пересменка clean: explained mid-shift losses don't look like theft.
     *
     * @return array{product: Product, movement: StockMovement}
     */
    public function adjustUnmarked(
        Product $product,
        int $qty,
        int $adminId,
        string $type,
        ?string $reasonCode = null,
        ?string $reasonNote = null,
        ?int $shiftId = null
    ): array {
        if ($product->requires_marking) {
            throw new RuntimeException('Маркированный товар списывается сканом КМ, не количеством');
        }

        if ($qty < 1) {
            throw new RuntimeException('Количество должно быть больше нуля');
        }

        if (! in_array($type, [StockMovement::TYPE_WRITE_OFF, StockMovement::TYPE_COMP], true)) {
            throw new RuntimeException('Неизвестный тип движения');
        }

        $reasonCode = $this->normalizeReasonCode(
            $reasonCode,
            $type === StockMovement::TYPE_COMP ? StockMovement::TYPE_COMP : StockMovement::TYPE_WRITE_OFF
        );
        $reasonText = StockMovement::formatReason($reasonCode, $reasonNote);

        return DB::transaction(function () use ($product, $qty, $adminId, $type, $reasonCode, $reasonText, $shiftId) {
            /** @var Product $locked */
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            $before = (int) $locked->stock;

            if ($before < $qty) {
                throw new RuntimeException("Недостаточно «{$locked->name}» на складе (есть {$before})");
            }

            $after = $before - $qty;
            $locked->update(['stock' => $after]);

            $fifo = $this->costs()->consumeFifo((int) $locked->id, $qty);

            $movement = $this->recordMovement([
                'product_id' => (int) $locked->id,
                'admin_id' => $adminId,
                'shift_id' => $shiftId,
                'type' => $type,
                'reason_code' => $reasonCode,
                'reason' => $reasonText,
                'qty' => -$qty,
                'stock_before' => $before,
                'stock_after' => $after,
                'meta' => [
                    'cogs' => $fifo['cogs'],
                    'fifo_layers' => $fifo['layers'],
                ],
            ]);

            return [
                'product' => $locked->fresh(),
                'movement' => $movement,
            ];
        });
    }

    /**
     * Apply shift recount for unmarked product: set stock to actual and log the delta.
     *
     * @return StockMovement|null  null when no change
     */
    public function applyShiftAdjustment(
        Product $product,
        int $expected,
        int $actual,
        int $adminId,
        int $shiftId,
        string $reasonNote
    ): ?StockMovement {
        if ($product->requires_marking) {
            throw new RuntimeException("«{$product->name}» маркирован — остаток правится списанием КМ, не пересменкой");
        }

        $delta = $actual - $expected;
        if ($delta === 0) {
            return null;
        }

        return DB::transaction(function () use ($product, $actual, $adminId, $shiftId, $reasonNote, $delta) {
            /** @var Product $locked */
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            $before = (int) $locked->stock;
            $locked->update(['stock' => max(0, $actual)]);

            return $this->recordMovement([
                'product_id' => (int) $locked->id,
                'admin_id' => $adminId,
                'shift_id' => $shiftId,
                'type' => StockMovement::TYPE_SHIFT_ADJUST,
                'reason_code' => StockMovement::REASON_SHIFT,
                'reason' => StockMovement::formatReason(StockMovement::REASON_SHIFT, $reasonNote),
                'qty' => $delta,
                'stock_before' => $before,
                'stock_after' => max(0, $actual),
                'meta' => [
                    'expected' => $before,
                    'actual' => $actual,
                ],
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    public function recordMovement(array $attrs): StockMovement
    {
        return StockMovement::create([
            'product_id' => (int) $attrs['product_id'],
            'product_unit_id' => $attrs['product_unit_id'] ?? null,
            'admin_id' => $attrs['admin_id'] ?? null,
            'shift_id' => $attrs['shift_id'] ?? null,
            'order_id' => $attrs['order_id'] ?? null,
            'type' => (string) $attrs['type'],
            'reason_code' => $attrs['reason_code'] ?? null,
            'reason' => $attrs['reason'] ?? null,
            'qty' => (int) $attrs['qty'],
            'stock_before' => (int) ($attrs['stock_before'] ?? 0),
            'stock_after' => (int) ($attrs['stock_after'] ?? 0),
            'meta' => $attrs['meta'] ?? null,
        ]);
    }

    public function normalizeReasonCode(?string $code, string $type = StockMovement::TYPE_WRITE_OFF): string
    {
        $code = $code !== null ? strtolower(trim($code)) : '';

        if ($type === StockMovement::TYPE_COMP) {
            return StockMovement::REASON_COMP;
        }

        if (in_array($code, self::WRITE_OFF_REASON_CODES, true)) {
            return $code;
        }

        return StockMovement::REASON_OTHER;
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
