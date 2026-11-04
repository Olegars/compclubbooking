<?php

namespace App\Services;

use App\Models\HostMemorySample;
use Illuminate\Support\Carbon;

class HostMemoryService
{
    /**
     * Latest RAM sample of the Windows Server host.
     * Written by the Sector0451HostMemory service, not by the shell.
     *
     * @return array<string, mixed>|null
     */
    public function snapshot(): ?array
    {
        $latest = HostMemorySample::query()->orderByDesc('id')->first();
        if (! $latest) {
            return null;
        }

        $history = HostMemorySample::query()
            ->where('reported_at', '>=', now()->subHours(MemoryLoadService::CHART_HOURS))
            ->orderBy('reported_at')
            ->get(['load_pct', 'reported_at']);

        $reportedAt = $latest->reported_at;
        $stale = $reportedAt === null
            || $reportedAt->lt(now()->subSeconds(MemoryLoadService::STALE_SECONDS));

        return [
            'name' => 'Сервер',
            'hostname' => $latest->hostname,
            'load_pct' => (int) $latest->load_pct,
            'used_mb' => (int) $latest->used_mb,
            'total_mb' => (int) $latest->total_mb,
            'available_mb' => (int) $latest->available_mb,
            'reported_at' => $reportedAt?->toIso8601String(),
            'stale' => $stale,
            'series' => $this->series($history),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, HostMemorySample>  $samples
     * @return array<int, array{at: string, load_pct: int}>
     */
    private function series($samples): array
    {
        $buckets = [];
        foreach ($samples as $sample) {
            $at = $sample->reported_at;
            if (! $at instanceof Carbon) {
                continue;
            }
            $buckets[$at->format('Y-m-d H:i')] = [
                'at' => $at->toIso8601String(),
                'load_pct' => (int) $sample->load_pct,
            ];
        }

        return array_values($buckets);
    }
}
