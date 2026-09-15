<?php

namespace App\Http\Controllers;

use App\Services\TelegramGuestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, TelegramGuestService $telegram): JsonResponse
    {
        $secret = trim((string) config('services.telegram.webhook_secret'));
        if ($secret !== '') {
            $got = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');
            if (! hash_equals($secret, $got)) {
                abort(403);
            }
        }
        if (! $telegram->botConfigured()) {
            return response()->json(['ok' => true]);
        }

        $payload = $request->all();
        if (is_array($payload)) {
            $telegram->handleUpdate($payload);
        }

        return response()->json(['ok' => true]);
    }
}
