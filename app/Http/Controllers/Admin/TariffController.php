<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tariff;
use App\Models\Zone; // <--- ВОТ ЭТУ СТРОЧКУ НУЖНО ДОБАВИТЬ!
use Illuminate\Http\Request;
use Inertia\Inertia;

class TariffController extends Controller
{
    public function index()
    {
        return Inertia::render('Admin/Tariffs', [
            'tariffs' => Tariff::orderBy('threshold_hours')->get(),
            'zones' => Zone::all()
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'threshold_hours' => 'required|numeric',
            'price_per_package' => 'required|numeric',
            'category' => 'required|string',
        ]);

        $exists = Tariff::query()
            ->where('category', $data['category'])
            ->where('threshold_hours', (int) $data['threshold_hours'])
            ->exists();

        if ($exists) {
            return back()->withErrors([
                'threshold_hours' => 'Для этой зоны уже есть тариф с таким количеством часов.',
            ]);
        }

        Tariff::create($data);
        return back();
    }

    public function update(Request $request, Tariff $tariff)
    {
        $tariff->update($request->all());
        return back();
    }

    public function destroy(Tariff $tariff)
    {
        $tariff->delete();
        return back();
    }
}
