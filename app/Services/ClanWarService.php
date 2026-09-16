<?php

namespace App\Services;

use App\Models\ClanPlayerRating;
use App\Models\ClanRating;
use App\Models\ClanWar;
use App\Models\ClanWarEvent;
use App\Models\Club;
use App\Models\Computer;
use App\Models\User;
use App\Support\ZoneSlug;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ClanWarService
{
    public const POINTS_MATCH_WIN = 10;

    public const POINTS_ROUND_WIN = 1;

    public const BASE_RATING = 1000;

    /**
     * @param  array<string, mixed>  $snap
     * @return array<string, mixed>|null
     */
    public function ingest(Computer $computer, User $user, array $snap): ?array
    {
        $event = strtolower((string) ($snap['event'] ?? ''));
        if (! in_array($event, ['match_win', 'round_win'], true)) {
            return null;
        }

        $this->expireOverdue();

        $war = $this->liveWarForComputer($computer);
        if (! $war) {
            return null;
        }

        $game = (($snap['game'] ?? '') === 'dota') ? 'dota' : 'cs2';
        if ($war->game !== 'any' && $war->game !== $game) {
            return null;
        }

        $side = $this->sideForComputer($war, $computer);
        if ($side === null) {
            return null;
        }

        $points = $event === 'match_win' ? self::POINTS_MATCH_WIN : self::POINTS_ROUND_WIN;
        $matchId = trim((string) ($snap['match_id'] ?? '')) ?: 'none';
        $round = (int) ($snap['round'] ?? 0);
        $dedupe = substr(sha1($event.'|'.$user->id.'|'.$matchId.'|'.$round), 0, 80);

        $recorded = null;
        DB::transaction(function () use ($war, $user, $computer, $side, $event, $game, $dedupe, $points, &$recorded) {
            $war = ClanWar::query()->lockForUpdate()->find($war->id);
            if (! $war || $war->status !== ClanWar::STATUS_LIVE) {
                return;
            }
            if (ClanWarEvent::query()
                ->where('clan_war_id', $war->id)
                ->where('dedupe_key', $dedupe)
                ->exists()) {
                return;
            }

            ClanWarEvent::query()->create([
                'clan_war_id' => $war->id,
                'user_id' => $user->id,
                'computer_id' => $computer->id,
                'club_id' => $computer->club_id,
                'side' => $side,
                'event' => $event,
                'game' => $game,
                'dedupe_key' => $dedupe,
                'points' => $points,
                'created_at' => now(),
            ]);

            $scoreCol = $side === 'a' ? 'score_a' : 'score_b';
            $winCol = $side === 'a' ? 'wins_a' : 'wins_b';
            $roundCol = $side === 'a' ? 'rounds_a' : 'rounds_b';
            $war->{$scoreCol} = (int) $war->{$scoreCol} + $points;
            if ($event === 'match_win') {
                $war->{$winCol} = (int) $war->{$winCol} + 1;
            } else {
                $war->{$roundCol} = (int) $war->{$roundCol} + 1;
            }
            $war->save();
            $recorded = $war;
        });

        return $recorded ? $this->scoreboard($recorded->fresh() ?? $recorded, $computer) : null;
    }

    public function start(ClanWar $war): ClanWar
    {
        if ($war->status !== ClanWar::STATUS_PLANNED) {
            throw new RuntimeException('Стартовать можно только запланированную войну.');
        }
        if (ClanWar::query()->where('status', ClanWar::STATUS_LIVE)->exists()) {
            throw new RuntimeException('Уже идёт другая Clan War. Сначала завершите её.');
        }

        $minutes = max(10, min(240, (int) $war->duration_minutes));
        $now = CarbonImmutable::now();
        $war->update([
            'status' => ClanWar::STATUS_LIVE,
            'starts_at' => $now,
            'ends_at' => $now->addMinutes($minutes),
            'duration_minutes' => $minutes,
        ]);

        return $war->fresh() ?? $war;
    }

    public function finish(ClanWar $war): ClanWar
    {
        if ($war->status !== ClanWar::STATUS_LIVE && $war->status !== ClanWar::STATUS_PLANNED) {
            return $war;
        }
        if ($war->status === ClanWar::STATUS_PLANNED) {
            $war->update(['status' => ClanWar::STATUS_CANCELLED]);

            return $war->fresh() ?? $war;
        }

        DB::transaction(function () use ($war) {
            $war = ClanWar::query()->lockForUpdate()->find($war->id);
            if (! $war || $war->status !== ClanWar::STATUS_LIVE) {
                return;
            }
            $winner = 'draw';
            if ((int) $war->score_a > (int) $war->score_b) {
                $winner = 'a';
            } elseif ((int) $war->score_b > (int) $war->score_a) {
                $winner = 'b';
            }
            $war->update([
                'status' => ClanWar::STATUS_FINISHED,
                'winner_side' => $winner,
                'ends_at' => $war->ends_at && $war->ends_at->isFuture() ? now() : $war->ends_at,
            ]);
            $this->applyRatings($war->fresh() ?? $war, $winner);
        });

        return $war->fresh() ?? $war;
    }

    public function cancel(ClanWar $war): ClanWar
    {
        if ($war->status === ClanWar::STATUS_FINISHED) {
            throw new RuntimeException('Завершённую войну нельзя отменить.');
        }
        $war->update(['status' => ClanWar::STATUS_CANCELLED]);

        return $war->fresh() ?? $war;
    }

    public function expireOverdue(): void
    {
        $overdue = ClanWar::query()
            ->where('status', ClanWar::STATUS_LIVE)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now())
            ->get();
        foreach ($overdue as $war) {
            try {
                $this->finish($war);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function liveForTerminal(?int $terminalId): ?array
    {
        $this->expireOverdue();
        $computer = $terminalId && $terminalId > 0
            ? Computer::query()->with('space.zone')->find($terminalId)
            : null;

        $war = $computer
            ? $this->liveWarForComputer($computer)
            : ClanWar::query()->where('status', ClanWar::STATUS_LIVE)->orderByDesc('id')->first();

        if (! $war) {
            return null;
        }

        return $this->scoreboard($war, $computer);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function livePayload(?Computer $computer = null): ?array
    {
        $this->expireOverdue();
        $war = $computer
            ? $this->liveWarForComputer($computer)
            : ClanWar::query()->where('status', ClanWar::STATUS_LIVE)->orderByDesc('id')->first();

        return $war ? $this->scoreboard($war, $computer) : null;
    }

    /**
     * @param  array<string, mixed>  $blocks
     * @param  array<string, mixed>  $war
     * @return array<string, mixed>
     */
    public function paintOverlayBlocks(array $blocks, array $war): array
    {
        $blocks['mid_left'] = $this->mergeScoreLayer(
            $blocks['mid_left'] ?? null,
            'mid_left',
            (string) ($war['side_a']['label'] ?? 'A'),
            (int) ($war['side_a']['score'] ?? 0),
            (int) ($war['side_a']['wins'] ?? 0),
            '#c084fc'
        );
        $blocks['mid_right'] = $this->mergeScoreLayer(
            $blocks['mid_right'] ?? null,
            'mid_right',
            (string) ($war['side_b']['label'] ?? 'B'),
            (int) ($war['side_b']['score'] ?? 0),
            (int) ($war['side_b']['wins'] ?? 0),
            '#22c55e'
        );

        return $blocks;
    }

    /**
     * @return array<string, mixed>
     */
    public function cabinetForUser(User $user): array
    {
        $this->expireOverdue();
        $live = $this->livePayload();
        $mine = ClanPlayerRating::query()
            ->with('clan')
            ->where('user_id', $user->id)
            ->orderByDesc('rating')
            ->limit(6)
            ->get()
            ->map(fn (ClanPlayerRating $row) => [
                'clan' => $row->clan?->name,
                'kind' => $row->clan?->kind,
                'rating' => (int) $row->rating,
                'wars_played' => (int) $row->wars_played,
                'points' => (int) $row->points_contributed,
            ])
            ->values()
            ->all();

        $board = ClanRating::query()
            ->orderByDesc('rating')
            ->orderByDesc('wars_won')
            ->limit(8)
            ->get()
            ->map(fn (ClanRating $row) => [
                'id' => $row->id,
                'name' => $row->name,
                'kind' => $row->kind,
                'rating' => (int) $row->rating,
                'wars_played' => (int) $row->wars_played,
                'wars_won' => (int) $row->wars_won,
                'points_total' => (int) $row->points_total,
            ])
            ->values()
            ->all();

        return [
            'live' => $live,
            'mine' => $mine,
            'board' => $board,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(ClanWar $war): array
    {
        $top = ClanWarEvent::query()
            ->selectRaw('user_id, side, sum(points) as points')
            ->where('clan_war_id', $war->id)
            ->groupBy('user_id', 'side')
            ->orderByDesc('points')
            ->limit(8)
            ->get();
        $users = User::query()
            ->whereIn('id', $top->pluck('user_id')->filter()->all())
            ->get(['id', 'name'])
            ->keyBy('id');

        return [
            'id' => $war->id,
            'name' => $war->name,
            'mode' => $war->mode,
            'game' => $war->game,
            'status' => $war->status,
            'duration_minutes' => (int) $war->duration_minutes,
            'starts_at' => $war->starts_at?->toIso8601String(),
            'ends_at' => $war->ends_at?->toIso8601String(),
            'side_a' => [
                'type' => $war->side_a_type,
                'key' => $war->side_a_key,
                'label' => $war->side_a_label,
                'club_id' => $war->side_a_club_id,
                'score' => (int) $war->score_a,
                'wins' => (int) $war->wins_a,
                'rounds' => (int) $war->rounds_a,
            ],
            'side_b' => [
                'type' => $war->side_b_type,
                'key' => $war->side_b_key,
                'label' => $war->side_b_label,
                'club_id' => $war->side_b_club_id,
                'score' => (int) $war->score_b,
                'wins' => (int) $war->wins_b,
                'rounds' => (int) $war->rounds_b,
            ],
            'winner_side' => $war->winner_side,
            'contributors' => $top->map(fn ($row) => [
                'user_id' => (int) $row->user_id,
                'name' => $users[(int) $row->user_id]->name ?? 'игрок',
                'side' => $row->side,
                'points' => (int) $row->points,
            ])->values()->all(),
        ];
    }

    public function create(array $data, ?int $hostClubId): ClanWar
    {
        $mode = ($data['mode'] ?? '') === ClanWar::MODE_LOCATION
            ? ClanWar::MODE_LOCATION
            : ClanWar::MODE_ZONE;
        $game = in_array($data['game'] ?? 'any', ['cs2', 'dota', 'any'], true)
            ? ($data['game'] ?? 'any')
            : 'any';
        $minutes = max(10, min(240, (int) ($data['duration_minutes'] ?? 60)));

        if ($mode === ClanWar::MODE_ZONE) {
            if (! $hostClubId) {
                throw new RuntimeException('Для межзонной войны нужна текущая локация.');
            }
            $club = Club::query()->find($hostClubId);
            $clubName = $club?->name ?: 'Клуб';
            $aLabel = 'Bootcamp · '.$clubName;
            $bLabel = 'Standard · '.$clubName;

            return ClanWar::query()->create([
                'host_club_id' => $hostClubId,
                'name' => trim((string) ($data['name'] ?? '')) ?: ('Bootcamp vs Standard · '.$clubName),
                'mode' => $mode,
                'game' => $game,
                'status' => ClanWar::STATUS_PLANNED,
                'duration_minutes' => $minutes,
                'side_a_type' => 'zone_group',
                'side_a_key' => 'bootcamp',
                'side_a_label' => $aLabel,
                'side_a_club_id' => $hostClubId,
                'side_b_type' => 'zone_group',
                'side_b_key' => 'standard',
                'side_b_label' => $bLabel,
                'side_b_club_id' => $hostClubId,
            ]);
        }

        $aId = (int) ($data['side_a_club_id'] ?? 0);
        $bId = (int) ($data['side_b_club_id'] ?? 0);
        if ($aId < 1 || $bId < 1 || $aId === $bId) {
            throw new RuntimeException('Выберите две разные локации сети.');
        }
        $a = Club::query()->find($aId);
        $b = Club::query()->find($bId);
        if (! $a || ! $b) {
            throw new RuntimeException('Локация не найдена.');
        }

        return ClanWar::query()->create([
            'host_club_id' => $hostClubId,
            'name' => trim((string) ($data['name'] ?? '')) ?: ($a->name.' vs '.$b->name),
            'mode' => $mode,
            'game' => $game,
            'status' => ClanWar::STATUS_PLANNED,
            'duration_minutes' => $minutes,
            'side_a_type' => 'club',
            'side_a_key' => (string) $a->id,
            'side_a_label' => (string) $a->name,
            'side_a_club_id' => $a->id,
            'side_b_type' => 'club',
            'side_b_key' => (string) $b->id,
            'side_b_label' => (string) $b->name,
            'side_b_club_id' => $b->id,
        ]);
    }

    public static function zoneGroup(?string $slug): ?string
    {
        $slug = ZoneSlug::normalize($slug);
        if ($slug === '' || $slug === 'tv') {
            return null;
        }
        if (str_starts_with($slug, 'bootcamp')) {
            return 'bootcamp';
        }

        return 'standard';
    }

    private function liveWarForComputer(Computer $computer): ?ClanWar
    {
        $clubId = (int) ($computer->club_id ?? 0);
        $group = $this->computerZoneGroup($computer);

        return ClanWar::query()
            ->where('status', ClanWar::STATUS_LIVE)
            ->orderByDesc('id')
            ->get()
            ->first(function (ClanWar $war) use ($clubId, $group) {
                if ($war->mode === ClanWar::MODE_LOCATION) {
                    return $clubId > 0 && in_array($clubId, [
                        (int) $war->side_a_club_id,
                        (int) $war->side_b_club_id,
                    ], true);
                }
                if ($group === null) {
                    return $clubId > 0 && (int) $war->host_club_id === $clubId;
                }

                return (int) $war->side_a_club_id === $clubId || (int) $war->side_b_club_id === $clubId;
            });
    }

    private function sideForComputer(ClanWar $war, Computer $computer): ?string
    {
        $clubId = (int) ($computer->club_id ?? 0);
        if ($war->mode === ClanWar::MODE_LOCATION) {
            if ($clubId === (int) $war->side_a_club_id) {
                return 'a';
            }
            if ($clubId === (int) $war->side_b_club_id) {
                return 'b';
            }

            return null;
        }

        $group = $this->computerZoneGroup($computer);
        if ($group === null) {
            return null;
        }
        $clubOk = $clubId === (int) $war->side_a_club_id || $clubId === (int) $war->side_b_club_id;
        if (! $clubOk) {
            return null;
        }
        if ($group === $war->side_a_key) {
            return 'a';
        }
        if ($group === $war->side_b_key) {
            return 'b';
        }

        return null;
    }

    private function computerZoneGroup(Computer $computer): ?string
    {
        if (! $computer->relationLoaded('space')) {
            $computer->loadMissing('space.zone');
        }
        $slug = $computer->space?->zone?->slug;

        return self::zoneGroup($slug);
    }

    /**
     * @return array<string, mixed>
     */
    private function scoreboard(ClanWar $war, ?Computer $computer): array
    {
        $remaining = 0;
        if ($war->ends_at) {
            $remaining = max(0, $war->ends_at->getTimestamp() - now()->getTimestamp());
        }
        $mySide = $computer ? $this->sideForComputer($war, $computer) : null;

        return [
            'id' => $war->id,
            'name' => $war->name,
            'mode' => $war->mode,
            'game' => $war->game,
            'status' => $war->status,
            'remaining_sec' => $remaining,
            'ends_at' => $war->ends_at?->toIso8601String(),
            'side_a' => [
                'label' => $war->side_a_label,
                'score' => (int) $war->score_a,
                'wins' => (int) $war->wins_a,
                'rounds' => (int) $war->rounds_a,
            ],
            'side_b' => [
                'label' => $war->side_b_label,
                'score' => (int) $war->score_b,
                'wins' => (int) $war->wins_b,
                'rounds' => (int) $war->rounds_b,
            ],
            'my_side' => $mySide,
            'headline' => $war->side_a_label.' '.$war->score_a.' : '.$war->score_b.' '.$war->side_b_label,
        ];
    }

    /**
     * @param  array<string, mixed>|object|null  $block
     * @return array<string, mixed>
     */
    private function mergeScoreLayer(array|object|null $block, string $position, string $label, int $score, int $wins, string $color): array
    {
        $data = is_object($block) ? (array) json_decode(json_encode($block), true) : ($block ?? []);
        $content = is_array($data['content'] ?? null) ? $data['content'] : [];
        $layers = is_array($content['layers'] ?? null) ? $content['layers'] : [];
        $layers = array_values(array_filter($layers, function ($layer) {
            return ! is_array($layer) || ($layer['role'] ?? '') !== 'clan_war';
        }));
        $layers[] = [
            'type' => 'text',
            'role' => 'clan_war',
            'value' => mb_strtoupper($label)."\n".$score."\nпобед ".$wins,
            'color' => $color,
            'size' => 26,
        ];
        $content['layers'] = $layers;
        $data['block_position'] = $data['block_position'] ?? $position;
        $data['title'] = $data['title'] ?? 'CLAN WAR';
        $data['type'] = $data['type'] ?? 'text';
        $data['is_active'] = true;
        $data['content'] = $content;

        return $data;
    }

    private function applyRatings(ClanWar $war, string $winner): void
    {
        if ($war->rating_applied_at) {
            return;
        }
        $a = $this->faction($war, 'a');
        $b = $this->faction($war, 'b');
        $diff = abs((int) $war->score_a - (int) $war->score_b);
        $swing = 20 + min(30, intdiv($diff, 5));

        if ($winner === 'a') {
            $a->rating = (int) $a->rating + $swing;
            $b->rating = max(100, (int) $b->rating - (int) round($swing * 0.6));
            $a->wars_won = (int) $a->wars_won + 1;
        } elseif ($winner === 'b') {
            $b->rating = (int) $b->rating + $swing;
            $a->rating = max(100, (int) $a->rating - (int) round($swing * 0.6));
            $b->wars_won = (int) $b->wars_won + 1;
        } else {
            $a->rating = (int) $a->rating + 5;
            $b->rating = (int) $b->rating + 5;
            $a->wars_drawn = (int) $a->wars_drawn + 1;
            $b->wars_drawn = (int) $b->wars_drawn + 1;
        }
        $a->wars_played = (int) $a->wars_played + 1;
        $b->wars_played = (int) $b->wars_played + 1;
        $a->points_total = (int) $a->points_total + (int) $war->score_a;
        $b->points_total = (int) $b->points_total + (int) $war->score_b;
        $a->save();
        $b->save();

        $contrib = ClanWarEvent::query()
            ->selectRaw('user_id, side, sum(points) as points')
            ->where('clan_war_id', $war->id)
            ->groupBy('user_id', 'side')
            ->get();
        foreach ($contrib as $row) {
            $faction = $row->side === 'a' ? $a : $b;
            $bonus = $winner === $row->side ? 15 : ($winner === 'draw' ? 5 : 0);
            $player = ClanPlayerRating::query()->firstOrCreate(
                ['user_id' => (int) $row->user_id, 'clan_rating_id' => $faction->id],
                ['rating' => self::BASE_RATING]
            );
            $player->rating = (int) $player->rating + (int) $row->points + $bonus;
            $player->wars_played = (int) $player->wars_played + 1;
            $player->points_contributed = (int) $player->points_contributed + (int) $row->points;
            $player->last_war_id = $war->id;
            $player->save();
        }

        $war->update(['rating_applied_at' => now()]);
    }

    private function faction(ClanWar $war, string $side): ClanRating
    {
        $type = $side === 'a' ? $war->side_a_type : $war->side_b_type;
        $key = $side === 'a' ? $war->side_a_key : $war->side_b_key;
        $label = $side === 'a' ? $war->side_a_label : $war->side_b_label;
        $clubId = $side === 'a' ? $war->side_a_club_id : $war->side_b_club_id;
        $kind = $type === 'club' ? 'club' : 'zone_group';
        $ratingClubId = $kind === 'club' ? (int) $clubId : (int) $clubId;

        return ClanRating::query()->firstOrCreate(
            [
                'kind' => $kind,
                'club_id' => $ratingClubId ?: null,
                'faction_key' => $key,
            ],
            [
                'name' => $label,
                'rating' => self::BASE_RATING,
            ]
        );
    }
}
