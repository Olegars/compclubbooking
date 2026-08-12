<?php

namespace App\Http\Controllers\Admin\Store;

use App\Models\StoreBuiltPc;
use App\Models\StoreClient;
use App\Models\StoreOrder;
use App\Models\StoreWarranty;
use App\Services\StorePosPrintService;
use App\Services\StoreWarrantyService;
use App\Support\Code128Svg;
use Illuminate\Http\Request;
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

        return Inertia::render('Admin/Store/Warranty', [
            'warranties' => $query->limit(100)->get(),
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

    /** Очередь на POS (ESC/POS штрихкод через kitchen-агент). */
    public function printBarcodePos(StoreWarranty $storeWarranty, StorePosPrintService $prints)
    {
        abort_unless($storeWarranty->club_id === $this->locationId(), 404);

        if (! $storeWarranty->serial) {
            return back()->with('error', 'У гарантии нет серийного номера.');
        }

        $prints->enqueueBarcode($storeWarranty);

        return back()->with('success', 'Штрихкод отправлен на POS-принтер (очередь агента).');
    }

    /** HTML-лента 80mm — выбрать POS в диалоге печати. */
    public function printBarcode(StoreWarranty $storeWarranty)
    {
        abort_unless($storeWarranty->club_id === $this->locationId(), 404);
        $storeWarranty->load(['client:id,name', 'club:id,name', 'builtPc:id,title']);

        return Inertia::render('Admin/Store/WarrantyBarcodePrint', [
            'warranty' => $storeWarranty,
            'barcodeSvg' => Code128Svg::svg((string) $storeWarranty->serial),
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

        return Inertia::render('Admin/Store/WarrantyTalonPrint', [
            'warranty' => $storeWarranty,
            'barcodeSvg' => Code128Svg::svg((string) $storeWarranty->serial),
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

        return back()->with('success', 'Штрихкод отправлен на POS-принтер.');
    }

    public function printBuiltPcTalon(StoreBuiltPc $storeBuiltPc, StoreWarrantyService $warranties)
    {
        abort_unless($storeBuiltPc->club_id === $this->locationId(), 404);
        $w = $warranties->ensureForBuiltPc($storeBuiltPc);

        return redirect()->route('admin.store.warranty.print-talon', $w);
    }
}
