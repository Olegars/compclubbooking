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

    private function validated(Request $request, bool $updating = false): array
    {
        $typeKeys = implode(',', array_keys(StoreComponent::TYPES));
        $statusKeys = implode(',', array_keys(StoreComponent::STATUSES));

        return $request->validate([
            'name' => 'required|string|max:255',
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
