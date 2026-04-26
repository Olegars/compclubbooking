<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Zone;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ZoneController extends Controller
{
    public function index()
    {
        return Inertia::render('Admin/Zones', [
            'zones' => Zone::all()
        ]);
    }

    public function store(Request $request)
    {
        Zone::create($request->validate([
            'name' => 'required|string',
            'slug' => 'required|string|unique:zones',
            'color' => 'required|string'
        ]));
        return back();
    }

    public function destroy(Zone $zone)
    {
        $zone->delete();
        return back();
    }
}
