<?php

namespace App\Services;

use App\Models\InventoryBatch;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Себестоимость (средневзвешенная на карточке + FIFO-партии), долги поставщикам.
 */
class InventoryCostService
{
    /**
     * Приход: партия FIFO + пересчёт cost_price (средневзвешенная) + опционально счёт поставщику.
     *
     * @return array{movement: StockMovement, batch: InventoryBatch, invoice: ?SupplierInvoice}
     */
    public function receive(
        Product $product,
        int $qty,
        float $unitCost,
        ?int $supplierId,
        int $adminId,
        ?int $productUnitId = null,
        bool $createInvoice = true,
        ?string $invoiceNumber = null,
    ): array {
        $qty = max(1, $qty);
        $unitCost = max(0, round($unitCost, 2));

        return DB::transaction(function () use ($product, $qty, $unitCost, $supplierId, $adminId, $productUnitId, $createInvoice, $invoiceNumber) {
            $product = Product::query()->lockForUpdate()->findOrFail($product->id);
            // Caller already updated stock (receiveScan / syncMarkedStock); stock includes this receive.
            $stockAfter = (int) $product->stock;
            $oldQtyForAvg = max(0, $stockAfter - $qty);
            $oldCost = (float) ($product->cost_price ?? 0);

            $newAvg = ($oldQtyForAvg + $qty) > 0
                ? round((($oldQtyForAvg * $oldCost) + ($qty * $unitCost)) / ($oldQtyForAvg + $qty), 2)
                : $unitCost;

            $product->cost_price = $newAvg;
            if ($supplierId) {
                $product->supplier_id = $supplierId;
            }
            $product->save();

            $movement = StockMovement::create([
                'product_id' => $product->id,
                'product_unit_id' => $productUnitId,
                'admin_id' => $adminId,
                'type' => StockMovement::TYPE_RECEIVE,
                'qty' => $qty,
                'stock_before' => $oldQtyForAvg,
                'stock_after' => $stockAfter,
                'reason' => 'Приёмка',
                'meta' => [
                    'unit_cost' => $unitCost,
                    'supplier_id' => $supplierId,
                    'cost_avg_after' => $newAvg,
                ],
            ]);

            $batch = InventoryBatch::create([
                'product_id' => $product->id,
                'supplier_id' => $supplierId,
                'stock_movement_id' => $movement->id,
                'product_unit_id' => $productUnitId,
                'qty_remaining' => $qty,
                'unit_cost' => $unitCost,
                'received_at' => now(),
            ]);

            $invoice = null;
            if ($createInvoice && $supplierId && $unitCost > 0) {
                $supplier = Supplier::query()->find($supplierId);
                $terms = (int) ($supplier?->payment_terms_days ?? 0);
                $total = round($qty * $unitCost, 2);
                $invoice = SupplierInvoice::create([
                    'supplier_id' => $supplierId,
                    'number' => $invoiceNumber ?: ('RCV-'.$movement->id),
                    'issued_at' => now()->toDateString(),
                    'due_at' => $terms > 0 ? now()->addDays($terms)->toDateString() : now()->toDateString(),
                    'total_amount' => $total,
                    'paid_amount' => 0,
                    'status' => SupplierInvoice::STATUS_OPEN,
                    'notes' => 'Приёмка «'.$product->name.'» × '.$qty,
                    'admin_id' => $adminId,
                ]);
            }

            return compact('movement', 'batch', 'invoice');
        });
    }

    /**
     * Списать партию конкретной маркированной единицы (или FIFO fallback).
     *
     * @return array{cogs: float, layers: list<array{batch_id:int,qty:int,unit_cost:float}>}
     */
    public function consumeUnit(?int $productUnitId, int $productId): array
    {
        if ($productUnitId) {
            $batch = InventoryBatch::query()
                ->where('product_unit_id', $productUnitId)
                ->where('qty_remaining', '>', 0)
                ->lockForUpdate()
                ->first();

            if ($batch) {
                $cost = (float) $batch->unit_cost;
                $batch->qty_remaining = 0;
                $batch->save();

                return [
                    'cogs' => round($cost, 2),
                    'layers' => [[
                        'batch_id' => (int) $batch->id,
                        'qty' => 1,
                        'unit_cost' => $cost,
                    ]],
                ];
            }
        }

        return $this->consumeFifo($productId, 1);
    }

