<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Overlay;
use App\Events\OverlayUpdated;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OverlayAdminController extends Controller
{
    // Отдает саму Vue страницу
    public function index()
    {
        return Inertia::render('Admin/OverlayManager');
    }

    // Отдает данные для Vue компонента (GET /api/overlays)
    public function getOverlays()
    {
        return response()->json(Overlay::all());
    }

    // Сохраняет изменения из админки (PUT /api/overlays/{id})
    public function updateOverlay(Request $request, $id)
    {
        $overlay = Overlay::findOrFail($id);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'type' => 'required|string',
            'content' => 'required|array',
            'is_active' => 'required|boolean'
        ]);

// ... внутри метода updateOverlay():
        $overlay->update($validated);

// БУМ! Отправляем новые данные по вебсокету всем компам
        broadcast(new OverlayUpdated($overlay));

        return response()->json(['status' => 'success']);
    }
}
