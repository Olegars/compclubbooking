<?php

namespace App\Services;

use App\Models\StoreBuiltPc;
use App\Models\StoreBuiltPcComponent;
use App\Models\StoreComponent;
use App\Models\StoreOrder;
use Illuminate\Support\Facades\DB;

class StoreOrderBuiltPcService
{
    public function __construct(private StoreWarrantyService $warranties) {}

    /**
     * Создать / обновить карточку «Готовый ПК» из заказа.
     * Вызывать на этапах assembling / ready / issued.
     */
    public function ensureFromOrder(StoreOrder $order, ?int $adminId = null): StoreBuiltPc
    {
        return DB::transaction(function () use ($order, $adminId) {
            $order->refresh();
            $order->loadMissing(['items.component', 'client']);

            $pc = StoreBuiltPc::query()
                ->where('store_order_id', $order->id)
                ->lockForUpdate()
                ->first();

            $pcStatus = $this->mapStatus($order->status);

            if (! $pc) {
                $title = 'Заказ #'.$order->id;
                if ($order->client?->name) {
                    $title .= ' · '.$order->client->name;
                }

                $pc = StoreBuiltPc::query()->create([
                    'club_id' => $order->club_id,
                    'store_order_id' => $order->id,
                    'store_client_id' => $order->store_client_id,
                    'assembled_by' => $order->assignee_id ?: $adminId,
                    'title' => $title,
                    'sale_price' => $order->total,
                    'sale_tax_mode' => 'with_tax',
                    'status' => $pcStatus,
                    'sold_at' => $order->status === 'issued' ? now() : null,
                    'issued_by' => $order->status === 'issued' ? ($adminId ?: $order->assignee_id) : null,
                    'notes' => $order->notes,
                ]);
            } else {
                $pc->update([
                    'store_client_id' => $order->store_client_id,
                    'assembled_by' => $pc->assembled_by ?: ($order->assignee_id ?: $adminId),
                    'sale_price' => $order->total,
                    'status' => $pcStatus,
                    'sold_at' => $order->status === 'issued'
                        ? ($pc->sold_at ?: now())
                        : $pc->sold_at,
                    'issued_by' => $order->status === 'issued'
                        ? ($pc->issued_by ?: ($adminId ?: $order->assignee_id))
                        : $pc->issued_by,
                    'notes' => $order->notes ?: $pc->notes,
                ]);
            }

            $this->syncLinksFromOrder($pc, $order);
            $this->warranties->ensureForBuiltPc($pc->fresh(['componentLinks', 'client']));

            return $pc->fresh([
                'componentLinks',
                'components',
                'client',
                'warranty',
            ]);
        });
    }

    public function cancelFromOrder(StoreOrder $order): void
    {
        $pc = StoreBuiltPc::query()->where('store_order_id', $order->id)->first();
        if (! $pc) {
            return;
        }

        $pc->update(['status' => 'cancelled']);
    }

    private function mapStatus(string $orderStatus): string
    {
        return match ($orderStatus) {
            'ready' => 'ready',
            'issued' => 'sold',
            'cancelled', 'returned' => 'cancelled',
            default => 'assembling', // assembling / new
        };
    }

    private function syncLinksFromOrder(StoreBuiltPc $pc, StoreOrder $order): void
    {
        $wanted = [];
        $spec = [];

        foreach ($order->items as $item) {
            if (! $item->store_component_id) {
                continue;
            }
            $wanted[] = (int) $item->store_component_id;
            /** @var StoreComponent|null $c */
            $c = $item->component;
            $spec[] = [
                'component_id' => $item->store_component_id,
                'type' => $c?->type,
                'name' => $item->name,
                'warranty_number' => $c?->warranty_number,
                'serials' => $c?->allSerials() ?? [],
                'original_name' => $c?->original_name,
                'source' => 'order',
            ];
        }

        $wanted = array_values(array_unique($wanted));
        $existing = $pc->componentLinks()->pluck('store_component_id')->filter()->map(fn ($id) => (int) $id)->all();

        // Удалить лишние связи (если из заказа убрали позицию)
        foreach ($pc->componentLinks as $link) {
            if ($link->store_component_id && ! in_array((int) $link->store_component_id, $wanted, true)) {
                $link->delete();
            }
        }

        foreach ($wanted as $componentId) {
            if (in_array($componentId, $existing, true)) {
                continue;
            }
            $c = StoreComponent::query()->find($componentId);
            StoreBuiltPcComponent::query()->create([
                'store_built_pc_id' => $pc->id,
                'store_component_id' => $componentId,
                'type' => $c?->type,
                'name' => $c?->name ?? ('#'.$componentId),
            ]);
        }

        // В сборке — used; после выдачи остаётся sold
        $compStatus = $order->status === 'issued' ? 'sold' : 'used';
        if ($wanted !== []) {
            StoreComponent::query()
                ->whereIn('id', $wanted)
                ->whereIn('status', ['sold', 'in_stock', 'reserved', 'used'])
                ->update(['status' => $compStatus]);
        }

        $pc->update(['build_spec' => $spec]);
    }
}
