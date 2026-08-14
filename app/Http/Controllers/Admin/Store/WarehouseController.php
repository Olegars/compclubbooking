<?php

namespace App\Http\Controllers\Admin\Store;

use App\Models\StoreBuiltPc;
use App\Models\StoreComponent;
use App\Models\StoreSpecDictionary;
use App\Models\StoreSupplier;
use App\Support\StoreComponentSpecs;
use Carbon\Carbon;
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
            ->with([
                'supplier:id,name',
                'receiver:id,name',
                'orderItems.order.client:id,name,phone',
                'orderItems.order.assignee:id,name',
                'orderItems.order.builtPc:id,store_order_id,title,serial_number,sold_at,status,store_client_id',
                'builtPcs' => fn ($q) => $q->with([
                    'client:id,name,phone',
                    'issuer:id,name',
                    'assembler:id,name',
                    'order.assignee:id,name',
                ]),
            ])
            ->latest();

        if ($type && isset(StoreComponent::TYPES[$type])) {
            $query->where('type', $type);
        }

        $components = $query->limit(300)->get()->map(fn (StoreComponent $c) => $this->presentComponent($c))->values();

        return Inertia::render('Admin/Store/Warehouse', [
            'components' => $components,
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
            'qty' => 1,
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
        abort_if($storeComponent->status === 'sold', 422, 'Проданное комплектующее нельзя редактировать.');

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
            'qty' => 1,
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
        abort_if($storeComponent->status === 'sold', 422, 'Проданное комплектующее нельзя удалить.');

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

    /**
     * @return array<string, mixed>
     */
    private function presentComponent(StoreComponent $c): array
    {
        $receivedAt = $c->created_at;
        $warranty = $this->warrantyInfo($receivedAt, $c->warranty_months);
        $sale = $this->saleInfo($c);

        return [
            'id' => $c->id,
            'name' => $c->name,
            'original_name' => $c->original_name,
            'type' => $c->type,
            'store_supplier_id' => $c->store_supplier_id,
            'purchase_price' => $c->purchase_price,
            'warranty_number' => $c->warranty_number,
            'serials' => $c->serials,
            'warranty_months' => $c->warranty_months,
            'qty' => 1,
            'status' => $c->status,
            'notes' => $c->notes,
            'specs' => $c->specs,
            'supplier' => $c->supplier ? ['id' => $c->supplier->id, 'name' => $c->supplier->name] : null,
            'receiver' => $c->receiver ? ['id' => $c->receiver->id, 'name' => $c->receiver->name] : null,
            'received_at' => $receivedAt?->toIso8601String(),
            'warranty_ends_at' => $warranty['ends_at'],
            'warranty_state' => $warranty['state'],
            'warranty_label' => $warranty['label'],
            'sale' => $sale,
        ];
    }

    /**
     * @return array{ends_at:?string,state:string,label:?string}
     */
    private function warrantyInfo(?Carbon $receivedAt, mixed $months): array
    {
        $m = $months !== null ? (int) $months : 0;
        if (! $receivedAt || $m <= 0) {
            return ['ends_at' => null, 'state' => 'none', 'label' => null];
        }

        $ends = $receivedAt->copy()->addMonthsNoOverflow($m)->startOfDay();
        $today = now()->startOfDay();

        if ($ends->lt($today)) {
            $ago = (int) $ends->diffInDays($today);

            return [
                'ends_at' => $ends->toIso8601String(),
                'state' => 'expired',
                'label' => $ago === 0
                    ? 'Гарантия истекла сегодня'
                    : 'Гарантия истекла '.$this->daysRu($ago).' назад',
            ];
        }

        $days = (int) $today->diffInDays($ends);
        if ($days === 0) {
            return [
                'ends_at' => $ends->toIso8601String(),
                'state' => 'expiring',
                'label' => 'Гарантия истекает сегодня',
            ];
        }

        return [
            'ends_at' => $ends->toIso8601String(),
            'state' => $days <= 30 ? 'expiring' : 'active',
            'label' => 'Гарантия истекает через '.$this->daysRu($days),
        ];
    }

    private function daysRu(int $n): string
    {
        $n = abs($n);
        $mod10 = $n % 10;
        $mod100 = $n % 100;
        if ($mod10 === 1 && $mod100 !== 11) {
            return $n.' день';
        }
        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            return $n.' дня';
        }

        return $n.' дней';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function saleInfo(StoreComponent $c): ?array
    {
        /** @var StoreBuiltPc|null $pc */
        $pc = $c->builtPcs->sortByDesc(fn (StoreBuiltPc $p) => $p->sold_at?->timestamp ?? $p->updated_at?->timestamp ?? 0)->first();

        $orderItem = $c->orderItems->sortByDesc('id')->first();
        $order = $orderItem?->order;

        if (! $pc && ! $order && ! in_array($c->status, ['sold', 'used'], true)) {
            return null;
        }

        $client = $pc?->client ?: $order?->client;
        $soldBy = $pc?->issuer
            ?: $pc?->assembler
            ?: $order?->assignee;
        $soldAt = $pc?->sold_at
            ?: ($order && $order->status === 'issued' ? ($order->updated_at ?: $orderItem?->created_at) : null)
            ?: ($c->status === 'sold' ? ($orderItem?->updated_at ?: $c->updated_at) : null);

        $buildTitle = null;
        if ($pc) {
            $buildTitle = trim((string) ($pc->title ?: ''));
            if ($buildTitle === '') {
                $buildTitle = $pc->serial_number ? 'Сборка S/N '.$pc->serial_number : 'Сборка #'.$pc->id;
            } elseif ($pc->serial_number) {
                $buildTitle .= ' · S/N '.$pc->serial_number;
            }
        }

        return [
            'client_name' => $client?->name,
            'client_phone' => $client?->phone,
            'sold_by' => $soldBy?->name,
            'sold_at' => $soldAt?->toIso8601String(),
            'order_id' => $order?->id ?? $pc?->store_order_id,
            'built_pc_id' => $pc?->id,
            'built_pc_title' => $buildTitle,
            'built_pc_status' => $pc?->status,
        ];
    }
}
