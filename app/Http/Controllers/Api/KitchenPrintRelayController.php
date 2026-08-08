<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\KitchenOrderPrintService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Pull-API для LAN-агента кухонного ESC/POS принтера (Ethernet :9100).
 */
class KitchenPrintRelayController extends Controller
{
    /**
     * GET /api/kitchen/print-targets?token=…
     */
    public function targets(Request $request, KitchenOrderPrintService $prints)
    {
        if (! $this->tokenOk($request)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        if (! $prints->enabled()) {
            return response()->json([
                'status' => 'success',
                'enabled' => false,
                'count' => 0,
                'jobs' => [],
            ]);
        }

        $prints->releaseStaleClaims((int) config('kitchen_print.stale_claim_minutes', 2));

        $limit = (int) $request->query('limit', config('kitchen_print.claim_limit', 10));
        $jobs = $prints->claimPending($limit);

        return response()->json([
            'status' => 'success',
            'enabled' => true,
            'count' => count($jobs),
            'jobs' => $jobs,
            'printer_hint' => [
                'host' => config('kitchen_print.printer_host'),
                'port' => (int) config('kitchen_print.printer_port', 9100),
            ],
        ]);
    }

    /**
     * POST /api/kitchen/print-applied
     * { token, printed_ids?: [], failed?: [{ id, error }] }
     */
    public function applied(Request $request, KitchenOrderPrintService $prints)
    {
        if (! $this->tokenOk($request)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $request->validate([
            'printed_ids' => 'nullable|array',
            'printed_ids.*' => 'integer',
            'failed' => 'nullable|array',
            'failed.*.id' => 'required|integer',
            'failed.*.error' => 'nullable|string|max:500',
        ]);

        $printed = $prints->markPrinted($request->input('printed_ids', []) ?: []);
        $failed = $prints->markFailed($request->input('failed', []) ?: []);

        return response()->json([
            'status' => 'success',
            'printed' => $printed,
            'failed' => $failed,
        ]);
    }

    private function tokenOk(Request $request): bool
    {
        $expected = (string) config('kitchen_print.relay_token', '');
        if ($expected === '') {
            Log::warning('Kitchen print relay: token empty — rejecting');

            return false;
        }

        $given = (string) (
            $request->query('token')
            ?? $request->header('X-Kitchen-Print-Token')
            ?? $request->input('token')
            ?? ''
        );

        return hash_equals($expected, $given);
    }
}
