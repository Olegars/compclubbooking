<?php

namespace App\Services;

use App\Models\IncidentClipJob;
use App\Models\StoreAssemblyClipJob;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Один ffmpeg на FACE-01. Пока стол сборки или эпизод инцидента в статусе claimed,
 * вторая очередь не отдаётся: параллельный RTSP в OpenCV и второй ffmpeg
 * дают всплеск RAM и задержку шины.
 */
class ClipExportSlot
{
    public function releaseStale(): void
    {
        app(StoreAssemblyCaptureService::class)->releaseStaleClaims(
            (int) config('video_surveillance.assembly_clip_stale_minutes', 30)
        );
        app(IncidentClipService::class)->releaseStaleClaims(
            (int) config('video_surveillance.incident_clip_stale_minutes', 4)
        );
    }

    public function held(): bool
    {
        $assemblyFresh = now()->subMinutes(max(1, (int) config('video_surveillance.assembly_clip_stale_minutes', 30)));
        $incidentFresh = now()->subMinutes(max(1, (int) config('video_surveillance.incident_clip_stale_minutes', 4)));

        $assembly = StoreAssemblyClipJob::query()
            ->where('status', StoreAssemblyClipJob::STATUS_CLAIMED)
            ->where(function ($q) use ($assemblyFresh) {
                $q->whereNull('claimed_at')->orWhere('claimed_at', '>=', $assemblyFresh);
            })
            ->exists();

        if ($assembly) {
            return true;
        }

        return IncidentClipJob::query()
            ->where('status', IncidentClipJob::STATUS_CLAIMED)
            ->where(function ($q) use ($incidentFresh) {
                $q->whereNull('claimed_at')->orWhere('claimed_at', '>=', $incidentFresh);
            })
            ->exists();
    }

    public function exclusive(callable $callback): mixed
    {
        try {
            return Cache::lock('clip-export-ffmpeg', 15)->block(5, $callback);
        } catch (LockTimeoutException) {
            return [];
        }
    }
}
