<?php

namespace App\Services;

use App\Models\AvatarStylizeJob;
use App\Models\User;
use App\Support\UserAvatar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AvatarStylizeService
{
    public function enqueue(
        User $user,
        string $photoPng,
        string $sampleName,
        string $prompt,
        string $negative,
        string $baselineAvatar,
    ): ?AvatarStylizeJob {
        if ($photoPng === '' || ! str_starts_with($photoPng, "\x89PNG")) {
            return null;
        }

        $this->supersedeOpen($user->id);

        $job = AvatarStylizeJob::query()->create([
            'user_id' => $user->id,
            'status' => AvatarStylizeJob::STATUS_PENDING,
            'prompt' => $prompt,
            'negative' => $negative,
            'sample_name' => $sampleName !== '' ? $sampleName : 'avatar_1.png',
            'baseline_avatar' => $baselineAvatar,
        ]);

        $stored = Storage::disk('local')->put($this->photoPath($job), $photoPng);
        if ($stored === false) {
            $job->delete();

            return null;
        }

        return $job;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function claimPending(int $limit = 1): array
    {
        $this->failStale();
        $limit = max(1, min(1, $limit));

        return DB::transaction(function () use ($limit) {
            $jobs = AvatarStylizeJob::query()
                ->where('status', AvatarStylizeJob::STATUS_PENDING)
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            $out = [];
            foreach ($jobs as $job) {
                if (! Storage::disk('local')->exists($this->photoPath($job))) {
                    $this->markFailed($job, 'photo missing');

                    continue;
                }
                $job->status = AvatarStylizeJob::STATUS_CLAIMED;
                $job->claimed_at = now();
                $job->attempts = (int) $job->attempts + 1;
                $job->save();
                $out[] = $this->payload($job);
            }

            return $out;
        });
    }

    /**
     * @return array{ok: bool, replaced: bool, message: string}
     */
    public function applyPng(int $jobId, string $png): array
    {
        $job = AvatarStylizeJob::query()->find($jobId);
        if (! $job) {
            return ['ok' => false, 'replaced' => false, 'message' => 'Job not found'];
        }
        if (! in_array($job->status, [AvatarStylizeJob::STATUS_CLAIMED, AvatarStylizeJob::STATUS_PENDING], true)) {
            return ['ok' => false, 'replaced' => false, 'message' => 'Job is closed'];
        }
        if (! str_starts_with($png, "\x89PNG") || strlen($png) < 32) {
            $this->markFailed($job, 'not a png');

            return ['ok' => true, 'replaced' => false, 'message' => 'not a png'];
        }

        $user = User::query()->find($job->user_id);
        if (! $user || (string) $user->avatar !== (string) $job->baseline_avatar) {
            $this->markFailed($job, 'avatar changed');

            return ['ok' => true, 'replaced' => false, 'message' => 'avatar changed'];
        }

        $name = 'u'.$user->id.'_'.Str::lower(Str::random(12)).'.png';
        if (! Storage::disk('public')->put('avatars/'.$name, $png)) {
            $this->markFailed($job, 'store failed');

            return ['ok' => false, 'replaced' => false, 'message' => 'store failed'];
        }

        $previous = (string) $user->avatar;
        $user->forceFill(['avatar' => 'custom/'.$name])->save();
        $this->deleteCustom($previous);

        $job->status = AvatarStylizeJob::STATUS_APPLIED;
        $job->applied_at = now();
        $job->last_error = null;
        $job->save();
        $this->forgetPhoto($job);

        return ['ok' => true, 'replaced' => true, 'message' => 'applied'];
    }

    public function fail(int $jobId, string $error): bool
    {
        $job = AvatarStylizeJob::query()->find($jobId);
        if (! $job) {
            return false;
        }
        if (! in_array($job->status, [AvatarStylizeJob::STATUS_CLAIMED, AvatarStylizeJob::STATUS_PENDING], true)) {
            return false;
        }
        $this->markFailed($job, $error !== '' ? $error : 'timeout');

        return true;
    }

    public function sourceBytes(int $jobId): ?string
    {
        $job = AvatarStylizeJob::query()->find($jobId);
        if (! $job || $job->status !== AvatarStylizeJob::STATUS_CLAIMED) {
            return null;
        }
        $path = $this->photoPath($job);
        if (! Storage::disk('local')->exists($path)) {
            return null;
        }
        $bytes = Storage::disk('local')->get($path);

        return is_string($bytes) && str_starts_with($bytes, "\x89PNG") ? $bytes : null;
    }

    public function failStale(): void
    {
        $seconds = max(90, (int) config('ai_assistant.avatar.stale_seconds', 120));
        $cutoff = now()->subSeconds($seconds);
        $stale = AvatarStylizeJob::query()
            ->where('status', AvatarStylizeJob::STATUS_CLAIMED)
            ->where('claimed_at', '<', $cutoff)
            ->get();
        foreach ($stale as $job) {
            $this->markFailed($job, 'timeout');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(AvatarStylizeJob $job): array
    {
        return [
            'job_id' => (int) $job->id,
            'photo_url' => url('/api/avatar/stylize-sources/'.$job->id),
            'sample_url' => UserAvatar::url($job->sample_name),
            'prompt' => (string) $job->prompt,
            'negative' => (string) $job->negative,
            'timeout_sec' => (int) config('ai_assistant.avatar.comfyui.timeout', 90),
        ];
    }

    private function supersedeOpen(int $userId): void
    {
        $open = AvatarStylizeJob::query()
            ->where('user_id', $userId)
            ->whereIn('status', [AvatarStylizeJob::STATUS_PENDING, AvatarStylizeJob::STATUS_CLAIMED])
            ->get();
        foreach ($open as $job) {
            $this->markFailed($job, 'superseded');
        }
    }

    private function markFailed(AvatarStylizeJob $job, string $error): void
    {
        $job->status = AvatarStylizeJob::STATUS_FAILED;
        $job->applied_at = now();
        $job->last_error = Str::limit($error, 500, '');
        $job->save();
        $this->forgetPhoto($job);
    }

    private function forgetPhoto(AvatarStylizeJob $job): void
    {
        Storage::disk('local')->delete($this->photoPath($job));
    }

    private function photoPath(AvatarStylizeJob $job): string
    {
        return 'avatar-stylize/'.$job->id.'.png';
    }

    private function deleteCustom(string $avatar): void
    {
        if (! UserAvatar::isCustom($avatar)) {
            return;
        }
        Storage::disk('public')->delete('avatars/'.basename(UserAvatar::filename($avatar)));
    }
}
