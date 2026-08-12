<?php

namespace App\Http\Controllers\Admin\Store;

use App\Models\StoreComponent;
use App\Models\StoreSpecDictionary;
use App\Models\StoreSupplier;
use App\Support\StoreComponentSpecs;
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
            'specSchemas' => StoreComponentSpecs::schemas(),
            'specDictionaries' => StoreSpecDictionary::mergedDictionaries($clubId),
            'filters' => ['type' => $type ?: null],
            'canManage' => $admin->canManageStoreCatalog() || $admin->role === 'owner',
            'canReceive' => $admin->canManageStoreInventory()
                || $admin->canManageStoreCatalog()
                || $admin->role === 'owner'
                || $admin->role === 'assembler',
        ]);
    }

    public function suggest(Request $request)
    {
        abort_unless(
            $this->admin()->canAccessStore(),
            403
        );

        $data = $request->validate([
            'type' => 'required|string',
            'field' => 'required|string',
            'q' => 'required|string|min:1|max:64',
        ]);

        $schema = StoreComponentSpecs::schemas()[$data['type']] ?? [];
        $fieldMeta = collect($schema)->firstWhere('key', $data['field']);
        if (! $fieldMeta) {
            return response()->json(['items' => []]);
        }

        $dictKey = $fieldMeta['suggest'];
        $clubId = $this->locationId();
        $history = StoreComponent::query()
            ->where('club_id', $clubId)
            ->where('type', $data['type'])
            ->whereNotNull('specs')
            ->latest()
            ->limit(200)
            ->pluck('specs')
            ->flatMap(function ($specs) use ($data) {
                $val = is_array($specs) ? ($specs[$data['field']] ?? null) : null;

                return $val ? [$val] : [];
            })
            ->merge(StoreSpecDictionary::valuesFor($clubId, $dictKey))
            ->unique()
            ->values()
            ->all();

        return response()->json([
            'items' => StoreComponentSpecs::suggest($dictKey, $data['q'], $history),
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

        $specs = $data['specs'] ?? [];
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $name = StoreComponentSpecs::composeName($data['type'], $specs);
        }
        abort_if($name === '', 422, 'Заполните поля конструктора или название.');

        [$warrantyNumber, $serials] = $this->normalizeSerials($data);

        StoreComponent::query()->create([
            'club_id' => $clubId,
            'store_supplier_id' => $data['store_supplier_id'] ?? null,
            'received_by' => $data['received_by'] ?? $this->admin()->id,
            'name' => $name,
            'original_name' => trim((string) ($data['original_name'] ?? '')) ?: null,
            'type' => $data['type'],
            'specs' => $specs,
            'purchase_price' => $data['purchase_price'],
            'warranty_number' => $warrantyNumber,
            'serials' => $serials,
            'warranty_months' => $data['warranty_months'] ?? null,
            'qty' => $data['qty'] ?? 1,
            'status' => $data['status'] ?? 'in_stock',
            'notes' => $data['notes'] ?? null,
        ]);

        StoreSpecDictionary::rememberFromSpecs($clubId, $data['type'], $specs);

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

        $specs = $data['specs'] ?? [];
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $name = StoreComponentSpecs::composeName($data['type'], $specs);
        }

        [$warrantyNumber, $serials] = $this->normalizeSerials($data);

        $storeComponent->update([
            'store_supplier_id' => $data['store_supplier_id'] ?? null,
            'name' => $name !== '' ? $name : $storeComponent->name,
            'original_name' => array_key_exists('original_name', $data)
                ? (trim((string) ($data['original_name'] ?? '')) ?: null)
                : $storeComponent->original_name,
            'type' => $data['type'],
            'specs' => $specs,
            'purchase_price' => $data['purchase_price'],
            'warranty_number' => $warrantyNumber,
            'serials' => $serials,
            'warranty_months' => $data['warranty_months'] ?? null,
            'qty' => $data['qty'] ?? $storeComponent->qty,
            'status' => $data['status'] ?? $storeComponent->status,
            'notes' => $data['notes'] ?? null,
        ]);

        StoreSpecDictionary::rememberFromSpecs($this->locationId(), $data['type'], $specs);

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
            'name' => 'nullable|string|max:255',
            'original_name' => 'nullable|string|max:255',
            'type' => "required|in:{$typeKeys}",
            'specs' => 'nullable|array',
            'specs.*' => 'nullable|string|max:128',
            'store_supplier_id' => 'nullable|integer',
            'purchase_price' => 'required|numeric|min:0',
            'warranty_number' => 'nullable|string|max:128',
            'serials' => 'nullable|array',
            'serials.*' => 'nullable|string|max:128',
            'warranty_months' => 'nullable|integer|min:0|max:120',
            'qty' => 'nullable|integer|min:1',
            'status' => $updating ? "nullable|in:{$statusKeys}" : "nullable|in:{$statusKeys}",
            'received_by' => 'nullable|integer',
            'notes' => 'nullable|string|max:2000',
        ]);
    }

    /**
     * @return array{0:?string,1:list<string>}
     */
    private function normalizeSerials(array $data): array
    {
        $serials = collect($data['serials'] ?? [])
            ->map(fn ($s) => trim((string) $s))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($serials === [] && ! empty($data['warranty_number'])) {
            $single = trim((string) $data['warranty_number']);
            if ($single !== '') {
                $serials = [$single];
            }
        }

        return [$serials[0] ?? null, $serials];
    }
}
