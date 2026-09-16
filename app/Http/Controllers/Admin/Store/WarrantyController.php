<?php

namespace App\Http\Controllers\Admin\Store;

use App\Models\StoreBuiltPc;
use App\Models\StoreBuiltPcComponent;
use App\Models\StoreClient;
use App\Models\StoreComponent;
use App\Models\StoreOrder;
use App\Models\StoreWarranty;
use App\Services\StorePosPrintService;
use App\Services\StoreWarrantyService;
use App\Support\WarrantyQr;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class WarrantyController extends StoreController
{
    public function index(Request $request)
    {
        $status = $request->string('status')->toString();
        $admin = $this->admin();

        $query = StoreWarranty::query()
            ->where('club_id', $this->locationId())
            ->with([
                'client:id,name,phone',
                'order:id,status',
                'builtPc:id,title,serial_number,status',
                'builtPc.componentLinks.component:id,name,type,warranty_number,serials,warranty_months,created_at,status,sent_to_repair_at,status_before_repair,replaces_component_id,replaced_by_component_id,notes',
            ])
            ->latest();

        if ($status && in_array($status, StoreWarranty::STATUSES, true)) {
            $query->where('status', $status);
        }

        if ($admin->role === 'assembler') {
            $query->where(function ($q) use ($admin) {
                $q->whereHas('order', fn ($oq) => $oq->where('assignee_id', $admin->id))
                    ->orWhereHas('builtPc', fn ($bq) => $bq->where('assembled_by', $admin->id));
            });
        }

        $svc = app(StoreWarrantyService::class);
        $warranties = $query->limit(100)->get()
            ->map(fn (StoreWarranty $w) => $this->presentWarranty($w, $svc))
            ->values();

        return Inertia::render('Admin/Store/Warranty', [
            'warranties' => $warranties,
            'clients' => StoreClient::query()->where('club_id', $this->locationId())->orderBy('name')->get(['id', 'name', 'phone']),
            'orders' => StoreOrder::query()
                ->where('club_id', $this->locationId())
                ->latest()
                ->limit(50)
                ->get(['id', 'store_client_id', 'status', 'total']),
            'statuses' => StoreWarranty::STATUSES,
            'filters' => ['status' => $status ?: null],
            'canManage' => $admin->canManageStoreCatalog() || $admin->role === 'owner',
            'canClose' => $admin->canCloseWarranties(),
            'posPrintEnabled' => (bool) config('kitchen_print.enabled', false),
        ]);
    }

    public function store(Request $request, StoreWarrantyService $warranties)
    {
        abort_unless($this->admin()->canManageStoreCatalog() || $this->admin()->role === 'owner', 403);

        $data = $request->validate([
            'store_client_id' => 'nullable|integer',
            'store_order_id' => 'nullable|integer',
            'store_built_pc_id' => 'nullable|integer',
            'serial' => 'nullable|string|max:128',
            'product_name' => 'nullable|string|max:255',
            'started_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:started_at',
            'claim_notes' => 'nullable|string|max:2000',
        ]);

        $clubId = $this->locationId();

        if (! empty($data['store_built_pc_id'])) {
            $pc = StoreBuiltPc::query()->where('club_id', $clubId)->whereKey($data['store_built_pc_id'])->firstOrFail();
            $warranties->ensureForBuiltPc($pc);

            return back()->with('success', 'Гарантия привязана к сборке.');
        }

        if (! empty($data['store_client_id'])) {
            StoreClient::query()->where('club_id', $clubId)->whereKey($data['store_client_id'])->firstOrFail();
        }
        if (! empty($data['store_order_id'])) {
            StoreOrder::query()->where('club_id', $clubId)->whereKey($data['store_order_id'])->firstOrFail();
        }

        $serial = $data['serial'] ?? null;
        if (! $serial) {
            $serial = $warranties->generateSerial();
        }

        $months = $warranties->months();
        $repairDays = $warranties->repairDays();
        $started = ! empty($data['started_at']) ? $data['started_at'] : now()->toDateString();
        $ends = $data['ends_at'] ?? now()->addMonthsNoOverflow($months)->toDateString();

        StoreWarranty::query()->create([
            ...$data,
            'serial' => $serial,
            'public_token' => $warranties->freshPublicToken(),
            'club_id' => $clubId,
            'started_at' => $started,
            'ends_at' => $ends,
            'warranty_months' => $months,
            'repair_days' => $repairDays,
            'status' => 'active',
        ]);

        return back()->with('success', 'Гарантия создана.');
    }

    public function update(Request $request, StoreWarranty $storeWarranty)
    {
        abort_unless($storeWarranty->club_id === $this->locationId(), 404);

        $data = $request->validate([
            'status' => 'required|in:active,claimed,closed',
            'claim_notes' => 'nullable|string|max:2000',
            'ends_at' => 'nullable|date',
        ]);

        $admin = $this->admin();

        if ($data['status'] === 'closed') {
            abort_unless($admin->canCloseWarranties(), 403);
        } else {
            abort_unless($admin->canManageStoreCatalog() || $admin->role === 'owner', 403);
        }

        $storeWarranty->update($data);

        return back()->with('success', 'Гарантия обновлена.');
    }

    /**
     * Передать комплектующую в ремонт (остаётся в сборке, статус «ремонт»).
     */
    public function sendToRepair(Request $request, StoreWarranty $storeWarranty)
    {
        abort_unless($storeWarranty->club_id === $this->locationId(), 404);
        abort_unless($this->admin()->canManageStoreCatalog() || $this->admin()->role === 'owner', 403);

        $data = $request->validate([
            'store_component_id' => 'required|integer',
        ]);

        try {
            DB::transaction(function () use ($storeWarranty, $data) {
                $component = $this->resolveWarrantyComponent($storeWarranty, (int) $data['store_component_id']);

                if ($component->status === 'repair') {
                    throw new \RuntimeException('Комплектующая уже в ремонте.');
                }
                if ($component->status === 'written_off') {
                    throw new \RuntimeException('Комплектующая уже списана.');
                }

                $prev = $component->status;
                $when = now();
                $line = 'передана в ремонт '.$when->format('d.m.Y H:i');
                $note = trim((string) ($component->notes ?? ''));
                $component->update([
                    'status_before_repair' => $prev,
                    'sent_to_repair_at' => $when,
                    'status' => 'repair',
                    'notes' => $note !== '' ? $note."\n".$line : $line,
                ]);

                if ($storeWarranty->status === 'active') {
                    $storeWarranty->update(['status' => 'claimed']);
                }
            });
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Комплектующая передана в ремонт (связь со сборкой сохранена).');
    }

    /**
     * Вернуть отремонтированную комплектующую в сборку.
     */
    public function returnFromRepair(Request $request, StoreWarranty $storeWarranty)
    {
        abort_unless($storeWarranty->club_id === $this->locationId(), 404);
        abort_unless($this->admin()->canManageStoreCatalog() || $this->admin()->role === 'owner', 403);

        $data = $request->validate([
            'store_component_id' => 'required|integer',
        ]);

        try {
            DB::transaction(function () use ($storeWarranty, $data) {
                $storeWarranty->loadMissing('builtPc');
                $component = $this->resolveWarrantyComponent($storeWarranty, (int) $data['store_component_id']);

                if ($component->status !== 'repair') {
                    throw new \RuntimeException('Комплектующая не в ремонте.');
                }

                $restore = $component->status_before_repair;
                if (! $restore || ! isset(StoreComponent::STATUSES[$restore]) || $restore === 'repair') {
                    $pcSold = $storeWarranty->builtPc?->status === 'sold'
                        || optional($storeWarranty->builtPc)->sold_at;
                    $restore = $pcSold ? 'sold' : 'used';
                }

                $when = now();
                $line = 'возвращена в сборку '.$when->format('d.m.Y H:i');
                $note = trim((string) ($component->notes ?? ''));
                $component->update([
                    'status' => $restore,
                    'status_before_repair' => null,
                    'sent_to_repair_at' => null,
                    'notes' => $note !== '' ? $note."\n".$line : $line,
                ]);
            });
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Комплектующая возвращена в сборку.');
    }

    /**
     * Списать деталь в ремонте и поставить замену в сборку.
     */
    public function replaceComponent(Request $request, StoreWarranty $storeWarranty, StoreWarrantyService $warranties)
    {
        abort_unless($storeWarranty->club_id === $this->locationId(), 404);
        abort_unless($this->admin()->canManageStoreCatalog() || $this->admin()->role === 'owner', 403);

        $data = $request->validate([
            'store_component_id' => 'required|integer',
            'name' => 'nullable|string|max:255',
            'warranty_number' => 'nullable|string|max:128',
            'serials' => 'nullable|array',
            'serials.*' => 'nullable|string|max:128',
            'purchase_price' => 'nullable|numeric|min:0',
            'warranty_months' => 'nullable|integer|min:0|max:120',
            'notes' => 'nullable|string|max:2000',
        ]);

        try {
            DB::transaction(function () use ($storeWarranty, $data, $warranties) {
                $storeWarranty->loadMissing('builtPc');
                $old = $this->resolveWarrantyComponent($storeWarranty, (int) $data['store_component_id']);

                if ($old->status !== 'repair') {
                    throw new \RuntimeException('Списать с заменой можно только деталь в ремонте.');
                }

                $serials = collect($data['serials'] ?? [])
                    ->map(fn ($s) => trim((string) $s))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();
                if ($serials === [] && ! empty($data['warranty_number'])) {
                    $one = trim((string) $data['warranty_number']);
                    if ($one !== '') {
                        $serials = [$one];
                    }
                }

                $restore = $old->status_before_repair;
                if (! $restore || ! isset(StoreComponent::STATUSES[$restore]) || in_array($restore, ['repair', 'written_off'], true)) {
                    $pcSold = $storeWarranty->builtPc?->status === 'sold'
                        || optional($storeWarranty->builtPc)->sold_at;
                    $restore = $pcSold ? 'sold' : 'used';
                }

                $when = now();
                $new = StoreComponent::query()->create([
                    'club_id' => $old->club_id,
                    'store_supplier_id' => $old->store_supplier_id,
                    'received_by' => $this->admin()->id,
                    'name' => trim((string) ($data['name'] ?? '')) ?: $old->name,
                    'original_name' => $old->original_name,
                    'type' => $old->type,
                    'specs' => $old->specs,
                    'purchase_price' => array_key_exists('purchase_price', $data) && $data['purchase_price'] !== null
                        ? $data['purchase_price']
                        : $old->purchase_price,
                    'warranty_number' => $serials[0] ?? null,
                    'serials' => $serials,
                    'warranty_months' => array_key_exists('warranty_months', $data) && $data['warranty_months'] !== null
                        ? (int) $data['warranty_months']
                        : $old->warranty_months,
                    'qty' => 1,
                    'status' => $restore,
                    'replaces_component_id' => $old->id,
                    'notes' => trim(
                        'замена ID '.$old->id
                        ."\n".trim((string) ($data['notes'] ?? ''))
                    ),
                ]);

                $oldNote = trim((string) ($old->notes ?? ''));
                $oldLine = 'списана со склада '.$when->format('d.m.Y H:i').' · замена на ID '.$new->id;
                $old->update([
                    'status' => 'written_off',
                    'status_before_repair' => null,
                    'sent_to_repair_at' => null,
                    'replaced_by_component_id' => $new->id,
                    'notes' => $oldNote !== '' ? $oldNote."\n".$oldLine : $oldLine,
                ]);

                // Заменить в сборке
                $linkQuery = StoreBuiltPcComponent::query()->where('store_component_id', $old->id);
                if ($storeWarranty->store_built_pc_id) {
                    $linkQuery->where('store_built_pc_id', $storeWarranty->store_built_pc_id);
                }
                $linkQuery->update([
                    'store_component_id' => $new->id,
                    'name' => $new->name,
                    'type' => $new->type,
                ]);

                // Обновить снимок гарантии
                if ($storeWarranty->store_built_pc_id) {
                    $pc = StoreBuiltPc::query()->find($storeWarranty->store_built_pc_id);
                    if ($pc) {
                        $snapshot = $warranties->buildSnapshot($pc);
                        $storeWarranty->update(['build_snapshot' => $snapshot]);
                    }
                } else {
                    $snapshot = collect(is_array($storeWarranty->build_snapshot) ? $storeWarranty->build_snapshot : [])
                        ->map(function ($row) use ($old, $new) {
                            if (! is_array($row)) {
                                return $row;
                            }
                            if ((int) ($row['store_component_id'] ?? 0) === $old->id) {
                                $row['store_component_id'] = $new->id;
                                $row['name'] = $new->name;
                                $row['warranty_number'] = $new->serialsLabel() ?: null;
                                $row['serials'] = $new->allSerials();
                                $row['warranty_months'] = $new->warranty_months;
                                $row['received_at'] = $new->created_at?->toIso8601String();
                            }

                            return $row;
                        })
                        ->values()
                        ->all();
                    $storeWarranty->update(['build_snapshot' => $snapshot]);
                }

                if ($storeWarranty->status === 'active') {
                    $storeWarranty->update(['status' => 'claimed']);
                }
            });
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Старая деталь списана, в сборку поставлена замена.');
    }

    private function resolveWarrantyComponent(StoreWarranty $storeWarranty, int $componentId): StoreComponent
    {
        $component = StoreComponent::query()
            ->where('club_id', $storeWarranty->club_id)
            ->whereKey($componentId)
            ->lockForUpdate()
            ->firstOrFail();

        $belongsToBuild = false;
        if ($storeWarranty->store_built_pc_id) {
            $belongsToBuild = StoreBuiltPcComponent::query()
                ->where('store_built_pc_id', $storeWarranty->store_built_pc_id)
                ->where('store_component_id', $component->id)
                ->exists();
        }

        if (! $belongsToBuild && ! in_array($component->status, ['sold', 'used', 'reserved', 'repair'], true)) {
            $inSnapshot = collect(is_array($storeWarranty->build_snapshot) ? $storeWarranty->build_snapshot : [])
                ->contains(fn ($row) => is_array($row) && (int) ($row['store_component_id'] ?? 0) === $component->id);
            if (! $inSnapshot) {
                throw new \RuntimeException('Комплектующая не относится к этой гарантии.');
            }
        }

        return $component;
    }

    /** Очередь на POS (ESC/POS QR через kitchen-агент). */
    public function printBarcodePos(StoreWarranty $storeWarranty, StorePosPrintService $prints)
    {
        abort_unless($storeWarranty->club_id === $this->locationId(), 404);

        if (! $storeWarranty->serial) {
            return back()->with('error', 'У гарантии нет серийного номера.');
        }

        $prints->enqueueBarcode($storeWarranty);

        return back()->with('success', 'QR отправлен на POS-принтер (очередь агента).');
    }

    /** HTML-лента 80mm — выбрать POS в диалоге печати. */
    public function printBarcode(StoreWarranty $storeWarranty)
    {
        abort_unless($storeWarranty->club_id === $this->locationId(), 404);
        $storeWarranty->load(['client:id,name', 'club:id,name', 'builtPc:id,title']);
        $qrPayload = WarrantyQr::payload($storeWarranty);

        return Inertia::render('Admin/Store/WarrantyBarcodePrint', [
            'warranty' => $storeWarranty,
            'qrPayload' => $qrPayload,
            'qrImageUrl' => WarrantyQr::imageUrl($qrPayload, 240),
            'endsAtLabel' => $storeWarranty->ends_at?->format('d.m.Y'),
        ]);
    }

    /** Гарантийный талон A4 с комплектацией. */
    public function printTalon(StoreWarranty $storeWarranty, StoreWarrantyService $warranties)
    {
        abort_unless($storeWarranty->club_id === $this->locationId(), 404);

        if ($storeWarranty->store_built_pc_id) {
            $pc = StoreBuiltPc::query()->find($storeWarranty->store_built_pc_id);
            if ($pc) {
                $storeWarranty = $warranties->ensureForBuiltPc($pc);
            }
        }

        $storeWarranty->load(['client', 'club', 'builtPc.assembler:id,name']);
        $qrPayload = WarrantyQr::payload($storeWarranty);

        return Inertia::render('Admin/Store/WarrantyTalonPrint', [
            'warranty' => $storeWarranty,
            'qrPayload' => $qrPayload,
            'qrImageUrl' => WarrantyQr::imageUrl($qrPayload, 180),
            'buildItems' => is_array($storeWarranty->build_snapshot) ? $storeWarranty->build_snapshot : [],
        ]);
    }

    /** Сборка → гарантия (создать при необходимости) → печать. */
    public function printBuiltPcBarcode(StoreBuiltPc $storeBuiltPc, StoreWarrantyService $warranties)
    {
        abort_unless($storeBuiltPc->club_id === $this->locationId(), 404);
        $w = $warranties->ensureForBuiltPc($storeBuiltPc);

        return redirect()->route('admin.store.warranty.print-barcode', $w);
    }

    public function printBuiltPcBarcodePos(StoreBuiltPc $storeBuiltPc, StoreWarrantyService $warranties, StorePosPrintService $prints)
    {
        abort_unless($storeBuiltPc->club_id === $this->locationId(), 404);
        $w = $warranties->ensureForBuiltPc($storeBuiltPc);
        $prints->enqueueBarcode($w);

        return back()->with('success', 'QR отправлен на POS-принтер.');
    }

    public function printBuiltPcTalon(StoreBuiltPc $storeBuiltPc, StoreWarrantyService $warranties)
    {
        abort_unless($storeBuiltPc->club_id === $this->locationId(), 404);
        $w = $warranties->ensureForBuiltPc($storeBuiltPc);

        return redirect()->route('admin.store.warranty.print-talon', $w);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentWarranty(StoreWarranty $w, StoreWarrantyService $svc): array
    {
        $remaining = $svc->remainingWarranty($w->ends_at);
        $items = is_array($w->build_snapshot) ? array_values($w->build_snapshot) : [];
        if ($items === [] && $w->builtPc) {
            $items = $svc->buildSnapshot($w->builtPc);
        }
        $items = $svc->enrichBuildItems($items, $w->builtPc);
        $hasRepair = collect($items)->contains(fn ($row) => ($row['component_status'] ?? null) === 'repair');
        $svc->ensurePublicToken($w);

        return [
            'id' => $w->id,
            'serial' => $w->serial,
            'product_name' => $w->product_name,
            'status' => $w->status,
            'claim_notes' => $w->claim_notes,
            'started_at' => $w->started_at?->toDateString(),
            'ends_at' => $w->ends_at?->toDateString(),
            'warranty_months' => $w->warranty_months,
            'repair_days' => $w->repair_days,
            'client' => $w->client ? [
                'id' => $w->client->id,
                'name' => $w->client->name,
                'phone' => $w->client->phone,
            ] : null,
            'order' => $w->order ? [
                'id' => $w->order->id,
                'status' => $w->order->status,
            ] : null,
            'built_pc' => $w->builtPc ? [
                'id' => $w->builtPc->id,
                'title' => $w->builtPc->title,
                'serial_number' => $w->builtPc->serial_number,
                'status' => $w->builtPc->status,
            ] : null,
            'build_items' => $items,
            'has_repair' => $hasRepair,
            'warranty_state' => $remaining['state'],
            'warranty_label' => $remaining['label'],
            'passport_url' => $w->passportUrl(),
        ];
    }
}
