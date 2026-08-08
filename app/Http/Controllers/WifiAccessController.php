<?php

namespace App\Http\Controllers;

use App\Services\WifiAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Гостевой Wi-Fi: QR → /wifi/join (walled garden) → authorize → MikroTik pull grant.
 */
class WifiAccessController extends Controller
{
    public function join(Request $request, WifiAccessService $wifi)
    {
        $station = trim((string) $request->query('station', $wifi->stationCode()));
        $mac = $request->query('mac');
        $ip = $request->query('ip') ?: $request->ip();

        return response()
            ->view('wifi.join', [
                'enabled' => $wifi->enabled(),
                'station' => $station,
                'mac' => is_string($mac) ? $mac : '',
                'ip' => is_string($ip) ? $ip : '',
                'authenticated' => Auth::guard('web')->check(),
                'user' => Auth::guard('web')->user(),
                'loginUrl' => url('/login').'?redirect='.urlencode($request->fullUrl()),
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    public function authorize(Request $request, WifiAccessService $wifi)
    {
        $data = $request->validate([
            'station' => 'required|string|max:64',
            'mac' => 'nullable|string|max:32',
            'ip' => 'nullable|string|max:64',
        ]);

        $user = $request->user('web');
        if (! $user) {
            return response()->json(['status' => 'error', 'message' => 'Нужна авторизация'], 401);
        }

        try {
            $session = $wifi->authorize(
                $user,
                $data['station'],
                $data['mac'] ?? null,
                $data['ip'] ?? $request->ip(),
                $request->userAgent(),
            );
        } catch (RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'status' => 'success',
            'session' => [
                'id' => $session->id,
                'mac' => $session->mac_address,
                'ip' => $session->client_ip,
                'status' => $session->status,
                'expires_at' => optional($session->expires_at)?->toIso8601String(),
            ],
            'message' => $session->mac_address
                ? 'Доступ запрошен. MikroTik применит grant в ближайшем poll.'
                : 'Сессия создана без MAC. Передайте mac из Hotspot ($(mac)) или дозаполните с роутера.',
        ]);
    }

    public function revoke(Request $request, WifiAccessService $wifi)
    {
        $user = $request->user('web');
        if (! $user) {
            return response()->json(['status' => 'error', 'message' => 'Нужна авторизация'], 401);
        }

        $data = $request->validate([
            'session_id' => 'nullable|integer|exists:wifi_access_sessions,id',
        ]);

        $q = \App\Models\WifiAccessSession::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [
                \App\Models\WifiAccessSession::STATUS_PENDING,
                \App\Models\WifiAccessSession::STATUS_GRANTED,
            ]);

        if (! empty($data['session_id'])) {
            $q->where('id', $data['session_id']);
        }

        $n = 0;
        foreach ($q->get() as $session) {
            $wifi->revoke($session, 'user');
            $n++;
        }

        return response()->json(['status' => 'success', 'revoked' => $n]);
    }
}
