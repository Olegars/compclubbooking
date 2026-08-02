<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ComputerPowerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Pull-API для MikroTik (или другого LAN-релея).
 * Роутер раз в минуту забирает MAC на пробуждение — облако само WOL не шлёт.
 */
class WolRelayController extends Controller
{
    /**
     * GET /api/power/wol-targets?token=...
     * По умолчанию сразу claim → power_state=booting (удобно для одного fetch на MikroTik).
     * ?claim=0 — только список, без смены статуса.
     */
    public function targets(Request $request, ComputerPowerService $power)
    {
        if (! $this->tokenOk($request)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $claim = ! in_array(strtolower((string) $request->query('claim', '1')), ['0', 'false', 'no'], true);

        try {
            $targets = $power->wolTargets(claim: $claim);
        } catch (\Throwable $e) {
            Log::error('WOL targets failed: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to build WOL queue',
            ], 500);
        }

        return response()->json([
            'status' => 'success',
            'claimed' => $claim,
            'count' => count($targets),
            'targets' => $targets,
            // Плоский список — проще парсить в RouterOS :deserialize / скрипте.
            'macs' => array_values(array_map(fn (array $t) => $t['mac'], $targets)),
        ]);
    }

    /**
     * POST /api/power/wol-sent  { token, ids: [1,2] } или { macs: ["AA:BB:..."] }
     * Если GET с claim=0 — релей подтверждает после /tool wol.
     */
    public function sent(Request $request, ComputerPowerService $power)
    {
        if (! $this->tokenOk($request)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $request->validate([
            'ids' => 'nullable|array',
            'ids.*' => 'integer',
            'macs' => 'nullable|array',
            'macs.*' => 'string',
        ]);

        $ids = array_map('intval', $request->input('ids', []) ?: []);

        if ($ids === [] && $request->filled('macs')) {
            $wol = app(\App\Services\WakeOnLan::class);
            $normalized = [];
            foreach ($request->input('macs', []) as $mac) {
                $n = $wol->normalizeMac((string) $mac);
                if ($n) {
                    $normalized[$n] = $n;
                }
            }
            if ($normalized !== []) {
                $ids = \App\Models\Computer::query()
                    ->whereIn('mac_address', array_values($normalized))
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
            }
        }

        $updated = $power->markWolSent($ids);

        return response()->json([
            'status' => 'success',
            'updated' => $updated,
            'ids' => $ids,
        ]);
    }

    private function tokenOk(Request $request): bool
    {
        $expected = (string) config('club.power.wol_relay_token', '');
        if ($expected === '') {
            Log::warning('WOL relay: CLUB_WOL_RELAY_TOKEN is empty — rejecting');

            return false;
        }

        $given = (string) (
            $request->query('token')
            ?? $request->header('X-Wol-Token')
            ?? $request->input('token')
            ?? ''
        );

        return hash_equals($expected, $given);
    }
}
