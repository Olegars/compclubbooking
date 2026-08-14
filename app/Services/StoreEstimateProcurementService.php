<?php

namespace App\Services;

use App\Models\StoreComponent;
use App\Models\StoreEstimate;
use App\Models\StoreEstimateItem;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\StorePurchase;
use App\Models\StorePurchaseItem;
use App\Models\StoreSupplier;
use App\Models\StoreSupplierCatalogProduct;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StoreEstimateProcurementService
{
    public function __construct(private QuickFoxApiClient $api) {}

    /**
     * Обновить цены/остатки у позиций сметы, у которых есть supplier_sku.
     *
     * @return array{updated: int, missing: list<int>}
     */
    public function refreshSupplierPrices(StoreEstimate $estimate): array
    {
        if (! $this->api->isConfigured()) {
            throw new RuntimeException('QuickFox не настроен.');
        }

        $items = $estimate->items()->whereNotNull('supplier_sku')->get();
        $skus = $items->pluck('supplier_sku')->map(fn ($s) => (int) $s)->unique()->values()->all();
        $live = collect($this->api->getActiveProductsBySkus($skus))->keyBy(fn ($p) => (int) ($p['sku'] ?? 0));

        $updated = 0;
        $missing = [];

        foreach ($items as $item) {
            $sku = (int) $item->supplier_sku;
            $row = $live->get($sku);
            if (! $row) {
                $missing[] = $sku;
                $item->update([
                    'supplier_qty' => 0,
                    'status' => in_array($item->status, ['from_stock', 'ordered', 'received'], true)
                        ? $item->status
                        : 'to_order',
                ]);
                continue;
            }

            $price = isset($row['price']) ? (float) $row['price'] : null;
            $qty = isset($row['real_qty'])
                ? (int) $row['real_qty']
                : (isset($row['qty']) && is_numeric($row['qty']) ? (int) $row['qty'] : null);

            $status = $item->status;
            if (! in_array($status, ['from_stock', 'ordered', 'received'], true)) {
                $status = 'to_order';
            }

            $item->update([
                'supplier_price' => $price,
                'supplier_qty' => $qty,
                'status' => $status,
            ]);
            $updated++;
        }

        $estimate->recalculateTotals();

        return ['updated' => $updated, 'missing' => $missing];
    }

    /**
     * Создать заказ у поставщика по позициям «к закупке».
     */
    public function orderMissing(StoreEstimate $estimate, int $adminId, bool $confirm = false): StorePurchase
    {
        if (! $this->api->isConfigured()) {
            throw new RuntimeException('QuickFox не настроен.');
        }

        $lines = $estimate->items()
            ->whereIn('status', ['to_order', 'planned'])
            ->whereNotNull('supplier_sku')
            ->get();

        if ($lines->isEmpty()) {
            throw new RuntimeException('Нет позиций к закупке с артикулом поставщика.');
        }

        return DB::transaction(function () use ($estimate, $adminId, $confirm, $lines) {
            $comment = trim(($estimate->title ?: 'Смета #'.$estimate->id).' / club '.$estimate->club_id);
            $external = $this->api->createOrder($comment);
            $externalId = $external['id'];

            $apiLines = $lines->map(fn (StoreEstimateItem $i) => [
                'sku' => (int) $i->supplier_sku,
                'qty' => max(1, (int) $i->qty),
                'wish_price' => $i->supplier_price !== null ? (float) $i->supplier_price : null,
            ])->values()->all();

            $ordered = $this->api->updateOrderItems($externalId, $apiLines);
            $bySku = collect($ordered)->keyBy(fn ($r) => (int) ($r['sku'] ?? 0));

            if ($confirm) {
                $this->api->confirmOrder($externalId);
            }

            $total = 0;
            $purchase = StorePurchase::query()->create([
                'club_id' => $estimate->club_id,
                'store_estimate_id' => $estimate->id,
                'created_by' => $adminId,
                'external_order_id' => $externalId,
                'status' => $confirm ? 'confirmed' : 'submitted',
                'total' => 0,
                'notes' => $confirm ? 'Подписан на отгрузку' : null,
                'submitted_at' => now(),
            ]);

            foreach ($lines as $item) {
                $sku = (int) $item->supplier_sku;
                $apiRow = $bySku->get($sku);
                $price = isset($apiRow['price'])
                    ? (float) $apiRow['price']
                    : (float) ($item->supplier_price ?? 0);
                $qty = max(1, (int) $item->qty);
                $total += $price * $qty;

                StorePurchaseItem::query()->create([
                    'store_purchase_id' => $purchase->id,
                    'store_estimate_item_id' => $item->id,
                    'supplier_sku' => $sku,
                    'name' => $item->supplier_name ?: $item->name,
                    'qty' => $qty,
                    'price' => $price,
                    'status' => 'ordered',
                ]);

                $item->update([
                    'status' => 'ordered',
                    'supplier_price' => $price,
                ]);
            }

            $purchase->update(['total' => $total]);
            $estimate->update(['status' => 'procuring']);
            $estimate->recalculateTotals();

            return $purchase->fresh(['items']);
        });
    }

    /**
     * Принять закупку на склад (резерв под смету).
     *
     * @param  list<array{id:int,serials?:list<string>,notes?:string|null}>  $itemMeta
     */
    public function receivePurchase(StorePurchase $purchase, int $adminId, array $itemMeta = [], ?string $comment = null): void
    {
        if ($purchase->status === 'received') {
            throw new RuntimeException('Закупка уже принята.');
        }
        if (! in_array($purchase->status, ['submitted', 'confirmed'], true)) {
            throw new RuntimeException('Закупку нельзя принять в статусе '.$purchase->status);
        }

        $metaById = [];
        foreach ($itemMeta as $row) {
            if (! is_array($row) || empty($row['id'])) {
                continue;
            }
            $metaById[(int) $row['id']] = $row;
        }

        DB::transaction(function () use ($purchase, $adminId, $metaById, $comment) {
            $supplierId = $this->ensureApiSupplier($purchase->club_id);
            $purchase->load(['items.estimateItem', 'estimate']);

            $skus = $purchase->items
                ->pluck('supplier_sku')
                ->map(fn ($s) => (int) $s)
                ->filter(fn ($s) => $s > 0)
                ->unique()
                ->values()
                ->all();
            $warrantyBySku = StoreSupplierCatalogProduct::query()
                ->whereIn('sku', $skus)
                ->pluck('warranty', 'sku');

            $globalComment = trim((string) ($comment ?? ''));

            foreach ($purchase->items as $pItem) {
                if ($pItem->status === 'received' && $pItem->store_component_id) {
                    continue;
                }

                /** @var StoreEstimateItem|null $eItem */
                $eItem = $pItem->estimateItem;
                $type = $eItem?->type ?: 'other';
                $name = $pItem->name ?: ($eItem?->name ?? 'SKU '.$pItem->supplier_sku);
                $sku = (int) $pItem->supplier_sku;
                $warrantyMonths = $this->parseWarrantyMonths($warrantyBySku->get($sku));

                $meta = $metaById[(int) $pItem->id] ?? [];
                $serials = collect($meta['serials'] ?? [])
                    ->map(fn ($s) => trim((string) $s))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();
                $lineNote = trim((string) ($meta['notes'] ?? ''));

                $notes = array_values(array_filter([
                    'Смета #'.$purchase->store_estimate_id.' · закупка #'.$purchase->id.' · sku '.$pItem->supplier_sku,
                    $purchase->external_order_id ? 'EXT '.$purchase->external_order_id : null,
                    $globalComment !== '' ? $globalComment : null,
                    $lineNote !== '' ? $lineNote : null,
                ]));

                $component = StoreComponent::query()->create([
                    'club_id' => $purchase->club_id,
                    'store_supplier_id' => $supplierId,
                    'received_by' => $adminId,
                    'name' => $name,
                    'original_name' => $eItem?->supplier_name,
                    'type' => $type,
                    'purchase_price' => $pItem->price,
                    'warranty_number' => $serials[0] ?? null,
                    'serials' => $serials,
                    'warranty_months' => $warrantyMonths,
                    'qty' => 1,
                    'status' => 'reserved',
                    'notes' => $notes !== [] ? implode("\n", $notes) : null,
                ]);

                $pItem->update([
                    'status' => 'received',
                    'store_component_id' => $component->id,
                ]);

                if ($eItem) {
                    $eItem->update([
                        'status' => 'received',
                        'store_component_id' => $component->id,
                        'supplier_price' => $pItem->price,
                    ]);
                }
            }

            $purchase->update([
                'status' => 'received',
                'received_at' => now(),
                'notes' => $globalComment !== ''
                    ? trim((string) ($purchase->notes ?? '').($purchase->notes ? "\n" : '').$globalComment)
                    : $purchase->notes,
            ]);

            $this->refreshEstimateReadiness($purchase->estimate);
        });
    }

    /**
     * Привязать позицию сметы к комплектующей со склада (резерв).
     */
    public function linkFromStock(StoreEstimateItem $item, StoreComponent $component): void
    {
        if ($component->club_id !== $item->estimate->club_id) {
            throw new RuntimeException('Комплектующая из другой локации.');
        }
        if ($component->status !== 'in_stock') {
            throw new RuntimeException('Комплектующая не на складе.');
        }

        DB::transaction(function () use ($item, $component) {
            $component->update(['status' => 'reserved']);
            $item->update([
                'status' => 'from_stock',
                'store_component_id' => $component->id,
                'name' => $item->name ?: $component->name,
                'type' => $item->type ?: $component->type,
                'sale_price' => $item->sale_price ?? $component->purchase_price,
            ]);
            $this->refreshEstimateReadiness($item->estimate);
        });
    }

    public function unlinkStock(StoreEstimateItem $item): void
    {
        DB::transaction(function () use ($item) {
            if ($item->store_component_id && in_array($item->status, ['from_stock', 'received'], true)) {
                $component = StoreComponent::query()->lockForUpdate()->find($item->store_component_id);
                if ($component && $component->status === 'reserved') {
                    $component->update(['status' => 'in_stock']);
                }
            }

            $item->update([
                'store_component_id' => null,
                'status' => $item->supplier_sku ? 'to_order' : 'planned',
            ]);
            $this->refreshEstimateReadiness($item->estimate);
        });
    }

    /**
     * Создать заказ магазина из готовой сметы.
     */
    public function convertToOrder(StoreEstimate $estimate, ?int $assigneeId = null): StoreOrder
    {
        if ($estimate->status === 'converted' && $estimate->store_order_id) {
            throw new RuntimeException('Смета уже преобразована в заказ.');
        }

        $items = $estimate->items()->get();
        if ($items->isEmpty()) {
            throw new RuntimeException('В смете нет позиций.');
        }

        foreach ($items as $item) {
            if (! $item->store_component_id || ! in_array($item->status, ['from_stock', 'received'], true)) {
                throw new RuntimeException('Не все позиции обеспечены складом: «'.$item->name.'».');
            }
        }

        return DB::transaction(function () use ($estimate, $assigneeId, $items) {
            $total = 0;
            $lines = [];

            foreach ($items as $item) {
                $component = StoreComponent::query()
                    ->where('club_id', $estimate->club_id)
                    ->whereKey($item->store_component_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                abort_unless(in_array($component->status, ['reserved', 'in_stock'], true), 422, "«{$component->name}» недоступна.");

                $price = (float) ($item->sale_price ?? $component->purchase_price);
                $total += $price;
                $lines[] = compact('component', 'price', 'item');
            }

            $order = StoreOrder::query()->create([
                'club_id' => $estimate->club_id,
                'store_client_id' => $estimate->store_client_id,
                'assignee_id' => $assigneeId,
                'status' => 'new',
                'total' => $total,
                'notes' => trim(($estimate->title ? $estimate->title.' · ' : '').'из сметы #'.$estimate->id.($estimate->notes ? ' · '.$estimate->notes : '')),
            ]);

            foreach ($lines as $line) {
                /** @var StoreComponent $component */
                $component = $line['component'];
                /** @var StoreEstimateItem $item */
                $item = $line['item'];

                StoreOrderItem::query()->create([
                    'store_order_id' => $order->id,
                    'store_component_id' => $component->id,
                    'store_product_id' => null,
                    'name' => $item->name ?: $component->name,
                    'qty' => 1,
                    'price' => $line['price'],
                    'serials' => $component->allSerials() ?: null,
                ]);

                $component->update(['status' => 'sold']);
            }

            $estimate->update([
                'status' => 'converted',
                'store_order_id' => $order->id,
                'sale_total' => $total,
            ]);

            return $order->fresh(['items', 'client']);
        });
    }

    public function refreshEstimateReadiness(?StoreEstimate $estimate): void
    {
        if (! $estimate) {
            return;
        }

        if (in_array($estimate->status, ['converted', 'cancelled'], true)) {
            $estimate->recalculateTotals();

            return;
        }

        $items = $estimate->items()->get();
        if ($items->isEmpty()) {
            $estimate->recalculateTotals();

            return;
        }

        $allReady = $items->every(fn (StoreEstimateItem $i) => in_array($i->status, ['from_stock', 'received'], true) && $i->store_component_id);
        $anyOrdered = $items->contains(fn (StoreEstimateItem $i) => in_array($i->status, ['ordered', 'to_order'], true));

        if ($allReady) {
            $estimate->update(['status' => 'ready']);
        } elseif ($anyOrdered || $estimate->purchases()->whereIn('status', ['submitted', 'confirmed', 'received'])->exists()) {
            $estimate->update(['status' => 'procuring']);
        }

        $estimate->recalculateTotals();
    }

    private function ensureApiSupplier(int $clubId): int
    {
        $supplier = StoreSupplier::query()->firstOrCreate(
            ['club_id' => $clubId, 'name' => 'QuickFox'],
            ['is_active' => true, 'notes' => 'API поставщика']
        );

        return (int) $supplier->id;
    }

    /**
     * warranty из прайса ITP: "12", "36"; "0" у них = 12 мес.; "?" / no_info — неизвестно.
     */
    private function parseWarrantyMonths(mixed $raw): ?int
    {
        if ($raw === null || $raw === '' || $raw === '?' || $raw === 'no_info') {
            return null;
        }
        if (is_int($raw) || is_float($raw)) {
            $n = (int) $raw;
            if ($n === 0) {
                return 12;
            }

            return $n > 0 && $n <= 120 ? $n : null;
        }

        $s = trim((string) $raw);
        if ($s === '' || $s === '?' || strcasecmp($s, 'no_info') === 0) {
            return null;
        }
        if (preg_match('/(\d{1,3})/', $s, $m)) {
            $n = (int) $m[1];
            if ($n === 0) {
                return 12;
            }

            return $n > 0 && $n <= 120 ? $n : null;
        }

        return null;
    }
}
