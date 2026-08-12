<?php

namespace App\Http\Controllers\Admin\Store;

use App\Models\StoreComponent;
use App\Models\StoreSupplier;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WarehouseController extends StoreController
{
    public function index(Request $request)
    {
        $admin = $this->admin();
        $clubId = $this->locationId();
        $type = $request->string('type')->toString();

        $query = StoreComponent::query()
            ->where('club_id', $clubId)
            ->with(['supplier:id,name', 'receiver:id,name'])
            ->latest();

        if ($type && isset(StoreComponent::TYPES[$type])) {
            $query->where('type', $type);
        }

        return Inertia::render('Admin/Store/Warehouse', [
            'components' => $query->limit(300)->get(),
            'suppliers' => StoreSupplier::query()
                ->where('club_id', $clubId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'types' => StoreComponent::TYPES,
            'statuses' => StoreComponent::STATUSES,
            'filters' => ['type' => $type ?: null],
            'canManage' => $admin->canManageStoreCatalog() || $admin->role === 'owner',
            'canReceive' => $admin->canManageStoreInventory()
                || $admin->canManageStoreCatalog()
                || $admin->role === 'owner'
                || $admin->role === 'assembler',
        ]);
    }

    public function store(Request $request)
    {
        abort_unless(
            $this->admin()->canManageStoreCatalog()
            || $this->admin()->canManageStoreInventory()
            || $this->admin()->role === 'owner',
            403
        );

        $data = $this->validated($request);
        $clubId = $this->locationId();

        if (! empty($data['store_supplier_id'])) {
            StoreSupplier::query()->where('club_id', $clubId)->whereKey($data['store_supplier_id'])->firstOrFail();
        }

        StoreComponent::query()->create([
            ...$data,
            'club_id' => $clubId,
            'received_by' => $data['received_by'] ?? $this->admin()->id,
            'status' => $data['status'] ?? 'in_stock',
            'qty' => $data['qty'] ?? 1,
        ]);

        return back()->with('success', 'Комплектующее добавлено на склад.');
    }

    public function update(Request $request, StoreComponent $storeComponent)
    {
        abort_unless($this->admin()->canManageStoreCatalog() || $this->admin()->role === 'owner', 403);
        abort_unless($storeComponent->club_id === $this->locationId(), 404);

        $data = $this->validated($request, updating: true);

        if (! empty($data['store_supplier_id'])) {
            StoreSupplier::query()
                ->where('club_id', $this->locationId())
                ->whereKey($data['store_supplier_id'])
                ->firstOrFail();
        }

        $storeComponent->update($data);

        return back()->with('success', 'Комплектующее обновлено.');
    }

    public function destroy(StoreComponent $storeComponent)
    {
        abort_unless($this->admin()->canManageStoreCatalog() || $this->admin()->role === 'owner', 403);
        abort_unless($storeComponent->club_id === $this->locationId(), 404);

        $storeComponent->delete();

        return back()->with('success', 'Комплектующее удалено.');
    }

    public function storeSupplier(Request $request)
    {
        abort_unless($this->admin()->canManageStoreCatalog() || $this->admin()->role === 'owner', 403);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:64',
            'notes' => 'nullable|string|max:1000',
        ]);

        StoreSupplier::query()->create([
            ...$data,
            'club_id' => $this->locationId(),
            'is_active' => true,
        ]);

        return back()->with('success', 'Поставщик добавлен.');
    }

    /**
     * HID-сканер: известный штрихкод → +qty, неизвестный → 404 (UI откроет форму прихода).
     */
    public function receiveScan(Request $request)
    {
        abort_unless(
            $this->admin()->canManageStoreCatalog()
            || $this->admin()->canManageStoreInventory()
            || $this->admin()->role === 'owner'
            || $this->admin()->role === 'assembler',
            403
        );

        $data = $request->validate([
            'code' => 'required|string|max:128',
            'store_component_id' => 'nullable|integer',
            'qty' => 'nullable|integer|min:1',
        ]);

        $clubId = $this->locationId();
        $code = trim($data['code']);
        $inc = (int) ($data['qty'] ?? 1);

        $component = null;
        if (! empty($data['store_component_id'])) {
            $component = StoreComponent::query()
                ->where('club_id', $clubId)
                ->whereKey($data['store_component_id'])
                ->firstOrFail();
        } else {
            $component = StoreComponent::query()
                ->where('club_id', $clubId)
                ->where('barcode', $code)
                ->first();
        }

        if (! $component) {
            return response()->json([
                'message' => 'Штрихкод не найден на складе комплектующих',
                'code' => $code,
            ], 404);
        }

        $component->update([
            'qty' => (int) $component->qty + $inc,
            'received_by' => $this->admin()->id,
            'status' => $component->status === 'written_off' ? 'in_stock' : $component->status,
        ]);

        if (! $component->barcode) {
            $component->update(['barcode' => $code]);
        }

        $component->load(['supplier:id,name', 'receiver:id,name']);

        return response()->json([
            'status' => 'received',
            'component' => $component->fresh(['supplier:id,name', 'receiver:id,name']),
            'added' => $inc,
        ]);
    }

    private function validated(Request $request, bool $updating = false): array
    {
        $typeKeys = implode(',', array_keys(StoreComponent::TYPES));
        $statusKeys = implode(',', array_keys(StoreComponent::STATUSES));

        return $request->validate([
            'name' => 'required|string|max:255',
            'barcode' => 'nullable|string|max:128',
            'type' => "required|in:{$typeKeys}",
            'store_supplier_id' => 'nullable|integer',
            'purchase_price' => 'required|numeric|min:0',
            'warranty_number' => 'nullable|string|max:128',
            'warranty_months' => 'nullable|integer|min:0|max:120',
            'qty' => 'nullable|integer|min:1',
            'status' => $updating ? "nullable|in:{$statusKeys}" : "nullable|in:{$statusKeys}",
            'received_by' => 'nullable|integer',
            'notes' => 'nullable|string|max:2000',
        ]);
    }
}
