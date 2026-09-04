<?php

namespace App\Http\Controllers;

use App\Services\StoreAvito\StoreAvitoMessengerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StoreAvitoWebhookController extends Controller
{
    public function __invoke(Request $request, StoreAvitoMessengerService $messenger)
    {
        try {
            $messenger->handleWebhook($request->all());
        } catch (\Throwable $e) {
            Log::warning('Avito webhook: '.$e->getMessage());
        }

        return response()->json(['ok' => true]);
    }
}
