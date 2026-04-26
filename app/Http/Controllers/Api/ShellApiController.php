<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Overlay;
use Illuminate\Http\Request;

class ShellApiController extends Controller
{
    // Этот метод будет дергать QML-шелл каждую минуту
    public function getActiveOverlays()
    {
        // Берем только активные оверлеи и делаем ключами 'block_position' (top_left, и т.д.)
        $overlays = Overlay::where('is_active', true)
            ->get()
            ->keyBy('block_position');

        return response()->json([
            'status' => 'success',
            'data' => $overlays
        ]);
    }
}
