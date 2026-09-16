<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ArenaDuel;
use App\Services\LanLive\ArenaDuelService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class ArenaDuelAdminController extends Controller
{
    public function forceRefund(int $id, ArenaDuelService $arena): JsonResponse
    {
        $duel = ArenaDuel::query()->findOrFail($id);
        try {
            $row = $arena->forceRefund($duel);
        } catch (RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Эскроу возвращён',
            'duel' => $arena->payload($row),
        ]);
    }
}
