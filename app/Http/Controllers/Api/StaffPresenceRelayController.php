<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StaffEdoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * FACE-01 сообщает, что на ресепшене видно лицо дежурного администратора.
 * POST /api/video/staff-presence { token, admin_id, seen_at? }
 */
class StaffPresenceRelayController extends Controller
{
    public function store(Request $request, StaffEdoService $edo)
    {
        if (! $this->tokenOk($request)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $data = $request->validate([
            'admin_id' => ['required', 'integer'],
            'seen_at' => ['nullable', 'date'],
        ]);

        try {
            $edo->recordPresence(
                (int) $data['admin_id'],
                isset($data['seen_at']) ? \Carbon\Carbon::parse($data['seen_at']) : null,
                'face'
            );
        } catch (RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 404);
        }

        return response()->json(['status' => 'success']);
    }

    private function tokenOk(Request $request): bool
    {
        $expected = (string) config('video_surveillance.relay_token', '');
        if ($expected === '') {
            Log::warning('Staff presence relay: VIDEO_MARKER_RELAY_TOKEN empty — rejecting');

            return false;
        }

        $given = (string) (
            $request->query('token')
            ?? $request->header('X-Video-Marker-Token')
            ?? $request->input('token')
            ?? ''
        );

        return hash_equals($expected, $given);
    }
}
