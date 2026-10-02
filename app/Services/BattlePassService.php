<?php

namespace App\Services;

use App\Models\Achievement;
use App\Models\AchievementBadge;
use App\Models\AchievementSourceSetting;
use App\Models\ArenaDuel;
use App\Models\ArenaRating;
use App\Models\BattlePassClaim;
use App\Models\BattlePassLevel;
use App\Models\BattlePassSeason;
use App\Models\Booking;
use App\Models\ClubStatus;
use App\Models\CosmeticFrame;
use App\Models\LadderRewardTier;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\UserAchievement;
use App\Models\UserBadgeShowcase;
use App\Models\UserBattlePass;
use App\Models\UserCosmetic;
use App\Models\UserRewardGrant;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BattlePassService
{
    public function __construct(private readonly LoyaltyRewardService $rewards)
    {
    }

    public function clubIdFor(User $user): ?int
    {
        return app(ClubFeatureService::class)->clubIdForUser($user);
    }

    public function settings(?int $clubId): AchievementSourceSetting
    {
        $clubId = $clubId && $clubId > 0 ? $clubId : null;
        if (! $clubId) {
            return new AchievementSourceSetting([
                'gsi_awards' => true,
                'showcase_slots' => 6,
            ]);
        }

        return AchievementSourceSetting::query()->firstOrCreate(
            ['club_id' => $clubId],
            [
                'gsi_awards' => true,
                'showcase_slots' => 6,
                'caffeine_categories' => ['energy', 'coffee'],
                'food_categories' => ['combo', 'sandwich'],
            ],
        );
    }

    public function liveSeason(?int $clubId): ?BattlePassSeason
    {
        if (! $clubId) {
            return null;
        }
        $this->rollSeasons($clubId);
        $season = BattlePassSeason::query()
            ->where('club_id', $clubId)
            ->where('is_active', true)
            ->where('status', BattlePassSeason::LIVE)
            ->first();
        if ($season && $season->ends_on && now()->greaterThan($season->ends_on->copy()->endOfDay())) {
            $this->close($season);

            return null;
        }

        return $season;
    }

    public function claimableSeason(User $user, ?int $clubId): ?BattlePassSeason
    {
        if (! $clubId) {
            return null;
        }
        $this->rollSeasons($clubId);
        $row = UserBattlePass::query()
            ->where('user_id', $user->id)
            ->whereIn('season_id', BattlePassSeason::query()->where('club_id', $clubId)->select('id'))
            ->orderByDesc('id')
            ->first();
        if (! $row) {
            return $this->liveSeason($clubId);
        }
        $season = BattlePassSeason::query()->find($row->season_id);
        if (! $season) {
            return null;
        }
        if ($season->status === BattlePassSeason::LIVE) {
            return $season;
        }
        if ($season->status === BattlePassSeason::XP_CLOSED && now()->lte($this->graceUntil($season))) {
            return $season;
        }

        return $this->liveSeason($clubId);
    }

    public function graceUntil(BattlePassSeason $season): CarbonInterface
    {
        $days = max(7, min(14, (int) $season->claim_grace_days));

        return $season->ends_on->copy()->endOfDay()->addDays($days);
    }

    public function addXp(User $user, int $xp, ?int $clubId): void
    {
        if ($xp < 1) {
            return;
        }
        $clubId = $clubId ?: $this->clubIdFor($user);
        $season = $this->liveSeason($clubId);
        if (! $season) {
            return;
        }
        $row = UserBattlePass::query()->firstOrCreate(
            ['user_id' => $user->id, 'season_id' => $season->id],
            ['xp' => 0, 'level' => 0, 'claimed_level' => 0],
        );
        $row->xp = (int) $row->xp + $xp;
        $row->level = (int) BattlePassLevel::query()
            ->where('season_id', $season->id)
            ->where('xp_required', '<=', $row->xp)
            ->max('level');
        $row->save();
        $this->evaluateLadder($user, $clubId);
    }

    /**
     * @return array<string, mixed>
     */
    public function claim(User $user, int $levelNumber, ?int $clubId = null): array
    {
        $clubId = $clubId ?: $this->clubIdFor($user);
        $season = $this->claimableSeason($user, $clubId);
        if (! $season || $season->status === BattlePassSeason::ARCHIVED) {
            throw new RuntimeException('Сезон закрыт, забрать награду уже нельзя');
        }
        if ($season->status === BattlePassSeason::XP_CLOSED && now()->gt($this->graceUntil($season))) {
            throw new RuntimeException('Срок, чтобы забрать награды, прошёл');
        }
        $level = BattlePassLevel::query()
            ->where('season_id', $season->id)
            ->where('level', $levelNumber)
            ->first();
        if (! $level) {
            throw new RuntimeException('Такого уровня нет');
        }
        $progress = UserBattlePass::query()
            ->where('user_id', $user->id)
            ->where('season_id', $season->id)
            ->first();
        if (! $progress || (int) $progress->xp < (int) $level->xp_required) {
            throw new RuntimeException('Уровень ещё не открыт');
        }
        if (BattlePassClaim::query()->where('user_id', $user->id)->where('level_id', $level->id)->exists()) {
            throw new RuntimeException('Награда уровня уже забрана');
        }

        $granted = $this->rewards->grant(
            $user,
            $level->reward_kind,
            $level->reward_payload ?? [],
            'level-'.$level->id,
            $season->id,
            $level->id,
        );
        BattlePassClaim::query()->create([
            'user_id' => $user->id,
            'level_id' => $level->id,
            'claimed_at' => now(),
        ]);
        $progress->claimed_level = max((int) $progress->claimed_level, (int) $level->level);
        $progress->save();

        return $granted;
    }

    public function close(BattlePassSeason $season): void
    {
        if ($season->status !== BattlePassSeason::LIVE) {
            return;
        }
        $season->update([
            'status' => BattlePassSeason::XP_CLOSED,
            'is_active' => false,
            'closed_at' => now(),
        ]);
        $this->crownMonthly($season);
    }

    public function rollSeasons(?int $clubId = null): void
    {
        $query = BattlePassSeason::query()->whereIn('status', [BattlePassSeason::LIVE, BattlePassSeason::XP_CLOSED]);
        if ($clubId) {
            $query->where('club_id', $clubId);
        }
        foreach ($query->get() as $season) {
            if ($season->status === BattlePassSeason::LIVE && now()->greaterThan($season->ends_on->copy()->endOfDay())) {
                $this->close($season);
                $season->refresh();
            }
            if ($season->status === BattlePassSeason::XP_CLOSED && now()->gt($this->graceUntil($season))) {
                $season->update(['status' => BattlePassSeason::ARCHIVED]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function progress(User $user, ?int $clubId): array
    {
        $clubId = $clubId ?: $this->clubIdFor($user);
        $season = $this->claimableSeason($user, $clubId);
        if (! $season) {
            return [
                'level' => 0,
                'xp' => 0,
                'xp_to_next' => null,
                'season' => null,
                'grace_until' => null,
                'claimable' => [],
                'ladder_hint' => null,
            ];
        }
        $row = UserBattlePass::query()
            ->where('user_id', $user->id)
            ->where('season_id', $season->id)
            ->first();
        $xp = (int) ($row->xp ?? 0);
        $next = BattlePassLevel::query()
            ->where('season_id', $season->id)
            ->where('xp_required', '>', $xp)
            ->orderBy('xp_required')
            ->first();
        $claimed = BattlePassClaim::query()
            ->where('user_id', $user->id)
            ->whereIn('level_id', BattlePassLevel::query()->where('season_id', $season->id)->select('id'))
            ->pluck('level_id');
        $claimable = BattlePassLevel::query()
            ->where('season_id', $season->id)
            ->where('xp_required', '<=', $xp)
            ->whereNotIn('id', $claimed)
            ->orderBy('level')
            ->get(['id', 'level', 'reward_kind', 'xp_required'])
            ->all();

        return [
            'level' => (int) ($row->level ?? 0),
            'xp' => $xp,
            'xp_to_next' => $next ? max(0, (int) $next->xp_required - $xp) : 0,
            'season' => $season->title,
            'season_status' => $season->status,
            'grace_until' => $season->status === BattlePassSeason::XP_CLOSED
                ? $this->graceUntil($season)->toIso8601String()
                : null,
            'claimable' => $claimable,
            'ladder_hint' => $this->ladderHint($user, $clubId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function profile(User $user, ?int $clubId): array
    {
        $clubId = $clubId ?: $this->clubIdFor($user);
        $slots = max(3, min(6, (int) $this->settings($clubId)->showcase_slots));
        $frame = UserCosmetic::query()
            ->where('user_id', $user->id)
            ->where('kind', 'frame')
            ->whereNotNull('equipped_at')
            ->first();
        $frameModel = $frame ? CosmeticFrame::query()->find($frame->ref_id) : null;
        $statusRow = UserCosmetic::query()
            ->where('user_id', $user->id)
            ->where('kind', 'status')
            ->whereNotNull('equipped_at')
            ->first();
        $status = $statusRow ? ClubStatus::query()->find($statusRow->ref_id) : null;
        if (! $status && $clubId) {
            $status = ClubStatus::query()
                ->where('club_id', $clubId)
                ->where('key', 'novice')
                ->first();
        }
        $showcase = UserBadgeShowcase::query()
            ->where('user_id', $user->id)
            ->orderBy('slot')
            ->limit($slots)
            ->get()
            ->map(function (UserBadgeShowcase $row) {
                $badge = AchievementBadge::query()->find($row->badge_id);

                return [
                    'slot' => (int) $row->slot,
                    'badge_id' => (int) $row->badge_id,
                    'name' => $badge?->name,
                    'slug' => $badge?->slug,
                    'image' => $badge?->image_path,
                ];
            })
            ->all();
        $owned = UserCosmetic::query()
            ->where('user_id', $user->id)
            ->where('kind', 'badge')
            ->pluck('ref_id');
        $badges = AchievementBadge::query()->whereIn('id', $owned)->get(['id', 'slug', 'name', 'image_path']);
        $frames = UserCosmetic::query()
            ->where('user_id', $user->id)
            ->where('kind', 'frame')
            ->pluck('ref_id');
        $vip = UserRewardGrant::query()
            ->where('user_id', $user->id)
            ->where('reward_kind', 'lfg_vip')
            ->whereNull('consumed_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();

        return [
            'avatar_url' => $user->avatar_url,
            'frame_id' => $frameModel?->id,
            'frame_url' => $frameModel?->image_path,
            'status_key' => $status?->key ?? 'novice',
            'status_label' => $status?->label ?? 'Новичок',
            'lfg_priority' => $vip,
            'showcase' => $showcase,
            'badges' => $badges,
            'frames' => CosmeticFrame::query()->whereIn('id', $frames)->get(['id', 'slug', 'name', 'image_path']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function shellBlock(User $user, ?int $clubId): array
    {
        return [
            'profile' => $this->profile($user, $clubId),
            'battle_pass' => $this->progress($user, $clubId),
            'granted' => $this->rewards->pullToasts($user),
        ];
    }

    public function completeByCode(User $user, string $code, ?int $clubId): bool
    {
        $achievement = Achievement::query()->where('code', $code)->where('is_active', true)->first();
        if (! $achievement) {
            return false;
        }
        $clubId = $clubId ?: $this->clubIdFor($user);
        if ($clubId && ! app(ClubFeatureService::class)->enabled($clubId, 'achievements')) {
            return false;
        }
        $periodKey = app(AchievementService::class)->periodKey($achievement);
        if ($achievement->period === Achievement::PERIOD_ONCE) {
            $done = UserAchievement::query()
                ->where('user_id', $user->id)
                ->where('achievement_id', $achievement->id)
                ->whereNotNull('rewarded_at')
                ->exists();
            if ($done) {
                return false;
            }
        }
        $row = UserAchievement::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'achievement_id' => $achievement->id,
                'period_key' => $periodKey,
            ],
            ['progress' => 0],
        );
        if ($row->isRewarded()) {
            return false;
        }
        $row->progress = max((float) $row->progress, (float) $achievement->target_value);
        $row->completed_at = $row->completed_at ?? now();
        $row->rewarded_at = now();
        $row->save();
        $this->payAchievement($user, $achievement, $periodKey, $clubId);
        if ($achievement->badge_id) {
            UserCosmetic::query()->firstOrCreate([
                'user_id' => $user->id,
                'kind' => 'badge',
                'ref_id' => $achievement->badge_id,
            ]);
        }

        return true;
    }

    public function payAchievement(User $user, Achievement $achievement, string $periodKey, ?int $clubId): void
    {
        $xp = (int) $achievement->xp;
        if ($xp > 0) {
            $this->addXp($user, min(500, $xp), $clubId);
        }
        $kind = $achievement->reward_kind;
        if ($kind && ! in_array($kind, ['none', 'deposit_balance', 'bonus_balance'], true)) {
            $this->rewards->grant(
                $user,
                $kind,
                $achievement->reward_payload ?? [],
                'ach-'.$achievement->id.'-'.$periodKey,
                $this->liveSeason($clubId)?->id,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $quote
     * @return array<string, mixed>
     */
    public function applyToQuote(User $user, array $quote, CarbonInterface $startsAt, bool $consume): array
    {
        $minutes = (int) ($quote['duration_minutes'] ?? 0);
        if ($minutes < 60) {
            return $quote;
        }
        $grant = UserRewardGrant::query()
            ->where('user_id', $user->id)
            ->where('reward_kind', 'tariff_discount')
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->orderBy('id')
            ->first();
        if (! $grant) {
            return $quote;
        }
        $payload = $grant->payload ?? [];
        $zone = (string) ($payload['zone'] ?? 'night');
        $hour = (int) $startsAt->timezone(config('app.timezone'))->format('G');
        $night = $hour >= 22 || $hour < 6;
        if ($zone === 'night' && ! $night) {
            return $quote;
        }
        $percent = max(1, min(50, (int) ($payload['percent'] ?? 0)));
        if ($percent < 1) {
            return $quote;
        }
        $total = (int) ($quote['total_minor'] ?? 0);
        $discount = (int) round($total * $percent / 100);
        $quote['total_minor'] = max(0, $total - $discount);
        $quote['total_price'] = $quote['total_minor'] / 100;
        $quote['battle_pass_discount_minor'] = $discount;
        if ($consume) {
            $grant->consumed_at = now();
            $grant->save();
        }

        return $quote;
    }

    public function lfgVip(int $userId): bool
    {
        return UserRewardGrant::query()
            ->where('user_id', $userId)
            ->where('reward_kind', 'lfg_vip')
            ->whereNull('consumed_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();
    }

    public function evaluateLadder(User $user, ?int $clubId): void
    {
        if (! $clubId) {
            return;
        }
        if (! $this->settings($clubId)->faceit_skill) {
            // FACEIT skill tiers stay off unless the club turns the metric on.
        }
        $elo = (int) (ArenaRating::query()
            ->where('club_id', $clubId)
            ->where('user_id', $user->id)
            ->value('rating') ?? 0);
        $level = (int) (UserBattlePass::query()
            ->where('user_id', $user->id)
            ->orderByDesc('xp')
            ->value('level') ?? 0);
        $hours = $this->hoursThisMonth($user);
        $tiers = LadderRewardTier::query()
            ->where('club_id', $clubId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
        foreach ($tiers as $tier) {
            if ($tier->metric === 'faceit_skill' && ! $this->settings($clubId)->faceit_skill) {
                continue;
            }
            $value = match ($tier->metric) {
                'arena_elo' => $elo,
                'bp_level' => $level,
                'hours_month' => (int) floor($hours),
                'faceit_skill' => (int) (\App\Models\FaceitIdentity::query()->where('user_id', $user->id)->value('skill_level') ?? 0),
                default => 0,
            };
            if ($value < (int) $tier->threshold) {
                continue;
            }
            $periodKey = match ($tier->period) {
                'monthly' => now()->format('Y-m'),
                default => 'once',
            };
            $alive = UserRewardGrant::query()
                ->where('user_id', $user->id)
                ->where('tier_id', $tier->id)
                ->where('period_key', $periodKey)
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->exists();
            if ($alive) {
                continue;
            }
            try {
                $this->rewards->grant(
                    $user,
                    $tier->reward_kind,
                    $tier->payload ?? [],
                    $periodKey,
                    null,
                    null,
                    $tier->id,
                );
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * @return array{templates:int,levels:int}
     */
    public function seedRecommended(int $clubId): array
    {
        $badges = [
            'ace' => 'Эйс',
            'rampage' => 'Rampage',
            'night_wolf' => 'Ночной волк',
            'centurion' => 'Сотник',
            'gladiator' => 'Гладиатор',
            'caffeine' => 'Кофеин',
            'nomad' => 'Кочевник',
            'full_stack' => 'Фулстэк',
            'referral_soul' => 'Душа компании',
        ];
        foreach ($badges as $slug => $name) {
            AchievementBadge::query()->firstOrCreate(
                ['club_id' => $clubId, 'slug' => $slug],
                ['name' => $name, 'source_kind' => 'club', 'is_active' => true],
            );
        }
        $frame = CosmeticFrame::query()->firstOrCreate(
            ['club_id' => $clubId, 'slug' => 'predator'],
            ['name' => 'Хищник', 'min_level' => 5, 'is_active' => true],
        );
        ClubStatus::query()->firstOrCreate(
            ['club_id' => $clubId, 'key' => 'novice'],
            ['label' => 'Новичок', 'priority' => 10, 'color' => 'white', 'is_active' => true],
        );
        $vip = ClubStatus::query()->firstOrCreate(
            ['club_id' => $clubId, 'key' => 'vip'],
            ['label' => 'VIP', 'priority' => 40, 'color' => 'gold', 'is_active' => true],
        );
        $templates = [
            ['code' => 'ace', 'title' => 'Эйс', 'source_kind' => 'gsi_cs2', 'type' => 'gsi_event', 'metric' => 'ace', 'target_value' => 1, 'period' => 'weekly', 'xp' => 40],
            ['code' => 'knife_or_zeus', 'title' => 'Нож или Zeus', 'source_kind' => 'gsi_cs2', 'type' => 'gsi_event', 'metric' => 'knife_or_zeus', 'target_value' => 1, 'period' => 'weekly', 'xp' => 25],
            ['code' => 'rampage', 'title' => 'Rampage', 'source_kind' => 'gsi_dota', 'type' => 'gsi_event', 'metric' => 'rampage', 'target_value' => 1, 'period' => 'weekly', 'xp' => 50],
            ['code' => 'first_blood', 'title' => 'First blood', 'source_kind' => 'gsi_dota', 'type' => 'gsi_event', 'metric' => 'first_blood', 'target_value' => 1, 'period' => 'weekly', 'xp' => 15],
            ['code' => 'night_wolf', 'title' => 'Ночной волк', 'source_kind' => 'club', 'type' => Achievement::TYPE_NIGHT_VISITS, 'metric' => 'night_visits', 'target_value' => 3, 'period' => 'monthly', 'xp' => 30],
            ['code' => 'centurion', 'title' => 'Сотник', 'source_kind' => 'club', 'type' => Achievement::TYPE_PLAY_HOURS, 'metric' => 'play_hours', 'target_value' => 100, 'period' => 'once', 'xp' => 80],
            ['code' => 'gladiator', 'title' => 'Гладиатор', 'source_kind' => 'arena', 'type' => 'arena_wins', 'metric' => 'duel_wins', 'target_value' => 10, 'period' => 'once', 'xp' => 60],
            ['code' => 'caffeine', 'title' => 'Кофеин', 'source_kind' => 'club', 'type' => 'bar_count', 'metric' => 'caffeine', 'target_value' => 10, 'period' => 'monthly', 'xp' => 20],
            ['code' => 'nomad', 'title' => 'Кочевник', 'source_kind' => 'club', 'type' => 'distinct_computers', 'metric' => 'computers', 'target_value' => 10, 'period' => 'once', 'xp' => 30],
            ['code' => 'full_stack', 'title' => 'Фулстэк', 'source_kind' => 'club', 'type' => 'bootcamp_seats', 'metric' => 'bootcamp', 'target_value' => 5, 'period' => 'once', 'xp' => 40],
            ['code' => 'referral_soul', 'title' => 'Душа компании', 'source_kind' => 'club', 'type' => 'referral', 'metric' => 'referral', 'target_value' => 1, 'period' => 'once', 'xp' => 40],
            ['code' => 'faceit_10', 'title' => 'FACEIT 10', 'source_kind' => 'faceit', 'type' => 'gsi_event', 'metric' => 'skill_level', 'target_value' => 10, 'period' => 'once', 'xp' => 50],
        ];
        $count = 0;
        foreach ($templates as $i => $row) {
            $badge = AchievementBadge::query()->where('club_id', $clubId)->where('slug', $row['code'])->first();
            $created = Achievement::query()->firstOrCreate(
                ['code' => $row['code']],
                [
                    'title' => $row['title'],
                    'description' => $row['title'],
                    'source_kind' => $row['source_kind'],
                    'type' => $row['type'],
                    'metric' => $row['metric'],
                    'target_value' => $row['target_value'],
                    'period' => $row['period'],
                    'reward_type' => Achievement::REWARD_BONUS,
                    'reward_value' => 0,
                    'reward_kind' => 'none',
                    'xp' => $row['xp'],
                    'badge_id' => $badge?->id,
                    'night_start' => 22,
                    'night_end' => 6,
                    'is_active' => true,
                    'sort_order' => 100 + $i,
                ],
            );
            if ($created->wasRecentlyCreated) {
                $count++;
            }
        }
        $levels = 0;
        $season = $this->liveSeason($clubId);
        if (! $season) {
            $season = BattlePassSeason::query()->create([
                'club_id' => $clubId,
                'title' => 'Сезон 1',
                'starts_on' => now()->toDateString(),
                'ends_on' => now()->addMonths(3)->toDateString(),
                'status' => BattlePassSeason::LIVE,
                'is_active' => true,
                'claim_grace_days' => 14,
            ]);
        }
        if ($season->levels()->count() === 0) {
            $rows = [
                [1, 40, 'cosmetic_frame', ['frame_id' => $frame->id]],
                [2, 80, 'session_minutes', ['minutes' => 30]],
                [3, 140, 'session_minutes', ['minutes' => 60]],
                [4, 220, 'tariff_discount', ['percent' => 10, 'ttl_days' => 30, 'zone' => 'night']],
                [5, 320, 'lfg_vip', ['ttl_days' => 14]],
                [6, 450, 'cosmetic_status', ['status_id' => $vip->id]],
                [7, 600, 'partner_promo', ['code' => 'PARTNER', 'brand' => 'Партнёр', 'url' => '', 'ttl_days' => 30]],
                [8, 800, 'bonus_balance', ['amount' => 50]],
            ];
            foreach ($rows as [$level, $xp, $kind, $payload]) {
                BattlePassLevel::query()->create([
                    'season_id' => $season->id,
                    'level' => $level,
                    'xp_required' => $xp,
                    'reward_kind' => $kind,
                    'reward_payload' => $payload,
                ]);
                $levels++;
            }
        }
        $ladder = [
            ['arena_elo', 1100, 'tariff_discount', ['percent' => 5, 'ttl_days' => 14, 'zone' => 'any'], 'once'],
            ['arena_elo', 1300, 'session_minutes', ['minutes' => 60], 'monthly'],
            ['bp_level', 5, 'partner_promo', ['code' => 'PARTNER', 'brand' => 'Партнёр', 'ttl_days' => 30], 'once'],
        ];
        foreach ($ladder as $i => [$metric, $threshold, $kind, $payload, $period]) {
            LadderRewardTier::query()->firstOrCreate(
                ['club_id' => $clubId, 'metric' => $metric, 'threshold' => $threshold],
                [
                    'reward_kind' => $kind,
                    'payload' => $payload,
                    'period' => $period,
                    'is_active' => true,
                    'sort_order' => $i,
                ],
            );
        }

        return ['templates' => $count, 'levels' => $levels];
    }

    private function crownMonthly(BattlePassSeason $season): void
    {
        $top = ArenaRating::query()
            ->where('club_id', $season->club_id)
            ->orderByDesc('rating')
            ->first();
        if (! $top) {
            return;
        }
        $user = User::query()->find($top->user_id);
        if ($user) {
            $this->completeByCode($user, 'monthly_champion', (int) $season->club_id);
        }
    }

    private function hoursThisMonth(User $user): float
    {
        $bookings = Booking::query()
            ->where('user_id', $user->id)
            ->where('status', 'completed')
            ->where('actual_started_at', '>=', now()->startOfMonth())
            ->get();

        return $bookings->sum(fn (Booking $b) => app(AchievementService::class)->playedHours($b));
    }

    private function ladderHint(User $user, ?int $clubId): ?string
    {
        if (! $clubId) {
            return null;
        }
        $elo = (int) (ArenaRating::query()->where('club_id', $clubId)->where('user_id', $user->id)->value('rating') ?? 0);
        $next = LadderRewardTier::query()
            ->where('club_id', $clubId)
            ->where('is_active', true)
            ->where('metric', 'arena_elo')
            ->where('threshold', '>', $elo)
            ->orderBy('threshold')
            ->first();
        if (! $next) {
            return null;
        }
        $left = (int) $next->threshold - $elo;
        $percent = (int) (($next->payload['percent'] ?? 0));

        return $percent > 0
            ? 'до скидки '.$percent.'% осталось '.$left
            : 'до следующей награды лестницы осталось '.$left;
    }

    public function pinShowcase(User $user, array $badgeIds, ?int $clubId): void
    {
        $slots = max(3, min(6, (int) $this->settings($clubId)->showcase_slots));
        $badgeIds = array_values(array_unique(array_map('intval', $badgeIds)));
        if (count($badgeIds) > $slots) {
            throw new RuntimeException('Слотов витрины: '.$slots);
        }
        $owned = UserCosmetic::query()
            ->where('user_id', $user->id)
            ->where('kind', 'badge')
            ->pluck('ref_id')
            ->all();
        DB::transaction(function () use ($user, $badgeIds, $owned) {
            UserBadgeShowcase::query()->where('user_id', $user->id)->delete();
            foreach ($badgeIds as $i => $id) {
                if (! in_array($id, $owned, true)) {
                    throw new RuntimeException('Значок ещё не получен');
                }
                UserBadgeShowcase::query()->create([
                    'user_id' => $user->id,
                    'badge_id' => $id,
                    'slot' => $i + 1,
                ]);
            }
        });
    }

    public function equipFrame(User $user, int $frameId): void
    {
        $owned = UserCosmetic::query()
            ->where('user_id', $user->id)
            ->where('kind', 'frame')
            ->where('ref_id', $frameId)
            ->exists();
        if (! $owned) {
            throw new RuntimeException('Рамка ещё не получена');
        }
        UserCosmetic::query()
            ->where('user_id', $user->id)
            ->where('kind', 'frame')
            ->update(['equipped_at' => null]);
        UserCosmetic::query()
            ->where('user_id', $user->id)
            ->where('kind', 'frame')
            ->where('ref_id', $frameId)
            ->update(['equipped_at' => now()]);
    }

    public function barProgress(User $user, Achievement $achievement, CarbonInterface $start, CarbonInterface $end): float
    {
        $clubId = $this->clubIdFor($user);
        $settings = $this->settings($clubId);
        $cats = $achievement->metric === 'fed_gamer'
            ? ($settings->food_categories ?: ['combo', 'sandwich'])
            : ($settings->caffeine_categories ?: ['energy', 'coffee']);
        $orders = Order::query()
            ->where('user_id', $user->id)
            ->where('status', '!=', Order::STATUS_CANCELLED)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<=', $end)
            ->get();
        $qty = 0;
        foreach ($orders as $order) {
            foreach ($order->lineItems() as $line) {
                $productId = (int) ($line['product_id'] ?? 0);
                if ($productId < 1) {
                    continue;
                }
                $product = Product::query()->find($productId);
                if ($product && in_array((string) $product->category, $cats, true)) {
                    $qty += (int) ($line['qty'] ?? 1);
                }
            }
        }

        return (float) $qty;
    }

    public function arenaWins(User $user): float
    {
        return (float) ArenaDuel::query()
            ->where('winner_user_id', $user->id)
            ->where('status', ArenaDuel::STATUS_COMPLETED)
            ->where('kind', ArenaDuel::KIND_DUEL)
            ->count();
    }
}
