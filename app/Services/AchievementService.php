<?php

namespace App\Services;

use App\Models\Achievement;
use App\Models\Booking;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserAchievement;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AchievementService
{
    /**
     * Recompute progress and auto-award for a user after a session ends.
     *
     * @return list<array{achievement_id:int,title:string,reward_value:float,reward_type:string}>
     */
    public function evaluateForUser(User $user): array
    {
        $awarded = [];

        $achievements = Achievement::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        foreach ($achievements as $achievement) {
            try {
                $result = $this->evaluateOne($user, $achievement);
                if ($result !== null) {
                    $awarded[] = $result;
                }
            } catch (\Throwable $e) {
                Log::error('Achievement evaluate failed', [
                    'user_id' => $user->id,
                    'achievement_id' => $achievement->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $awarded;
    }

    /**
     * Evaluate users who just completed bookings (batch hook).
     *
     * @param  Collection<int, Booking>|iterable<Booking>  $bookings
     */
    public function evaluateForBookings(iterable $bookings): void
    {
        $userIds = collect($bookings)
            ->pluck('user_id')
            ->filter()
            ->unique()
            ->values();

        foreach ($userIds as $userId) {
            $user = User::find($userId);
            if ($user) {
                $this->evaluateForUser($user);
            }
        }
    }

    /**
     * Progress cards for the player cabinet.
     *
     * @return list<array<string, mixed>>
     */
    public function progressForUser(User $user): array
    {
        $achievements = Achievement::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $cards = [];
        foreach ($achievements as $achievement) {
            $periodKey = $this->periodKey($achievement);
            $progress = $this->computeProgress($user, $achievement, $periodKey);
            $row = UserAchievement::query()
                ->where('user_id', $user->id)
                ->where('achievement_id', $achievement->id)
                ->where('period_key', $periodKey)
                ->first();

            $target = (float) $achievement->target_value;
            $current = min($progress, $target);
            $completed = $row?->isCompleted() || ($target > 0 && $progress >= $target);

            $cards[] = [
                'id' => $achievement->id,
                'title' => $achievement->title,
                'description' => $achievement->description,
                'type' => $achievement->type,
                'type_label' => $achievement->typeLabel(),
                'period' => $achievement->period,
                'period_label' => $achievement->periodLabel(),
                'period_key' => $periodKey,
                'target' => $target,
                'progress' => round($current, 2),
                'percent' => $target > 0 ? min(100, round(($current / $target) * 100, 1)) : 0,
                'reward_type' => $achievement->reward_type,
                'reward_value' => (float) $achievement->reward_value,
                'completed' => (bool) $completed,
                'rewarded' => (bool) ($row?->isRewarded()),
                'completed_at' => $row?->completed_at?->toIso8601String(),
            ];
        }

        return $cards;
    }

    /**
     * @return array{achievement_id:int,title:string,reward_value:float,reward_type:string}|null
     */
    protected function evaluateOne(User $user, Achievement $achievement): ?array
    {
        $periodKey = $this->periodKey($achievement);

        // Once-only: skip if already rewarded in any period row
        if ($achievement->period === Achievement::PERIOD_ONCE) {
            $already = UserAchievement::query()
                ->where('user_id', $user->id)
                ->where('achievement_id', $achievement->id)
                ->whereNotNull('rewarded_at')
                ->exists();
            if ($already) {
                return null;
            }
        }

        $progress = $this->computeProgress($user, $achievement, $periodKey);
        $target = (float) $achievement->target_value;

        $row = UserAchievement::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'achievement_id' => $achievement->id,
                'period_key' => $periodKey,
            ],
            ['progress' => 0]
        );

        if ($row->isRewarded()) {
            if ((float) $row->progress !== $progress) {
                $row->update(['progress' => $progress]);
            }
            return null;
        }

        $row->progress = $progress;

        if ($target > 0 && $progress >= $target && ! $row->isCompleted()) {
            $row->completed_at = now();
        }

        $row->save();

        if (! $row->isCompleted() || $row->isRewarded()) {
            return null;
        }

        return $this->award($user, $achievement, $row);
    }

    /**
     * @return array{achievement_id:int,title:string,reward_value:float,reward_type:string}
     */
    protected function award(User $user, Achievement $achievement, UserAchievement $row): array
    {
        return DB::transaction(function () use ($user, $achievement, $row) {
            $locked = UserAchievement::query()
                ->whereKey($row->id)
                ->lockForUpdate()
                ->first();

            if (! $locked || $locked->isRewarded()) {
                return [
                    'achievement_id' => $achievement->id,
                    'title' => $achievement->title,
                    'reward_value' => (float) $achievement->reward_value,
                    'reward_type' => $achievement->reward_type,
                ];
            }

            $wallet = $user->wallet()->firstOrCreate(['user_id' => $user->id]);
            $amount = (float) $achievement->reward_value;

            if ($amount > 0) {
                if ($achievement->reward_type === Achievement::REWARD_BONUS) {
                    $wallet->increment('bonus_balance', $amount);
                } else {
                    $wallet->creditSpendable($amount);
                }

                Transaction::create([
                    'user_id' => $user->id,
                    'amount' => $amount,
                    'type' => 'deposit',
                    'source' => 'achievement',
                    'description' => "Ачивка: {$achievement->title}",
                    'payload' => [
                        'achievement_id' => $achievement->id,
                        'period_key' => $locked->period_key,
                        'reward_type' => $achievement->reward_type,
                    ],
                ]);
            }

            $locked->update([
                'rewarded_at' => now(),
                'completed_at' => $locked->completed_at ?? now(),
            ]);

            return [
                'achievement_id' => $achievement->id,
                'title' => $achievement->title,
                'reward_value' => $amount,
                'reward_type' => $achievement->reward_type,
            ];
        });
    }

    public function periodKey(Achievement $achievement, ?CarbonInterface $at = null): string
    {
        $at = Carbon::parse($at ?? now());

        return match ($achievement->period) {
            Achievement::PERIOD_WEEKLY => $at->isoFormat('GGGG-[W]WW'),
            Achievement::PERIOD_MONTHLY => $at->format('Y-m'),
            default => 'once',
        };
    }

    public function periodBounds(Achievement $achievement, string $periodKey): array
    {
        if ($achievement->period === Achievement::PERIOD_WEEKLY) {
            // GGGG-Www
            if (preg_match('/^(\d{4})-W(\d{2})$/', $periodKey, $m)) {
                $start = Carbon::now()->setISODate((int) $m[1], (int) $m[2])->startOfWeek();
                $end = $start->copy()->endOfWeek();
                return [$start, $end];
            }
        }

        if ($achievement->period === Achievement::PERIOD_MONTHLY) {
            if (preg_match('/^(\d{4})-(\d{2})$/', $periodKey, $m)) {
                $start = Carbon::create((int) $m[1], (int) $m[2], 1)->startOfMonth();
                $end = $start->copy()->endOfMonth();
                return [$start, $end];
            }
        }

        return [Carbon::create(2000, 1, 1)->startOfDay(), Carbon::now()->addYear()];
    }

    public function computeProgress(User $user, Achievement $achievement, string $periodKey): float
    {
        [$start, $end] = $this->periodBounds($achievement, $periodKey);

        $bookings = Booking::query()
            ->where('user_id', $user->id)
            ->where('status', 'completed')
            ->where(function ($q) use ($start, $end) {
                $q->where(function ($modern) use ($start, $end) {
                    $modern->whereNotNull('actual_started_at')
                        ->where('actual_started_at', '>=', $start)
                        ->where('actual_started_at', '<=', $end);
                })->orWhere(function ($planned) use ($start, $end) {
                    $planned->whereNull('actual_started_at')
                        ->whereNotNull('starts_at')
                        ->where('starts_at', '>=', $start)
                        ->where('starts_at', '<=', $end);
                })->orWhere(function ($legacy) use ($start, $end) {
                    $legacy->whereNull('actual_started_at')
                        ->whereNull('starts_at')
                        ->whereNotNull('date')
                        ->whereDate('date', '>=', $start->toDateString())
                        ->whereDate('date', '<=', $end->toDateString());
                });
            })
            ->get();

        return match ($achievement->type) {
            Achievement::TYPE_PLAY_HOURS => round($bookings->sum(fn (Booking $b) => $this->playedHours($b)), 2),
            Achievement::TYPE_VISIT_COUNT => (float) $bookings->count(),
            Achievement::TYPE_NIGHT_VISITS => (float) $bookings
                ->filter(fn (Booking $b) => $this->isNightVisit($b, $achievement))
                ->count(),
            default => 0.0,
        };
    }

    public function playedHours(Booking $booking): float
    {
        if ($booking->actual_started_at && $booking->actual_ended_at) {
            $seconds = $booking->actual_started_at->diffInSeconds($booking->actual_ended_at);
            return max(0, $seconds) / 3600;
        }

        if ($booking->starts_at && $booking->ends_at) {
            $seconds = $booking->starts_at->diffInSeconds($booking->ends_at);
            return max(0, $seconds) / 3600;
        }

        return max(0, (float) ($booking->duration ?? 0));
    }

    public function isNightVisit(Booking $booking, Achievement $achievement): bool
    {
        $at = $booking->actual_started_at
            ?? $booking->starts_at
            ?? ($booking->date
                ? Carbon::parse($booking->date->format('Y-m-d'))->addHours((int) floor((float) $booking->start_time))
                : null);

        if (! $at) {
            return false;
        }

        $hour = (int) $at->format('G');
        $nightStart = (int) $achievement->night_start;
        $nightEnd = (int) $achievement->night_end;

        if ($nightStart === $nightEnd) {
            return true;
        }

        // e.g. 22–6: hour >= 22 OR hour < 6
        if ($nightStart > $nightEnd) {
            return $hour >= $nightStart || $hour < $nightEnd;
        }

        return $hour >= $nightStart && $hour < $nightEnd;
    }
}
