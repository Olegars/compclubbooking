<?php

namespace App\Http\Controllers;

use App\Services\StoreAvito\StoreAvitoMessengerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StoreAvitoWebhookController extends Controller
{
    public function __invoke(Request $request, StoreAvitoMessengerService $messenger)
    {
        $secret = trim((string) config('services.avito.webhook_secret'));
        $got = (string) ($request->query('token') ?: $request->header('X-Avito-Webhook-Token', ''));
        if ($secret === '' || ! hash_equals($secret, $got)) {
            abort(403);
        }

        $payload = $request->except(['token']);
        Log::info('Avito webhook hit', [
            'type' => data_get($payload, 'payload.type'),
            'chat_id' => $messenger->webhookMessage($payload)['chat_id'] ?? null,
            'empty' => $payload === [],
        ]);
        try {
            $messenger->handleWebhook($payload);
        } catch (\Throwable $e) {
            Log::warning('Avito webhook: '.$e->getMessage());
        }

        return response()->json(['ok' => true]);
    }
}
