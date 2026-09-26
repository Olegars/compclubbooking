<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Computer;
use App\Models\User;
use App\Models\UserFeatureDailyStat;
use App\Models\UserFeatureEvent;
use App\Support\UserFeatureCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class UserFeatureTelemetry
{
    private const COALESCE_SECONDS = 2;

    public function record(
        string $featureKey,
        ?User $user,
        ?string $sourceClient = null,
        array $payload = [],
        ?int $bookingId = null,
        ?int $computerId = null,
    ): void {
        if (! $user || ! $user->id) {
            return;
        }
        $def = UserFeatureCatalog::get($featureKey);
        if (! $def) {
            return;
        }

        try {
            $computer = $computerId ? Computer::query()->find($computerId) : null;
            $bookingId = $bookingId ?: $this->activeBookingId($user, $computer);
            if (! $bookingId) {
                $bookingId = $this->anyActiveBookingId($user);
            }
            $clubId = $computer?->club_id ? (int) $computer->club_id : $this->clubIdFromBooking($bookingId);
            $source = $sourceClient ?: ($computer && $computer->isTvBoothSeat()
                ? UserFeatureCatalog::SOURCE_TV
                : UserFeatureCatalog::SOURCE_PC);

            if ($def['coalesce']) {
                $recent = UserFeatureEvent::query()
                    ->where('user_id', $user->id)
                    ->where('feature_key', $featureKey)
                    ->where('created_at', '>=', now()->subSeconds(self::COALESCE_SECONDS))
                    ->latest('id')
                    ->first();
                if ($recent) {
                    $recent->forceFill([
                        'payload' => $payload ?: $recent->payload,
                        'created_at' => now(),
                    ])->save();

                    return;
                }
            }

            UserFeatureEvent::query()->create([
                'user_id' => (int) $user->id,
                'booking_id' => $bookingId,
                'computer_id' => $computer?->id,
                'club_id' => $clubId,
                'terminal_id' => $computer?->name,
                'feature_key' => $featureKey,
                'source_client' => $source,
                'payload' => $payload ?: null,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('User feature telemetry skipped: '.$e->getMessage(), [
                'feature_key' => $featureKey,
                'user_id' => $user->id,
            ]);
        }
    }

    public function aggregateDate(CarbonImmutable $date): int
    {
        $from = $date->startOfDay();
        $to = $date->endOfDay();
        $dayExpr = $this->dayExpression('created_at');

        $rows = UserFeatureEvent::query()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw("feature_key, source_client, club_id, count(*) as total_actions, count(distinct user_id) as unique_users, count(distinct terminal_id) as unique_stations, {$dayExpr} as day")
            ->groupBy('feature_key', 'source_client', 'club_id', DB::raw($dayExpr))
            ->get();

        UserFeatureDailyStat::query()->whereDate('date', $from->toDateString())->delete();

        foreach ($rows as $row) {
            UserFeatureDailyStat::query()->create([
                'date' => $from->toDateString(),
                'club_id' => $row->club_id,
                'feature_key' => $row->feature_key,
                'source_client' => $row->source_client,
                'total_actions' => (int) $row->total_actions,
                'unique_users' => (int) $row->unique_users,
                'unique_stations' => (int) $row->unique_stations,
            ]);
        }

        return $rows->count();
    }

    public function pruneOlderThan(int $days): int
    {
        $days = max(1, $days);

        return UserFeatureEvent::query()
            ->where('created_at', '<', now()->subDays($days))
            ->delete();
    }

    public function dayExpression(string $column): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? "({$column})::date"
            : "date({$column})";
    }

    private function anyActiveBookingId(User $user): ?int
    {
        $id = Booking::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->latest('id')
            ->value('id');

        return $id ? (int) $id : null;
    }

    private function activeBookingId(User $user, ?Computer $computer): ?int
    {
        if (! $computer) {
            return null;
        }
        $id = Booking::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->where('computer_id', $computer->id)
            ->latest('id')
            ->value('id');

        return $id ? (int) $id : null;
    }

    private function clubIdFromBooking(?int $bookingId): ?int
    {
        if (! $bookingId) {
            return null;
        }
        $computerId = Booking::query()->whereKey($bookingId)->value('computer_id');
        if (! $computerId) {
            return null;
        }
        $clubId = Computer::query()->whereKey($computerId)->value('club_id');

        return $clubId ? (int) $clubId : null;
    }
}
