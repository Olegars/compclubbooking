<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FiscalGatewayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Pull-API шлюза фискального регистратора. Облако в LAN кассы не ходит.
 */
class FiscalRelayController extends Controller
{
    /**
     * GET /api/fiscal/targets?token=…
     */
    public function targets(Request $request, FiscalGatewayService $gateway)
    {
        if (! $this->tokenOk($request)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $gateway->touch();

        if (! (bool) config('fiscal.enabled', false)) {
            return response()->json([
                'status' => 'success',
                'enabled' => false,
                'count' => 0,
                'jobs' => [],
            ]);
        }

        $gateway->releaseStaleClaims();

        $limit = (int) $request->query('limit', config('fiscal.claim_limit', 5));
        $jobs = $gateway->claimPending($limit);

        return response()->json([
            'status' => 'success',
            'enabled' => true,
            'count' => count($jobs),
            'jobs' => $jobs,
        ]);
    }

    /**
     * POST /api/fiscal/applied
     * { token, results: [{ id, outcome, receipt_url?, fn?, fd?, fp?, error? }], device? }
     */
    public function applied(Request $request, FiscalGatewayService $gateway)
    {
        if (! $this->tokenOk($request)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $gateway->touch(is_array($request->input('device')) ? $request->input('device') : null);

        $data = $request->validate([
            'results' => 'required|array',
            'results.*.id' => 'required|integer',
            'results.*.outcome' => 'required|string|in:success,error,uncertain',
            'results.*.error' => 'nullable|string|max:500',
            'results.*.receipt_url' => 'nullable|string|max:500',
            'results.*.fn' => 'nullable|string|max:32',
            'results.*.fd' => 'nullable|string|max:32',
            'results.*.fp' => 'nullable|string|max:32',
            'results.*.shift' => 'nullable|string|max:16',
            'results.*.receipt_number' => 'nullable|string|max:16',
            'device' => 'nullable|array',
        ]);

        $applied = $gateway->applyResults($data['results']);

        return response()->json([
            'status' => 'success',
            'applied' => $applied,
        ]);
    }

    private function tokenOk(Request $request): bool
    {
        $expected = (string) config('fiscal.relay_token', '');
        if ($expected === '') {
            Log::warning('Fiscal relay: FISCAL_RELAY_TOKEN empty — rejecting');

            return false;
        }

        $given = (string) (
            $request->query('token')
            ?? $request->header('X-Fiscal-Relay-Token')
            ?? $request->input('token')
            ?? ''
        );

        return hash_equals($expected, $given);
    }
}
