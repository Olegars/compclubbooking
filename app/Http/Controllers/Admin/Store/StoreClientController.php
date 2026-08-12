<?php

namespace App\Http\Controllers\Admin\Store;

use App\Models\StoreClient;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StoreClientController extends StoreController
{
    public function index(Request $request)
    {
        $q = trim($request->string('q')->toString());
        $admin = $this->admin();

        $query = StoreClient::query()
            ->where('club_id', $this->locationId())
            ->withCount(['orders', 'warranties'])
            ->orderBy('name');

        if ($q !== '') {
            $query->where(function ($builder) use ($q) {
                $builder->where('name', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            });
        }

        return Inertia::render('Admin/Store/Clients', [
            'clients' => $query->limit(200)->get(),
            'filters' => ['q' => $q ?: null],
            'canManage' => $admin->canManageStoreCatalog() || $admin->role === 'owner',
            'readOnly' => $admin->role === 'assembler',
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($this->admin()->canManageStoreCatalog() || $this->admin()->role === 'owner', 403);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:32',
            'email' => 'nullable|email|max:255',
            'notes' => 'nullable|string|max:2000',
        ]);

        StoreClient::query()->create([
            ...$data,
            'club_id' => $this->locationId(),
        ]);

        return back()->with('success', 'Клиент магазина добавлен.');
    }

    public function update(Request $request, StoreClient $storeClient)
    {
        abort_unless($this->admin()->canManageStoreCatalog() || $this->admin()->role === 'owner', 403);
        abort_unless($storeClient->club_id === $this->locationId(), 404);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:32',
            'email' => 'nullable|email|max:255',
            'notes' => 'nullable|string|max:2000',
        ]);

        $storeClient->update($data);

        return back()->with('success', 'Клиент обновлён.');
    }

    public function destroy(StoreClient $storeClient)
    {
        abort_unless($this->admin()->canManageStoreCatalog() || $this->admin()->role === 'owner', 403);
        abort_unless($storeClient->club_id === $this->locationId(), 404);

        $storeClient->delete();

        return back()->with('success', 'Клиент удалён.');
    }
}
