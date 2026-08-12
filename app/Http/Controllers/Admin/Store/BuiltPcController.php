<?php

namespace App\Http\Controllers\Admin\Store;

use App\Models\Admin;
use App\Models\StoreBuiltPc;
use App\Models\StoreBuiltPcComponent;
use App\Models\StoreClient;
use App\Models\StoreComponent;
use App\Services\StoreWarrantyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class BuiltPcController extends StoreController
{
    public function index(Request $request)
    {
        $admin = $this->admin();
        $clubId = $this->locationId();
        $status = $request->string('status')->toString();

        $query = StoreBuiltPc::query()
            ->where('club_id', $clubId)
            ->with([
                'client:id,name,phone',
                'assembler:id,name',
                'acceptor:id,name',
                'issuer:id,name',
                'componentLinks',
                'warranty:id,store_built_pc_id,serial,status,ends_at',
            ])
            ->latest();

        if ($status && isset(StoreBuiltPc::STATUSES[$status])) {
            $query->where('status', $status);
        }

        $staff = Admin::query()
            ->whereIn('role', ['assembler', 'store_manager', 'senior_manager', 'owner'])
            ->where(function ($q) use ($clubId) {
                $q->where('club_id', $clubId)->orWhereNull('club_id');
            })
            ->orderBy('name')
            ->get(['id', 'name', 'role']);

        return Inertia::render('Admin/Store/BuiltPcs', [
            'pcs' => $query->limit(150)->get(),
            'clients' => StoreClient::query()->where('club_id', $clubId)->orderBy('name')->get(['id', 'name', 'phone']),
            'components' => StoreComponent::query()
                ->where('club_id', $clubId)
                ->where('status', 'in_stock')
                ->orderBy('name')
                ->get(['id', 'name', 'type', 'purchase_price', 'warranty_number', 'qty']),
            'componentTypes' => StoreComponent::TYPES,
            'staff' => $staff,
            'taxModes' => StoreBuiltPc::TAX_MODES,
            'statuses' => StoreBuiltPc::STATUSES,
            'filters' => ['status' => $status ?: null],
            'canManage' => $admin->canManageStoreCatalog() || $admin->role === 'owner',
            'canAssemble' => in_array($admin->role, ['assembler', 'store_manager', 'senior_manager', 'owner'], true),
        ]);
    }

    public function store(Request $request, StoreWarrantyService $warranties)
    {
        abort_unless(
            $this->admin()->canManageStoreCatalog()
            || $this->admin()->role === 'assembler'
            || $this->admin()->role === 'owner',
            403
        );

        $data = $this->validated($request);
        $clubId = $this->locationId();

        DB::transaction(function () use ($data, $clubId, $warranties) {
            $serial = isset($data['serial_number']) && $data['serial_number'] !== ''
                ? $data['serial_number']
                : null;

            $pc = StoreBuiltPc::query()->create([
                'club_id' => $clubId,
                'store_client_id' => $data['store_client_id'] ?? null,
                'assembled_by' => $data['assembled_by'] ?? $this->admin()->id,
                'accepted_by' => $data['accepted_by'] ?? null,
                'issued_by' => $data['issued_by'] ?? null,
                'title' => $data['title'] ?? null,
                'build_spec' => $data['build_spec'] ?? null,
                'serial_number' => $serial,
                'sale_price' => $data['sale_price'] ?? null,
                'sale_tax_mode' => $data['sale_tax_mode'] ?? null,
                'sold_at' => $data['sold_at'] ?? null,
                'status' => $data['status'] ?? 'assembling',
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncComponents($pc, $data['component_ids'] ?? []);
            $warranties->ensureForBuiltPc($pc->fresh());
        });

        return back()->with('success', 'Сборка ПК создана, гарантия и серийный номер назначены.');
    }

    public function update(Request $request, StoreBuiltPc $storeBuiltPc, StoreWarrantyService $warranties)
    {
        abort_unless($storeBuiltPc->club_id === $this->locationId(), 404);
        abort_unless(
            $this->admin()->canManageStoreCatalog()
            || $this->admin()->role === 'assembler'
            || $this->admin()->role === 'owner',
            403
        );

        $data = $this->validated($request, updating: true);

        DB::transaction(function () use ($storeBuiltPc, $data, $warranties) {
            if (($data['status'] ?? null) === 'sold' && empty($data['sold_at']) && ! $storeBuiltPc->sold_at) {
                $data['sold_at'] = now();
            }

            if (array_key_exists('serial_number', $data) && $data['serial_number'] === '') {
                $data['serial_number'] = null;
            }

            $storeBuiltPc->update(collect($data)->except('component_ids')->all());

            if (array_key_exists('component_ids', $data)) {
                $this->syncComponents($storeBuiltPc, $data['component_ids'] ?? []);
            }

            $warranties->ensureForBuiltPc($storeBuiltPc->fresh());
        });

        return back()->with('success', 'Сборка обновлена.');
    }

    public function destroy(StoreBuiltPc $storeBuiltPc)
    {
        abort_unless($this->admin()->canManageStoreCatalog() || $this->admin()->role === 'owner', 403);
        abort_unless($storeBuiltPc->club_id === $this->locationId(), 404);

        DB::transaction(function () use ($storeBuiltPc) {
            foreach ($storeBuiltPc->componentLinks as $link) {
                if ($link->store_component_id) {
                    StoreComponent::query()
                        ->whereKey($link->store_component_id)
                        ->where('status', 'used')
                        ->update(['status' => 'in_stock']);
                }
            }
            $storeBuiltPc->delete();
        });

        return back()->with('success', 'Сборка удалена.');
    }

    private function syncComponents(StoreBuiltPc $pc, array $componentIds): void
    {
        $clubId = $pc->club_id;
        $ids = collect($componentIds)->filter()->map(fn ($id) => (int) $id)->unique()->values();

        $previousIds = $pc->componentLinks()->pluck('store_component_id')->filter()->all();

        if ($previousIds) {
            StoreComponent::query()
                ->where('club_id', $clubId)
                ->whereIn('id', $previousIds)
                ->where('status', 'used')
                ->update(['status' => 'in_stock']);
        }

        $pc->componentLinks()->delete();

        if ($ids->isEmpty()) {
            return;
        }

        $components = StoreComponent::query()
            ->where('club_id', $clubId)
            ->whereIn('id', $ids)
            ->get();

        foreach ($components as $component) {
            StoreBuiltPcComponent::query()->create([
                'store_built_pc_id' => $pc->id,
                'store_component_id' => $component->id,
                'type' => $component->type,
                'name' => $component->name,
            ]);
            $component->update(['status' => 'used']);
        }

        $pc->update([
            'build_spec' => $components->map(fn (StoreComponent $c) => [
                'component_id' => $c->id,
                'type' => $c->type,
                'name' => $c->name,
                'warranty_number' => $c->warranty_number,
            ])->values()->all(),
        ]);
    }

    private function validated(Request $request, bool $updating = false): array
    {
        $statuses = implode(',', array_keys(StoreBuiltPc::STATUSES));
        $tax = implode(',', array_keys(StoreBuiltPc::TAX_MODES));

        return $request->validate([
            'title' => 'nullable|string|max:255',
            'store_client_id' => 'nullable|integer',
            'assembled_by' => 'nullable|integer',
            'accepted_by' => 'nullable|integer',
            'issued_by' => 'nullable|integer',
            'serial_number' => 'nullable|string|max:128',
            'sale_price' => 'nullable|numeric|min:0',
            'sale_tax_mode' => "nullable|in:{$tax}",
            'sold_at' => 'nullable|date',
            'status' => "nullable|in:{$statuses}",
            'notes' => 'nullable|string|max:2000',
            'component_ids' => 'nullable|array',
            'component_ids.*' => 'integer',
            'build_spec' => 'nullable|array',
        ]);
    }
}
