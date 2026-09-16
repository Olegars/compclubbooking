<?php

namespace App\Http\Controllers;

use App\Services\StoreAvito\StoreAvitoMessengerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StoreAvitoWebhookController extends Controller
{
    public function __invoke(Request $request, StoreAvitoMessengerService $messenger)
    {
        $payload = $request->all();
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
