<?php

namespace App\Http\Controllers\Admin\Store;

use App\Models\StoreProduct;
use App\Models\StoreStockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class WarehouseController extends StoreController
{
    public function index()
    {
        $admin = $this->admin();
        $products = StoreProduct::query()
            ->where('club_id', $this->locationId())
            ->orderBy('name')
            ->get();

        $movements = StoreStockMovement::query()
            ->where('club_id', $this->locationId())
            ->with(['product:id,name,sku', 'admin:id,name'])
            ->latest()
            ->limit(40)
            ->get();

        return Inertia::render('Admin/Store/Warehouse', [
            'products' => $products,
            'movements' => $movements,
            'categories' => StoreProduct::CATEGORIES,
            'canManageCatalog' => $admin->canManageStoreCatalog(),
            'canAdjustStock' => $admin->canManageStoreInventory() || $admin->role === 'assembler',
            'canInventory' => in_array($admin->role, ['senior_manager', 'owner'], true),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($this->admin()->canManageStoreCatalog(), 403);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'sku' => 'nullable|string|max:64',
            'category' => 'required|in:component,pc,peripheral,service',
            'price' => 'required|numeric|min:0',
            'cost' => 'nullable|numeric|min:0',
            'stock' => 'nullable|integer|min:0',
            'serial_tracked' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $data['club_id'] = $this->locationId();
        $data['stock'] = $data['stock'] ?? 0;
        $data['serial_tracked'] = $request->boolean('serial_tracked');
        $data['is_active'] = $request->boolean('is_active', true);

        StoreProduct::query()->create($data);

        return back()->with('success', 'Товар добавлен на склад магазина.');
    }

    public function update(Request $request, StoreProduct $storeProduct)
    {
        abort_unless($this->admin()->canManageStoreCatalog(), 403);
        abort_unless($storeProduct->club_id === $this->locationId(), 404);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'sku' => 'nullable|string|max:64',
            'category' => 'required|in:component,pc,peripheral,service',
            'price' => 'required|numeric|min:0',
            'cost' => 'nullable|numeric|min:0',
            'serial_tracked' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $storeProduct->update([
            ...$data,
            'serial_tracked' => $request->boolean('serial_tracked'),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return back()->with('success', 'Товар обновлён.');
    }

    public function destroy(StoreProduct $storeProduct)
    {
        abort_unless($this->admin()->canManageStoreCatalog(), 403);
        abort_unless($storeProduct->club_id === $this->locationId(), 404);

        $storeProduct->delete();

        return back()->with('success', 'Товар удалён.');
    }

    public function adjust(Request $request)
    {
        $admin = $this->admin();
        abort_unless($admin->canManageStoreInventory() || $admin->role === 'assembler', 403);

        $data = $request->validate([
            'store_product_id' => 'required|integer',
            'type' => 'required|in:receive,write_off,inventory',
            'qty' => 'required|integer|min:1',
            'reason' => 'nullable|string|max:500',
        ]);

        if ($data['type'] === 'inventory') {
            abort_unless(in_array($admin->role, ['senior_manager', 'owner'], true), 403);
        }

        if ($data['type'] === 'write_off' && $admin->role === 'assembler') {
            abort(403, 'Сборщик не может списывать со склада.');
        }

        $product = StoreProduct::query()
            ->where('club_id', $this->locationId())
            ->whereKey($data['store_product_id'])
            ->firstOrFail();

        DB::transaction(function () use ($product, $data, $admin) {
            $delta = match ($data['type']) {
                'receive' => $data['qty'],
                'write_off' => -$data['qty'],
                'inventory' => $data['qty'] - (int) $product->stock,
            };

            $newStock = (int) $product->stock + $delta;
            abort_if($newStock < 0, 422, 'Недостаточно остатка.');

            $product->update(['stock' => $newStock]);

            StoreStockMovement::query()->create([
                'club_id' => $product->club_id,
                'store_product_id' => $product->id,
                'admin_id' => $admin->id,
                'type' => $data['type'],
                'qty' => $delta,
                'stock_after' => $newStock,
                'reason' => $data['reason'] ?? null,
            ]);
        });

        return back()->with('success', 'Остаток обновлён.');
    }
}
