<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Fan\SharedFanControlService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Pull-API для MikroTik: общие приточные/вытяжные вентиляторы.
 */
class SharedFanRelayController extends Controller
{
    /**
     * GET /api/fans/shared-targets?token=...
     */
    public function targets(Request $request, SharedFanControlService $shared)
    {
        if (! $this->tokenOk($request)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $clubId = $request->filled('club_id') ? (int) $request->query('club_id') : null;

        try {
            $targets = $shared->targetsPayload($clubId);
        } catch (\Throwable $e) {
            Log::error('Shared fan targets failed: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to build shared fan queue',
            ], 500);
        }

        $needs = array_values(array_filter($targets, fn (array $t) => ! empty($t['needs_apply'])));

        return response()->json([
            'status' => 'success',
            'count' => count($targets),
            'needs_apply_count' => count($needs),
            'targets' => $targets,
        ]);
    }

    /**
     * POST /api/fans/shared-applied
     * Body: { token, items: [{ id, applied_power, last_error? }] }
     */
    public function applied(Request $request, SharedFanControlService $shared)
    {
        if (! $this->tokenOk($request)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|integer',
            'items.*.applied_power' => 'required|integer|min:0|max:3',
            'items.*.last_error' => 'nullable|string|max:500',
        ]);

        $updated = $shared->acknowledgeApplied($request->input('items', []));

        return response()->json([
            'status' => 'success',
            'updated' => $updated,
        ]);
    }

    private function tokenOk(Request $request): bool
    {
        $expected = (string) config('fan.shared_relay_token', '');
        if ($expected === '') {
            Log::warning('Shared fan relay: FAN_SHARED_RELAY_TOKEN / CLUB_WOL_RELAY_TOKEN empty — rejecting');

            return false;
        }

        $given = (string) (
            $request->query('token')
            ?? $request->header('X-Fan-Token')
            ?? $request->header('X-Wol-Token')
            ?? $request->input('token')
            ?? ''
        );

        return hash_equals($expected, $given);
    }
}
