<?php

namespace App\Services;

use App\Models\Club;
use App\Models\Computer;
use App\Models\ComputerMemorySample;
use Illuminate\Support\Carbon;

class MemoryLoadService
{
    public const RETENTION_HOURS = 24;

    public const CHART_HOURS = 2;

    public const STALE_SECONDS = 90;

    public function record(int $computerId, int $loadPct, int $totalMb, int $usedMb, int $availableMb): ComputerMemorySample
    {
        $computer = Computer::query()->findOrFail($computerId);
        $totalMb = max(1, $totalMb);
        $usedMb = min(max(0, $usedMb), $totalMb);
        $availableMb = min(max(0, $availableMb), $totalMb);

        $sample = ComputerMemorySample::query()->create([
            'club_id' => $computer->club_id,
            'computer_id' => $computer->id,
            'load_pct' => max(0, min(100, $loadPct)),
            'total_mb' => $totalMb,
            'used_mb' => $usedMb,
            'available_mb' => $availableMb,
            'reported_at' => now(),
        ]);

        ComputerMemorySample::query()
            ->where('computer_id', $computer->id)
            ->where('reported_at', '<', now()->subHours(self::RETENTION_HOURS))
            ->delete();

        return $sample;
    }

    /**
     * @return array{stations: array<int, array<string, mixed>>, summary: array<string, mixed>}
     */
    public function board(?Club $club): array
    {
        if (! $club) {
            return [
                'stations' => [],
                'summary' => $this->emptySummary(),
            ];
        }

        $computers = Computer::query()
            ->where('club_id', $club->id)
            ->where('kind', Computer::KIND_PC)
            ->with('space:id,name')
            ->orderBy('name')
            ->get();

        $ids = $computers->pluck('id');
        $latest = collect();
        $history = collect();

        if ($ids->isNotEmpty()) {
            $latestIds = ComputerMemorySample::query()
                ->selectRaw('MAX(id) as id')
                ->whereIn('computer_id', $ids)
                ->groupBy('computer_id')
                ->pluck('id');

            $latest = ComputerMemorySample::query()
                ->whereIn('id', $latestIds)
                ->get()
                ->keyBy('computer_id');

            $history = ComputerMemorySample::query()
                ->whereIn('computer_id', $ids)
                ->where('reported_at', '>=', now()->subHours(self::CHART_HOURS))
                ->orderBy('reported_at')
                ->get(['computer_id', 'load_pct', 'reported_at'])
                ->groupBy('computer_id');
        }

        $staleBefore = now()->subSeconds(self::STALE_SECONDS);
        $stations = [];
        foreach ($computers as $computer) {
            /** @var ComputerMemorySample|null $sample */
            $sample = $latest->get($computer->id);
            $reportedAt = $sample?->reported_at;
            $stale = $reportedAt === null || $reportedAt->lt($staleBefore);

            $stations[] = [
                'id' => $computer->id,
                'name' => $computer->name,
                'space' => $computer->space?->name,
                'power_state' => $computer->power_state,
                'load_pct' => $sample ? (int) $sample->load_pct : null,
                'used_mb' => $sample ? (int) $sample->used_mb : null,
                'total_mb' => $sample ? (int) $sample->total_mb : null,
                'available_mb' => $sample ? (int) $sample->available_mb : null,
                'reported_at' => $reportedAt?->toIso8601String(),
                'stale' => $stale,
                'series' => $this->series($history->get($computer->id, collect())),
            ];
        }

        usort($stations, function (array $a, array $b): int {
            $aMissing = $a['load_pct'] === null;
            $bMissing = $b['load_pct'] === null;
            if ($aMissing !== $bMissing) {
                return $aMissing <=> $bMissing;
            }
            if ($a['load_pct'] !== $b['load_pct']) {
                return $b['load_pct'] <=> $a['load_pct'];
            }

            return strnatcasecmp((string) $a['name'], (string) $b['name']);
        });

        return [
            'stations' => $stations,
            'summary' => $this->summary($stations),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ComputerMemorySample>  $samples
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

    /**
     * @param  array<int, array<string, mixed>>  $stations
     * @return array<string, mixed>
     */
    private function summary(array $stations): array
    {
        $fresh = array_values(array_filter($stations, fn (array $row) => $row['load_pct'] !== null && ! $row['stale']));
        $loads = array_map(fn (array $row) => (int) $row['load_pct'], $fresh);
        $peak = null;
        foreach ($fresh as $row) {
            if ($peak === null || $row['load_pct'] > $peak['load_pct']) {
                $peak = $row;
            }
        }

        return [
            'pcs' => count($stations),
            'reporting' => count($fresh),
            'hot' => count(array_filter($fresh, fn (array $row) => $row['load_pct'] >= 85)),
            'avg_load' => $loads === [] ? null : (int) round(array_sum($loads) / count($loads)),
            'peak_load' => $peak['load_pct'] ?? null,
            'peak_name' => $peak['name'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySummary(): array
    {
        return [
            'pcs' => 0,
            'reporting' => 0,
            'hot' => 0,
            'avg_load' => null,
            'peak_load' => null,
            'peak_name' => null,
        ];
    }
}
