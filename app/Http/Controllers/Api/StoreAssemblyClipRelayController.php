<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StoreAssemblyClipJob;
use App\Services\StoreAssemblyCaptureService;
use App\Services\VideoMarkerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Pull/upload API: LAN-агент выгружает ролик сборки с NVR (камера стола).
 *
 * GET  /api/video/assembly-clip-targets?token=…
 * POST /api/video/assembly-clips { token, job_id, clip }
 * POST /api/video/assembly-clip-applied { token, sent_ids?, failed? }
 */
class StoreAssemblyClipRelayController extends Controller
{
    public function targets(Request $request, StoreAssemblyCaptureService $capture, VideoMarkerService $markers)
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

        $limit = (int) $request->query('limit', 3);
        $jobs = $capture->claimPending($limit, $s->club_id ? (int) $s->club_id : $clubId);

        return response()->json([
            'status' => 'success',
            'enabled' => true,
            'count' => count($jobs),
            'jobs' => $jobs,
            'nvr' => $markers->agentNvrAuth($s->club_id ? (int) $s->club_id : $clubId),
        ]);
    }

    public function upload(Request $request, StoreAssemblyCaptureService $capture)
    {
        if (! $this->tokenOk($request)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $data = $request->validate([
            'job_id' => 'required|integer',
            'clip' => 'required|file|mimetypes:video/mp4,application/octet-stream|max:98304',
        ]);

        $job = StoreAssemblyClipJob::query()
            ->with('builtPc')
            ->find((int) $data['job_id']);

        if (! $job || ! $job->builtPc) {
            return response()->json(['status' => 'error', 'message' => 'Job not found'], 404);
        }

        $capture->storeClip($job->builtPc, $request->file('clip'));
        $capture->markSent([(int) $job->id]);

        return response()->json([
            'status' => 'success',
            'built_pc_id' => (int) $job->store_built_pc_id,
        ]);
    }

    public function applied(Request $request, StoreAssemblyCaptureService $capture)
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

        $sent = $capture->markSent($request->input('sent_ids', []) ?: []);
        $failed = $capture->markFailed($request->input('failed', []) ?: []);

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
            Log::warning('Assembly clip relay: VIDEO_MARKER_RELAY_TOKEN empty — rejecting');

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
