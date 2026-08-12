<?php

namespace App\Http\Controllers\Admin\Store;

use App\Models\StoreClient;
use App\Models\StoreOrder;
use App\Models\StoreWarranty;
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
            ->with(['client:id,name,phone', 'order:id,status'])
            ->latest();

        if ($status && in_array($status, StoreWarranty::STATUSES, true)) {
            $query->where('status', $status);
        }

        // Сборщик видит кейсы по своим заказам
        if ($admin->role === 'assembler') {
            $query->whereHas('order', fn ($q) => $q->where('assignee_id', $admin->id));
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
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($this->admin()->canManageStoreCatalog() || $this->admin()->role === 'owner', 403);

        $data = $request->validate([
            'store_client_id' => 'nullable|integer',
            'store_order_id' => 'nullable|integer',
            'serial' => 'nullable|string|max:128',
            'product_name' => 'nullable|string|max:255',
            'started_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:started_at',
            'claim_notes' => 'nullable|string|max:2000',
        ]);

        $clubId = $this->locationId();

        if (! empty($data['store_client_id'])) {
            StoreClient::query()->where('club_id', $clubId)->whereKey($data['store_client_id'])->firstOrFail();
        }
        if (! empty($data['store_order_id'])) {
            StoreOrder::query()->where('club_id', $clubId)->whereKey($data['store_order_id'])->firstOrFail();
        }

        StoreWarranty::query()->create([
            ...$data,
            'club_id' => $clubId,
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
}
