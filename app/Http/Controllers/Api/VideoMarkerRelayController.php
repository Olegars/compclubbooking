<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\VideoMarkerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Pull-API для LAN-агента меток Hikvision NVR (ISAPI Digest).
 *
 * GET  /api/video/marker-targets?token=…
 * POST /api/video/marker-applied { token, sent_ids?: [], failed?: [{ id, error }] }
 */
class VideoMarkerRelayController extends Controller
{
    public function targets(Request $request, VideoMarkerService $markers)
    {
        if (! $this->tokenOk($request)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $clubId = $request->filled('club_id') ? (int) $request->query('club_id') : null;
        $s = $markers->settings($clubId);

        if (! $s->is_enabled || $s->provider !== 'hikvision') {
            return response()->json([
                'status' => 'success',
                'enabled' => false,
                'count' => 0,
                'jobs' => [],
                'nvr' => null,
            ]);
        }

        $stale = (int) config('video_surveillance.stale_claim_minutes', 2);
        $markers->releaseStaleClaims($stale);

        $limit = (int) $request->query('limit', config('video_surveillance.claim_limit', 10));
        $jobs = $markers->claimPending($limit, $s->club_id ? (int) $s->club_id : $clubId);

        return response()->json([
            'status' => 'success',
            'enabled' => true,
            'count' => count($jobs),
            'jobs' => $jobs,
            'nvr' => $markers->agentNvrAuth($s->club_id ? (int) $s->club_id : $clubId),
        ]);
    }

    public function applied(Request $request, VideoMarkerService $markers)
    {
        if (! $this->tokenOk($request)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $request->validate([
            'sent_ids' => 'nullable|array',
            'sent_ids.*' => 'integer',
            'failed' => 'nullable|array',
            'failed.*.id' => 'required|integer',
            'failed.*.error' => 'nullable|string|max:500',
        ]);

        $ids = $request->input('sent_ids', []) ?: [];
        $failedRows = $request->input('failed', []) ?: [];

        $sent = $markers->markSent($ids);
        $failed = $markers->markFailed($failedRows);

        return response()->json([
            'status' => 'success',
            'sent' => $sent,
            'failed' => $failed,
        ]);
    }

    private function tokenOk(Request $request): bool
    {
        $expected = (string) config('video_surveillance.relay_token', '');
        if ($expected === '') {
            Log::warning('Video marker relay: VIDEO_MARKER_RELAY_TOKEN / CLUB_WOL_RELAY_TOKEN empty — rejecting');

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
