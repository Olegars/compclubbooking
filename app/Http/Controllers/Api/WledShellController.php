<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Computer;
use App\Models\WledController;
use App\Models\WledCue;
use App\Services\Light\WledCueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WledShellController extends Controller
{
    public function cues(Request $request, WledCueService $wled): JsonResponse
    {
        $computer = Computer::query()->find((int) $request->query('terminal_id', 0));
        if (! $computer) {
            return response()->json(['status' => 'error', 'message' => 'terminal'], 404);
        }

        $clubId = (int) $computer->club_id;
        $any = $clubId > 0 && WledController::query()->where('club_id', $clubId)->exists();
        $active = $any && WledController::query()
            ->where('club_id', $clubId)
            ->where('is_active', true)
            ->exists();

        $cues = $any ? $wled->claim($computer) : [];

        return response()->json([
            'status' => 'ok',
            'enabled' => $active,
            'poll_ms' => $cues !== [] ? 1500 : ($any ? 2500 : 30000),
            'cues' => $cues,
        ]);
    }

    public function ack(Request $request, WledCue $cue, WledCueService $wled): JsonResponse
    {
        $data = $request->validate([
            'terminal_id' => 'required|integer',
            'ok' => 'required|boolean',
            'error' => 'nullable|string|max:500',
        ]);

        $computer = Computer::query()->find((int) $data['terminal_id']);
        if (! $computer) {
            return response()->json(['status' => 'error', 'message' => 'terminal'], 404);
        }

        if (! $wled->acknowledge($cue, $computer, (bool) $data['ok'], $data['error'] ?? null)) {
            return response()->json(['status' => 'error', 'message' => 'claimed'], 409);
        }

        return response()->json(['status' => 'ok']);
    }
}