    /**
     * Списать qty с партий FIFO. Возвращает суммарную себестоимость.
     *
     * @return array{cogs: float, layers: list<array{batch_id:int,qty:int,unit_cost:float}>}
     */
    public function consumeFifo(int $productId, int $qty): array
    {
        $qty = max(1, $qty);
        $left = $qty;
        $cogs = 0.0;
        $layers = [];

        $batches = InventoryBatch::query()
            ->where('product_id', $productId)
            ->where('qty_remaining', '>', 0)
            ->orderBy('received_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($batches as $batch) {
            if ($left <= 0) {
                break;
            }
            $take = min((int) $batch->qty_remaining, $left);
            $batch->qty_remaining = (int) $batch->qty_remaining - $take;
            $batch->save();
            $cogs += $take * (float) $batch->unit_cost;
            $layers[] = [
                'batch_id' => (int) $batch->id,
                'qty' => $take,
                'unit_cost' => (float) $batch->unit_cost,
            ];
            $left -= $take;
        }

        // Нет партий (старый остаток) — берём cost_price карточки
        if ($left > 0) {
            $product = Product::query()->find($productId);
            $fallback = (float) ($product?->cost_price ?? 0);
            $cogs += $left * $fallback;
            $layers[] = [
                'batch_id' => 0,
                'qty' => $left,
                'unit_cost' => $fallback,
            ];
        }

        return ['cogs' => round($cogs, 2), 'layers' => $layers];
    }

    /**
     * Возврат на склад (отмена продажи): новая партия по средней/переданной цене.
     */
    public function restoreBatch(int $productId, int $qty, float $unitCost): InventoryBatch
    {
        return InventoryBatch::create([
            'product_id' => $productId,
            'supplier_id' => null,
            'qty_remaining' => max(1, $qty),
            'unit_cost' => max(0, round($unitCost, 2)),
            'received_at' => now(),
        ]);
    }

    public function addPayment(SupplierInvoice $invoice, float $amount, int $adminId, ?string $note = null): SupplierPayment
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new RuntimeException('Сумма оплаты должна быть больше нуля');
        }
        if ($invoice->status === SupplierInvoice::STATUS_CANCELLED) {
            throw new RuntimeException('Счёт отменён');
        }

        return DB::transaction(function () use ($invoice, $amount, $adminId, $note) {
            $invoice = SupplierInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $due = $invoice->balanceDue();
            if ($amount > $due + 0.009) {
                throw new RuntimeException('Сумма больше долга (осталось '.$due.')');
            }

            $payment = SupplierPayment::create([
                'supplier_invoice_id' => $invoice->id,
                'amount' => $amount,
                'paid_at' => now(),
                'admin_id' => $adminId,
                'note' => $note,
            ]);

            $invoice->paid_amount = round((float) $invoice->paid_amount + $amount, 2);
            $invoice->refreshStatus();

            return $payment;
        });
    }

    /**
     * @return array{debt_total: float, overdue_total: float, invoices: list<array<string,mixed>>}
     */
    public function debtReport(): array
    {
        $invoices = SupplierInvoice::query()
            ->with('supplier:id,name')
            ->whereIn('status', [SupplierInvoice::STATUS_OPEN, SupplierInvoice::STATUS_PARTIAL])
            ->orderBy('due_at')
            ->get();

        $debt = 0.0;
        $overdue = 0.0;
        $rows = [];
        foreach ($invoices as $inv) {
            $bal = $inv->balanceDue();
            $debt += $bal;
            $isOver = $inv->isOverdue();
            if ($isOver) {
                $overdue += $bal;
            }
            $rows[] = [
                'id' => $inv->id,
                'supplier' => $inv->supplier?->name,
                'number' => $inv->number,
                'issued_at' => optional($inv->issued_at)?->toDateString(),
                'due_at' => optional($inv->due_at)?->toDateString(),
                'total' => (float) $inv->total_amount,
                'paid' => (float) $inv->paid_amount,
                'balance' => $bal,
                'overdue' => $isOver,
                'status' => $inv->status,
            ];
        }

        return [
            'debt_total' => round($debt, 2),
            'overdue_total' => round($overdue, 2),
            'invoices' => $rows,
        ];
    }

    /**
     * Маржа по каталогу: price vs cost_price.
     *
     * @return list<array{id:int,name:string,price:float,cost:float,margin:float,margin_pct:float,stock:int}>
     */
    public function marginSnapshot(): array
    {
        return Product::query()
            ->orderBy('name')
            ->get(['id', 'name', 'price', 'cost_price', 'stock'])
            ->map(function (Product $p) {
                $price = (float) $p->price;
                $cost = (float) ($p->cost_price ?? 0);
                $margin = round($price - $cost, 2);

                return [
                    'id' => (int) $p->id,
                    'name' => $p->name,
                    'price' => $price,
                    'cost' => $cost,
                    'margin' => $margin,
                    'margin_pct' => $price > 0 ? round($margin / $price * 100, 1) : 0,
                    'stock' => (int) $p->stock,
                ];
            })
            ->values()
            ->all();
    }
}
