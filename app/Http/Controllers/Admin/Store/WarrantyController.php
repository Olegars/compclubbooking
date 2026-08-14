<?php

namespace App\Http\Controllers\Admin\Store;

use App\Models\StoreBuiltPc;
use App\Models\StoreClient;
use App\Models\StoreOrder;
use App\Models\StoreWarranty;
use App\Services\StorePosPrintService;
use App\Services\StoreWarrantyService;
use App\Support\WarrantyQr;
use Carbon\Carbon;
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
                'builtPc.componentLinks.component:id,name,type,warranty_number,serials,warranty_months',
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
        $remaining = $this->remainingWarranty($w->ends_at);
        $items = is_array($w->build_snapshot) ? array_values($w->build_snapshot) : [];
        if ($items === [] && $w->builtPc) {
            $items = $svc->buildSnapshot($w->builtPc);
        }

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
            'warranty_state' => $remaining['state'],
            'warranty_label' => $remaining['label'],
        ];
    }

    /**
     * @return array{state:string,label:?string}
     */
    private function remainingWarranty(mixed $endsAt): array
    {
        if (! $endsAt) {
            return ['state' => 'none', 'label' => null];
        }

        $ends = $endsAt instanceof Carbon
            ? $endsAt->copy()->startOfDay()
            : Carbon::parse($endsAt)->startOfDay();
        $today = now()->startOfDay();

        if ($ends->lt($today)) {
            $ago = (int) $ends->diffInDays($today);

            return [
                'state' => 'expired',
                'label' => $ago === 0
                    ? 'Гарантия истекла сегодня'
                    : 'Гарантия истекла '.$this->daysRu($ago).' назад',
            ];
        }

        $days = (int) $today->diffInDays($ends);
        if ($days === 0) {
            return ['state' => 'expiring', 'label' => 'Гарантия истекает сегодня'];
        }

        return [
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
}
