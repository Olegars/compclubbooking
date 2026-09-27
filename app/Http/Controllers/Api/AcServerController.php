<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReactorAc\AcGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AcServerController extends Controller
{
    public function __construct(private readonly AcGate $gate)
    {
    }

    public function validateToken(Request $request): JsonResponse
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        $data = $request->validate([
            'steam_id' => ['required', 'string', 'max:32'],
            'token' => ['nullable', 'string', 'max:64'],
            'ip' => ['nullable', 'string', 'max:64'],
        ]);

        return response()->json($this->gate->validateToken($data['steam_id'], (string) ($data['token'] ?? '')));
    }

    public function validateStation(Request $request): JsonResponse
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        $data = $request->validate([
            'client_ip' => ['required', 'ip'],
            'steam_id' => ['required', 'string', 'max:32'],
            'match_id' => ['nullable', 'string', 'max:64'],
        ]);

        return response()->json($this->gate->validateStation($data['client_ip'], $data['steam_id'], $data['match_id'] ?? null));
    }

    public function heartbeatCheck(Request $request): JsonResponse
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        $data = $request->validate([
            'steam_ids' => ['required', 'array', 'max:64'],
            'steam_ids.*' => ['string', 'max:32'],
        ]);

        return response()->json($this->gate->heartbeatCheck($data['steam_ids']));
    }

    public function activeSessions(Request $request): JsonResponse
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        $data = $request->validate([
            'match_id' => ['required', 'string', 'max:64'],
        ]);

        return response()->json($this->gate->activeSessions($data['match_id']));
    }

    public function validateSession(Request $request): JsonResponse
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        $data = $request->validate([
            'steam_id' => ['required', 'string', 'max:32'],
            'match_id' => ['required', 'string', 'max:64'],
        ]);

        return response()->json($this->gate->validateSession($data['steam_id'], $data['match_id']));
    }

    public function kickAck(Request $request): JsonResponse
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        $data = $request->validate([
            'steam_id' => ['required', 'string', 'max:32'],
            'reason' => ['required', 'string', 'max:64'],
        ]);
        $this->gate->kickAck($data['steam_id'], $data['reason']);

        return response()->json(['ok' => true]);
    }

    private function guard(Request $request): ?JsonResponse
    {
        $given = $request->header('X-Reactor-Ac-Secret') ?: $request->input('server_secret');
        if (! $this->gate->secretOk(is_string($given) ? $given : null)) {
            return response()->json(['message' => 'Секрет дедика не принят'], 403);
        }

        return null;
    }
}
