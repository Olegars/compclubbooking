<?php

namespace App\Http\Controllers;

use App\Services\Faceit\FaceitIdentityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class FaceitWebhookController extends Controller
{
    public function __invoke(Request $request, FaceitIdentityService $faceit): JsonResponse
    {
        $secret = trim((string) config('services.faceit.webhook_secret'));
        $got = (string) $request->header('X-Faceit-Webhook-Secret', '');
        if ($secret === '' || ! hash_equals($secret, $got)) {
            abort(403);
        }
        try {
            $result = $faceit->ingestWebhook($request->all());
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'duplicate' => $result['duplicate']]);
    }
}
