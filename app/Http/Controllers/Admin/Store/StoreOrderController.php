<?php

namespace App\Http\Controllers\Admin\Store;

use App\Models\Admin;
use App\Models\StoreClient;
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
            ->with(['client:id,name,phone', 'assignee:id,name', 'items'])
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

        return Inertia::render('Admin/Store/Orders', [
            'orders' => $query->limit(100)->get(),
            'clients' => StoreClient::query()->where('club_id', $this->locationId())->orderBy('name')->get(['id', 'name', 'phone']),
            'products' => StoreProduct::query()
                ->where('club_id', $this->locationId())
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'sku', 'price', 'stock', 'serial_tracked']),
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
            'items.*.store_product_id' => 'required|integer',
            'items.*.qty' => 'required|integer|min:1',
            'items.*.serials' => 'nullable|array',
        ]);

        $clubId = $this->locationId();

        if (! empty($data['store_client_id'])) {
            StoreClient::query()->where('club_id', $clubId)->whereKey($data['store_client_id'])->firstOrFail();
        }

        DB::transaction(function () use ($data, $clubId) {
            $total = 0;
            $lines = [];

            foreach ($data['items'] as $item) {
                $product = StoreProduct::query()
                    ->where('club_id', $clubId)
                    ->whereKey($item['store_product_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                abort_if($product->stock < $item['qty'], 422, "Недостаточно «{$product->name}» на складе.");

                $lineTotal = (float) $product->price * $item['qty'];
                $total += $lineTotal;
                $lines[] = compact('product', 'item', 'lineTotal');
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
                /** @var StoreProduct $product */
                $product = $line['product'];
                $item = $line['item'];

                StoreOrderItem::query()->create([
                    'store_order_id' => $order->id,
                    'store_product_id' => $product->id,
                    'name' => $product->name,
                    'qty' => $item['qty'],
                    'price' => $product->price,
                    'serials' => $item['serials'] ?? null,
                ]);

                $newStock = (int) $product->stock - (int) $item['qty'];
                $product->update(['stock' => $newStock]);

                StoreStockMovement::query()->create([
                    'club_id' => $clubId,
                    'store_product_id' => $product->id,
                    'admin_id' => $this->admin()->id,
                    'type' => 'sale',
                    'qty' => -(int) $item['qty'],
                    'stock_after' => $newStock,
                    'reason' => 'Заказ #'.$order->id,
                ]);
            }
        });

        return back()->with('success', 'Заказ создан.');
    }

    public function updateStatus(Request $request, StoreOrder $storeOrder)
    {
        abort_unless($storeOrder->club_id === $this->locationId(), 404);

        $data = $request->validate([
            'status' => 'required|in:new,assembling,ready,issued,cancelled,returned',
        ]);

        $admin = $this->admin();
        $next = $data['status'];

        if (in_array($next, ['cancelled', 'returned'], true)) {
            abort_unless($admin->canCancelStoreOrders(), 403);
        }

        // Сборщик двигает только сборку
        if ($admin->role === 'assembler') {
            abort_unless(in_array($next, ['assembling', 'ready'], true), 403);
            if (! $storeOrder->assignee_id) {
                $storeOrder->assignee_id = $admin->id;
            }
        }

        $prev = $storeOrder->status;
        $storeOrder->status = $next;
        $storeOrder->save();

        if ($next === 'returned' && $prev !== 'returned') {
            DB::transaction(fn () => $this->restockOrder($storeOrder));
        }

        return back()->with('success', 'Статус заказа обновлён.');
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

    private function restockOrder(StoreOrder $order): void
    {
        $order->load('items');
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
