<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PromoCode;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PromoCodeAdminController extends Controller
{
    public function index()
    {
        return Inertia::render('Admin/PromoCodes', [
            'promocodes' => PromoCode::latest()->get()
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|unique:promo_codes|max:20',
            'type' => 'required|in:bonus_money,discount',
            'value' => 'required|numeric|min:1',
            'max_uses' => 'required|integer|min:1',
        ]);

        // Сохраняем в верхнем регистре для унификации
        $validated['code'] = strtoupper($validated['code']);
        PromoCode::create($validated);

        return back();
    }

    public function destroy(PromoCode $promoCode)
    {
        $promoCode->delete();
        return back();
    }
}
