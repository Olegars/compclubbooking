<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IncidentClipJob;
use App\Services\IncidentClipService;
use App\Services\VideoMarkerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Pull/upload API: LAN-агент вырезает эпизод инцидента с NVR.
 *
 * GET  /api/video/incident-clip-targets?token=…
 * POST /api/video/incident-clips { token, job_id, clip }
 * POST /api/video/incident-clip-failed { token, job_id, error }
 * POST /api/video/incident-clip-applied { token, sent_ids?, failed? }
 */
class IncidentClipRelayController extends Controller
{
    public function targets(Request $request, IncidentClipService $clips, VideoMarkerService $markers)
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

        $limit = (int) $request->query('limit', (int) config('video_surveillance.incident_clip_claim_limit', 2));
        $jobs = $clips->claimPending($limit, $s->club_id ? (int) $s->club_id : $clubId);

        return response()->json([
            'status' => 'success',
            'enabled' => true,
            'count' => count($jobs),
            'jobs' => $jobs,
            'nvr' => $markers->agentNvrAuth($s->club_id ? (int) $s->club_id : $clubId),
        ]);
    }

    public function upload(Request $request, IncidentClipService $clips)
    {
        if (! $this->tokenOk($request)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $maxKb = max(1024, (int) config('video_surveillance.incident_clip_max_kb', 98304));
        $data = $request->validate([
            'job_id' => 'required|integer',
            'clip' => 'required|file|mimetypes:video/mp4,application/octet-stream|max:'.$maxKb,
        ]);

        $job = IncidentClipJob::query()->find((int) $data['job_id']);
        if (! $job) {
            return response()->json(['status' => 'error', 'message' => 'Job not found'], 404);
        }

        $clips->storeClip($job, $request->file('clip'));

        return response()->json([
            'status' => 'success',
            'job_id' => (int) $job->id,
            'subject_type' => $job->subject_type,
            'subject_id' => (int) $job->subject_id,
        ]);
    }

    public function failed(Request $request, IncidentClipService $clips)
    {
        if (! $this->tokenOk($request)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $data = $request->validate([
            'job_id' => 'required|integer',
            'error' => 'nullable|string|max:500',
        ]);

        $updated = $clips->markFailed([[
            'id' => (int) $data['job_id'],
            'error' => $data['error'] ?? 'ffmpeg timeout',
        ]]);

        return response()->json([
            'status' => 'success',
            'failed' => $updated,
        ]);
    }

    public function applied(Request $request, IncidentClipService $clips)
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

        $sent = $clips->markSent($request->input('sent_ids', []) ?: []);
        $failed = $clips->markFailed($request->input('failed', []) ?: []);

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
            Log::warning('Incident clip relay: VIDEO_MARKER_RELAY_TOKEN empty — rejecting');

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
