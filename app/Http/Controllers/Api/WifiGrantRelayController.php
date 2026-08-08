<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WifiAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Pull-API для MikroTik: выдать / забрать гостевой Wi-Fi по MAC.
 *
 * GET  /api/wifi/grant-targets?token=...
 * POST /api/wifi/grant-applied { token, grant_ids?: [], revoke_ids?: [], enrich?: { "12": {"mac":"...","ip":"..."} } }
 */
class WifiGrantRelayController extends Controller
{
    public function targets(Request $request, WifiAccessService $wifi)
    {
        if (! $this->tokenOk($request)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        if (! $wifi->enabled()) {
            return response()->json([
                'status' => 'success',
                'enabled' => false,
                'grant' => [],
                'revoke' => [],
                'macs_grant' => [],
                'macs_revoke' => [],
            ]);
        }

        $payload = $wifi->relayTargets();

        return response()->json(array_merge(['status' => 'success', 'enabled' => true], $payload));
    }

    public function applied(Request $request, WifiAccessService $wifi)
    {
        if (! $this->tokenOk($request)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $data = $request->validate([
            'grant_ids' => 'nullable|array',
            'grant_ids.*' => 'integer',
            'revoke_ids' => 'nullable|array',
            'revoke_ids.*' => 'integer',
            'enrich' => 'nullable|array',
        ]);

        $grantIds = array_map('intval', $data['grant_ids'] ?? []);
        $revokeIds = array_map('intval', $data['revoke_ids'] ?? []);
        $enrich = [];
        foreach (($data['enrich'] ?? []) as $id => $row) {
            if (! is_array($row)) {
                continue;
            }
            $enrich[(int) $id] = [
                'mac' => isset($row['mac']) ? (string) $row['mac'] : null,
                'ip' => isset($row['ip']) ? (string) $row['ip'] : null,
            ];
        }

        $wifi->markApplied($grantIds, $revokeIds, $enrich);

        return response()->json([
            'status' => 'success',
            'grant_ids' => $grantIds,
            'revoke_ids' => $revokeIds,
        ]);
    }

    private function tokenOk(Request $request): bool
    {
        $expected = (string) config('wifi_access.relay_token', '');
        if ($expected === '') {
            Log::warning('WiFi relay: WIFI_RELAY_TOKEN / CLUB_WOL_RELAY_TOKEN empty — rejecting');

            return false;
        }

        $token = (string) ($request->query('token') ?: $request->input('token', ''));

        return $token !== '' && hash_equals($expected, $token);
    }
}
