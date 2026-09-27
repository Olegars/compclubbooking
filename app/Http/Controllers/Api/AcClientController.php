<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcBan;
use App\Services\ReactorAc\AcGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AcClientController extends Controller
{
    public function __construct(private readonly AcGate $gate)
    {
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'password' => ['required', 'string', 'max:200'],
        ]);
        $result = $this->gate->login($data['phone'], $data['password']);

        return response()->json($result['body'], $result['status']);
    }

    public function policy(Request $request): JsonResponse
    {
        $session = $this->gate->sessionFromBearer($request->bearerToken());
        if (! $session) {
            return response()->json(['message' => 'Нет сессии клиента'], 401);
        }

        return response()->json($this->gate->policy($session->club_id ? (int) $session->club_id : null));
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $session = $this->gate->sessionFromBearer($request->bearerToken());
        if (! $session) {
            return response()->json(['message' => 'Нет сессии клиента'], 401);
        }
        $data = $request->validate([
            'steam_id' => ['required', 'string', 'max:32'],
            'hwid' => ['required', 'string', 'max:64'],
            'testsigning' => ['nullable', 'boolean'],
            'vm' => ['nullable', 'boolean'],
            'integrity_ok' => ['nullable', 'boolean'],
            'findings' => ['nullable', 'array', 'max:20'],
            'findings.*' => ['string', 'max:120'],
            'client_version' => ['nullable', 'string', 'max:32'],
            'os' => ['nullable', 'string', 'max:64'],
        ]);
        $result = $this->gate->heartbeat($session, $data);

        return response()->json($result['body'], $result['status']);
    }

    public function connectToken(Request $request): JsonResponse
    {
        $session = $this->gate->sessionFromBearer($request->bearerToken());
        if (! $session) {
            return response()->json(['message' => 'Нет сессии клиента'], 401);
        }
        $data = $request->validate([
            'match_id' => ['required', 'string', 'max:64'],
            'scope' => ['nullable', 'string', 'in:match_making,tournament'],
        ]);
        $result = $this->gate->issueToken(
            $session,
            $data['match_id'],
            $data['scope'] ?? AcBan::SCOPE_MATCH_MAKING,
        );

        return response()->json($result['body'], $result['status']);
    }

    public function events(Request $request): JsonResponse
    {
        $session = $this->gate->sessionFromBearer($request->bearerToken());
        if (! $session) {
            return response()->json(['message' => 'Нет сессии клиента'], 401);
        }
        $data = $request->validate([
            'kind' => ['required', 'string', 'max:32'],
            'message' => ['nullable', 'string', 'max:255'],
        ]);
        \App\Models\AcEvent::query()->create([
            'user_id' => $session->user_id,
            'session_id' => $session->id,
            'club_id' => $session->club_id,
            'kind' => $data['kind'],
            'steam_id' => $session->steam_id,
            'message' => $data['message'] ?? null,
        ]);

        return response()->json(['ok' => true]);
    }

    public function evidence(Request $request): JsonResponse
    {
        $session = $this->gate->sessionFromBearer($request->bearerToken());
        if (! $session) {
            return response()->json(['message' => 'Нет сессии клиента'], 401);
        }
        $data = $request->validate([
            'kind' => ['required', 'string', 'in:screenshot,proclist'],
            'file' => ['required', 'file', 'max:3072'],
        ]);
        $result = $this->gate->storeEvidence($session, $data['kind'], $data['file']);

        return response()->json($result['body'], $result['status']);
    }
}
