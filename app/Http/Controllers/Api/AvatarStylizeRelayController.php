<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AvatarStylizeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Pull-API стилизации аватара. Облако на ComfyUI не ходит.
 *
 * GET  /api/avatar/stylize-targets?token=
 * GET  /api/avatar/stylize-sources/{id}?token=
 * POST /api/avatar/stylize-applied { token, job_id, image | status=failed, error? }
 */
class AvatarStylizeRelayController extends Controller
{
    public function targets(Request $request, AvatarStylizeService $queue)
    {
        if (! $this->tokenOk($request)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $jobs = $queue->claimPending(1);

        return response()->json([
            'status' => 'success',
            'count' => count($jobs),
            'jobs' => $jobs,
            'comfyui' => 'http://127.0.0.1:8188',
        ]);
    }

    public function source(Request $request, AvatarStylizeService $queue, int $id)
    {
        if (! $this->tokenOk($request)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $bytes = $queue->sourceBytes($id);
        if ($bytes === null) {
            return response()->json(['status' => 'error', 'message' => 'Not found'], 404);
        }

        return response($bytes, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function applied(Request $request, AvatarStylizeService $queue)
    {
        if (! $this->tokenOk($request)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $data = $request->validate([
            'job_id' => 'required|integer',
            'status' => 'nullable|string|in:failed,applied',
            'error' => 'nullable|string|max:500',
        ]);

        $jobId = (int) $data['job_id'];
        if (($data['status'] ?? '') === 'failed') {
            $ok = $queue->fail($jobId, (string) ($data['error'] ?? 'timeout'));

            return response()->json([
                'status' => 'success',
                'failed' => $ok,
                'replaced' => false,
            ]);
        }

        $png = $this->pngBytes($request);
        if ($png === null) {
            return response()->json(['status' => 'error', 'message' => 'PNG required'], 422);
        }

        $result = $queue->applyPng($jobId, $png);
        $code = $result['ok'] ? 200 : ($result['message'] === 'Job not found' ? 404 : 409);

        return response()->json([
            'status' => $result['ok'] ? 'success' : 'error',
            'replaced' => $result['replaced'],
            'message' => $result['message'],
        ], $code);
    }

    private function pngBytes(Request $request): ?string
    {
        $file = $request->file('image');
        if ($file !== null && $file->isValid()) {
            $bytes = (string) file_get_contents($file->getRealPath() ?: '');
            if (str_starts_with($bytes, "\x89PNG")) {
                return $bytes;
            }
        }

        $raw = $request->input('image');
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = base64_decode($raw, true);

        return is_string($decoded) && str_starts_with($decoded, "\x89PNG") ? $decoded : null;
    }

    private function tokenOk(Request $request): bool
    {
        $expected = (string) config('ai_assistant.avatar.relay_token', '');
        if ($expected === '') {
            Log::warning('Avatar stylize relay: AVATAR_RELAY_TOKEN empty — rejecting');

            return false;
        }

        $given = (string) (
            $request->query('token')
            ?? $request->header('X-Avatar-Relay-Token')
            ?? $request->input('token')
            ?? ''
        );

        return hash_equals($expected, $given);
    }
}
