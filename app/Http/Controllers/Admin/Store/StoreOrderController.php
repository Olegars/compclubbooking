<?php

namespace App\Http\Controllers\Admin\Store;

use App\Models\Admin;
use App\Models\StoreClient;
use App\Models\StoreComponent;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\StoreProduct;
use App\Models\StoreStockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class StoreOrderController extends StoreController
{
    public function index(Request $request)
    {
        $status = $request->string('status')->toString();
        $query = StoreOrder::query()
            ->where('club_id', $this->locationId())
            ->with(['client:id,name,phone', 'assignee:id,name', 'items.component:id,type,warranty_number,serials,status'])
            ->latest();

        if ($status && in_array($status, StoreOrder::STATUSES, true)) {
            $query->where('status', $status);
        }

        $assemblers = Admin::query()
            ->whereIn('role', ['assembler', 'store_manager', 'senior_manager'])
            ->where(function ($q) {
                $q->where('club_id', $this->locationId())->orWhereNull('club_id');
            })
            ->orderBy('name')
            ->get(['id', 'name', 'role']);

        $components = StoreComponent::query()
            ->where('club_id', $this->locationId())
            ->where('status', 'in_stock')
            ->orderBy('type')
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'purchase_price', 'warranty_number', 'serials', 'qty', 'status']);

        return Inertia::render('Admin/Store/Orders', [
            'orders' => $query->limit(100)->get(),
            'clients' => StoreClient::query()->where('club_id', $this->locationId())->orderBy('name')->get(['id', 'name', 'phone']),
            'components' => $components,
            'componentTypes' => StoreComponent::TYPES,
            'assemblers' => $assemblers,
            'statuses' => StoreOrder::STATUSES,
            'filters' => ['status' => $status ?: null],
            'canCreate' => $this->admin()->canManageStoreCatalog() || $this->admin()->role === 'owner',
            'canCancel' => $this->admin()->canCancelStoreOrders(),
            'canAssign' => $this->admin()->canManageStoreCatalog() || $this->admin()->role === 'owner',
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($this->admin()->canManageStoreCatalog() || $this->admin()->role === 'owner', 403);

        $data = $request->validate([
            'store_client_id' => 'nullable|integer',
            'notes' => 'nullable|string|max:2000',
            'assignee_id' => 'nullable|integer',
            'items' => 'required|array|min:1',
            'items.*.store_component_id' => 'required|integer',
            'items.*.qty' => 'nullable|integer|min:1',
        ]);

        $clubId = $this->locationId();

        if (! empty($data['store_client_id'])) {
            StoreClient::query()->where('club_id', $clubId)->whereKey($data['store_client_id'])->firstOrFail();
        }

        // Одна комплектующая — не больше одного раза в заказе
        $ids = collect($data['items'])->pluck('store_component_id')->map(fn ($id) => (int) $id);
        abort_if($ids->count() !== $ids->unique()->count(), 422, 'Одна комплектующая указана дважды.');

        DB::transaction(function () use ($data, $clubId) {
            $total = 0;
            $lines = [];

            foreach ($data['items'] as $item) {
                $component = StoreComponent::query()
                    ->where('club_id', $clubId)
                    ->whereKey($item['store_component_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                abort_unless($component->status === 'in_stock', 422, "«{$component->name}» не на складе.");

                $qty = 1; // уникальные позиции со склада
                $price = (float) $component->purchase_price;
                $total += $price * $qty;
                $lines[] = compact('component', 'qty', 'price');
            }

            $order = StoreOrder::query()->create([
                'club_id' => $clubId,
                'store_client_id' => $data['store_client_id'] ?? null,
                'assignee_id' => $data['assignee_id'] ?? null,
                'status' => 'new',
                'total' => $total,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($lines as $line) {
                /** @var StoreComponent $component */
                $component = $line['component'];

                StoreOrderItem::query()->create([
                    'store_order_id' => $order->id,
                    'store_component_id' => $component->id,
                    'store_product_id' => null,
                    'name' => $component->name,
                    'qty' => $line['qty'],
                    'price' => $line['price'],
                    'serials' => $component->allSerials() ?: null,
                ]);

                $component->update(['status' => 'sold']);
            }
        });

        return back()->with('success', 'Заказ создан.');
    }

    public function destroyItem(StoreOrder $storeOrder, StoreOrderItem $item)
    {
        abort_unless($this->admin()->canManageStoreCatalog() || $this->admin()->role === 'owner', 403);
        abort_unless($storeOrder->club_id === $this->locationId(), 404);
        abort_unless($item->store_order_id === $storeOrder->id, 404);
        abort_if(in_array($storeOrder->status, ['cancelled', 'returned', 'issued'], true), 422, 'Заказ уже закрыт или выдан.');

        DB::transaction(function () use ($storeOrder, $item) {
            $this->returnComponentToStock($item);
            $item->delete();

            $storeOrder->load('items');
            $storeOrder->update([
                'total' => $storeOrder->items->sum(fn (StoreOrderItem $i) => (float) $i->price * (int) $i->qty),
            ]);
        });

        return back()->with('success', 'Позиция удалена, комплектующая возвращена на склад.');
    }

    public function updateStatus(Request $request, StoreOrder $storeOrder)
    {
        abort_unless($storeOrder->club_id === $this->locationId(), 404);

        $data = $request->validate([
            'status' => 'required|in:new,assembling,ready,issued,cancelled,returned',
        ]);

        $admin = $this->admin();
        $prev = $storeOrder->status;
        $next = $data['status'];

        $allowed = $this->allowedTransitions($prev);
        abort_unless(in_array($next, $allowed, true), 422, 'Нельзя перевести заказ из «'.$prev.'» в «'.$next.'».');

        if (in_array($next, ['cancelled', 'returned'], true)) {
            abort_unless($admin->canCancelStoreOrders(), 403);
        }

        if ($admin->role === 'assembler') {
            abort_unless(in_array($next, ['assembling', 'ready'], true), 403);
            if (! $storeOrder->assignee_id) {
                $storeOrder->assignee_id = $admin->id;
            }
        }

        DB::transaction(function () use ($storeOrder, $next, $prev) {
            $storeOrder->status = $next;
            $storeOrder->save();

            // Отмена (до выдачи) или возврат (после выдачи) — комплектующие на склад
            if (in_array($next, ['cancelled', 'returned'], true) && ! in_array($prev, ['cancelled', 'returned'], true)) {
                $storeOrder->load('items');
                foreach ($storeOrder->items as $item) {
                    $this->returnComponentToStock($item);
                }
                $this->restockProducts($storeOrder);
            }
        });

        return back()->with('success', 'Статус заказа обновлён.');
    }

    /**
     * Цепочка: new → assembling → ready → issued.
     * cancelled — тупик (склад уже вернули).
     * returned — только после issued (клиент вернул после выдачи).
     *
     * @return list<string>
     */
    private function allowedTransitions(string $from): array
    {
        return match ($from) {
            'new' => ['assembling', 'cancelled'],
            'assembling' => ['ready', 'cancelled'],
            'ready' => ['issued', 'cancelled'],
            'issued' => ['returned'],
            'cancelled', 'returned' => [],
            default => [],
        };
    }

    public function assign(Request $request, StoreOrder $storeOrder)
    {
        abort_unless($this->admin()->canManageStoreCatalog() || $this->admin()->role === 'owner', 403);
        abort_unless($storeOrder->club_id === $this->locationId(), 404);

        $data = $request->validate([
            'assignee_id' => 'nullable|integer',
        ]);

        if (! empty($data['assignee_id'])) {
            Admin::query()->whereKey($data['assignee_id'])->firstOrFail();
        }

        $storeOrder->update(['assignee_id' => $data['assignee_id'] ?? null]);

        return back()->with('success', 'Сборщик назначен.');
    }

    private function returnComponentToStock(StoreOrderItem $item): void
    {
        if (! $item->store_component_id) {
            return;
        }

        $component = StoreComponent::query()->whereKey($item->store_component_id)->lockForUpdate()->first();
        if (! $component) {
            return;
        }

        if (in_array($component->status, ['sold', 'reserved', 'used'], true)) {
            $component->update(['status' => 'in_stock']);
        }
    }

    private function restockProducts(StoreOrder $order): void
    {
        foreach ($order->items as $item) {
            if (! $item->store_product_id) {
                continue;
            }
            $product = StoreProduct::query()->whereKey($item->store_product_id)->lockForUpdate()->first();
            if (! $product) {
                continue;
            }
            $newStock = (int) $product->stock + (int) $item->qty;
            $product->update(['stock' => $newStock]);
            StoreStockMovement::query()->create([
                'club_id' => $order->club_id,
                'store_product_id' => $product->id,
                'admin_id' => $this->admin()->id,
                'type' => 'return',
                'qty' => (int) $item->qty,
                'stock_after' => $newStock,
                'reason' => 'Возврат заказа #'.$order->id,
            ]);
        }
    }
}
