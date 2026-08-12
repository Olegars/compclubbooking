<?php

namespace App\Http\Controllers\Admin\Store;

use App\Models\Club;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class LocationController extends StoreController
{
    public function index()
    {
        return Inertia::render('Admin/Store/Locations', [
            'locations' => Club::query()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:clubs,slug',
            'type' => 'required|in:club,store,both',
            'address' => 'nullable|string|max:500',
        ]);

        $slug = $data['slug'] ?: Str::slug($data['name']);
        if (Club::query()->where('slug', $slug)->exists()) {
            $slug .= '-'.Str::random(4);
        }

        Club::query()->create([
            'name' => $data['name'],
            'slug' => $slug,
            'type' => $data['type'],
            'address' => $data['address'] ?? null,
        ]);

        return back()->with('success', 'Локация создана.');
    }

    public function update(Request $request, Club $location)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:club,store,both',
            'address' => 'nullable|string|max:500',
        ]);

        $location->update($data);

        return back()->with('success', 'Локация обновлена.');
    }

    public function switch(Request $request)
    {
        $data = $request->validate([
            'club_id' => 'required|integer|exists:clubs,id',
        ]);

        abort_unless(\App\Support\AdminLocation::switch((int) $data['club_id'], $this->admin()), 403);

        return back()->with('success', 'Локация переключена.');
    }
}
