<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Club;
use App\Models\Tournament;
use App\Models\TournamentChallenge;
use App\Models\TournamentChallengeRevision;
use App\Services\ClubFeatureService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TournamentChallengeService
{
    public function __construct(
        private readonly ClubFeatureService $features,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function propose(int $fromClubId, Admin $admin, array $data): TournamentChallenge
    {
        $guestId = (int) ($data['guest_club_id'] ?? 0);
        $this->assertClubPair($fromClubId, $guestId);
        $terms = $this->normalizedTerms($data);

        return DB::transaction(function () use ($fromClubId, $guestId, $admin, $terms, $data) {
            $challenge = TournamentChallenge::query()->create(array_merge($terms, [
                'host_club_id' => $fromClubId,
                'guest_club_id' => $guestId,
                'waiting_club_id' => $guestId,
                'proposer_admin_id' => $admin->id,
                'status' => TournamentChallenge::STATUS_PENDING,
            ]));
            $this->record($challenge, $fromClubId, $admin, 'propose', $data['comment'] ?? null);

            return $challenge;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function counter(TournamentChallenge $challenge, int $clubId, Admin $admin, array $data): TournamentChallenge
    {
        $this->assertWaiting($challenge, $clubId);
        $terms = $this->normalizedTerms($data);

        return DB::transaction(function () use ($challenge, $clubId, $admin, $terms, $data) {
            $challenge->fill($terms);
            $challenge->waiting_club_id = $challenge->otherClubId($clubId);
            $challenge->proposer_admin_id = $admin->id;
            $challenge->status = TournamentChallenge::STATUS_PENDING;
            $challenge->save();
            $this->record($challenge, $clubId, $admin, 'counter', $data['comment'] ?? null);

            return $challenge->fresh();
        });
    }

    public function accept(TournamentChallenge $challenge, int $clubId, Admin $admin, ?string $comment = null): Tournament
    {
        $this->assertWaiting($challenge, $clubId);

        return DB::transaction(function () use ($challenge, $clubId, $admin, $comment) {
            $tournament = Tournament::query()->create([
                'club_id' => $challenge->host_club_id,
                'opponent_club_id' => $challenge->guest_club_id,
                'name' => $challenge->name,
                'game_id' => $challenge->game_id,
                'start_at' => $challenge->start_at,
                'end_at' => $challenge->end_at,
                'entry_fee' => $challenge->entry_fee,
                'prize_pool' => $challenge->prize_pool,
                'prize_first_minor' => $challenge->prize_first_minor,
                'prize_second_minor' => $challenge->prize_second_minor,
                'prize_third_minor' => $challenge->prize_third_minor,
                'status' => 'planned',
                'format' => $challenge->format ?: 'single_elim',
                'lock_games' => (bool) $challenge->lock_games,
                'roster_size' => $challenge->roster_size,
                'venue' => $challenge->venue,
                'prize_funding' => $challenge->prize_funding,
                'rules' => $challenge->rules,
            ]);

            $challenge->update([
                'status' => TournamentChallenge::STATUS_AGREED,
                'tournament_id' => $tournament->id,
                'agreed_at' => now(),
            ]);
            $tournament->update(['challenge_id' => $challenge->id]);
            $this->record($challenge, $clubId, $admin, 'accept', $comment);

            return $tournament->fresh();
        });
    }

    public function decline(TournamentChallenge $challenge, int $clubId, Admin $admin, ?string $reason = null): void
    {
        $this->assertWaiting($challenge, $clubId);
        $challenge->update([
            'status' => TournamentChallenge::STATUS_DECLINED,
            'decline_reason' => $reason,
        ]);
        $this->record($challenge, $clubId, $admin, 'decline', $reason);
    }

    public function cancel(TournamentChallenge $challenge, int $clubId, Admin $admin, ?string $reason = null): void
    {
        if (! $challenge->isPending()) {
            throw ValidationException::withMessages([
                'challenge' => 'Отменить можно только открытое согласование.',
            ]);
        }
        if (! $challenge->involvesClub($clubId)) {
            throw ValidationException::withMessages([
                'challenge' => 'Этот вызов не вашей локации.',
            ]);
        }
        $challenge->update([
            'status' => TournamentChallenge::STATUS_CANCELLED,
            'decline_reason' => $reason,
        ]);
        $this->record($challenge, $clubId, $admin, 'cancel', $reason);
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(TournamentChallenge $challenge, ?int $viewerClubId = null): array
    {
        $challenge->loadMissing([
            'hostClub:id,name,slug',
            'guestClub:id,name,slug',
            'waitingClub:id,name',
            'game:id,title',
            'revisions.admin:id,name',
            'revisions.club:id,name',
        ]);

        $viewerClubId = $viewerClubId ? (int) $viewerClubId : 0;

        return [
            'id' => $challenge->id,
            'status' => $challenge->status,
            'name' => $challenge->name,
            'format' => $challenge->format,
            'game' => $challenge->game ? ['id' => $challenge->game->id, 'title' => $challenge->game->title] : null,
            'host' => $challenge->hostClub ? ['id' => $challenge->hostClub->id, 'name' => $challenge->hostClub->name] : null,
            'guest' => $challenge->guestClub ? ['id' => $challenge->guestClub->id, 'name' => $challenge->guestClub->name] : null,
            'waiting_club_id' => (int) $challenge->waiting_club_id,
            'waiting_club' => $challenge->waitingClub?->name,
            'mine_turn' => $viewerClubId > 0 && (int) $challenge->waiting_club_id === $viewerClubId
                && $challenge->isPending(),
            'incoming' => $viewerClubId > 0 && (int) $challenge->waiting_club_id === $viewerClubId
                && $challenge->isPending(),
            'tournament_id' => $challenge->tournament_id,
            'start_at' => optional($challenge->start_at)?->timezone(config('app.timezone'))->format('Y-m-d\\TH:i'),
            'end_at' => optional($challenge->end_at)?->timezone(config('app.timezone'))->format('Y-m-d\\TH:i'),
            'start_label' => optional($challenge->start_at)?->timezone(config('app.timezone'))->format('d.m H:i'),
            'end_label' => optional($challenge->end_at)?->timezone(config('app.timezone'))->format('d.m H:i'),
            'roster_size' => (int) $challenge->roster_size,
            'entry_fee' => (float) $challenge->entry_fee,
            'prize_pool' => $challenge->prize_pool,
            'prize_first' => ((int) $challenge->prize_first_minor) / 100,
            'prize_second' => ((int) $challenge->prize_second_minor) / 100,
            'prize_third' => ((int) $challenge->prize_third_minor) / 100,
            'prize_funding' => $challenge->prize_funding,
            'venue' => $challenge->venue,
            'lock_games' => (bool) $challenge->lock_games,
            'rules' => $challenge->rules,
            'decline_reason' => $challenge->decline_reason,
            'agreed_at' => optional($challenge->agreed_at)?->toIso8601String(),
            'history' => $challenge->revisions->sortBy('id')->values()->map(fn (TournamentChallengeRevision $r) => [
                'id' => $r->id,
                'action' => $r->action,
                'club' => $r->club?->name,
                'admin' => $r->admin?->name,
                'comment' => $r->comment,
                'at' => optional($r->created_at)?->timezone(config('app.timezone'))->format('d.m H:i'),
            ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function normalizedTerms(array $data): array
    {
        $start = $data['start_at'] ?? now();
        $end = $data['end_at'] ?? now()->addHours(4);

        return [
            'name' => trim((string) $data['name']),
            'game_id' => (int) $data['game_id'],
            'format' => $data['format'] ?? 'single_elim',
            'start_at' => $start,
            'end_at' => $end,
            'roster_size' => max(1, (int) ($data['roster_size'] ?? 5)),
            'entry_fee' => (float) ($data['entry_fee'] ?? 0),
            'prize_pool' => $data['prize_pool'] ?? null,
            'prize_first_minor' => (int) round(((float) ($data['prize_first'] ?? 0)) * 100),
            'prize_second_minor' => (int) round(((float) ($data['prize_second'] ?? 0)) * 100),
            'prize_third_minor' => (int) round(((float) ($data['prize_third'] ?? 0)) * 100),
            'prize_funding' => $data['prize_funding'] ?? 'host',
            'venue' => $data['venue'] ?? 'host',
            'lock_games' => array_key_exists('lock_games', $data)
                ? filter_var($data['lock_games'], FILTER_VALIDATE_BOOLEAN)
                : true,
            'rules' => $data['rules'] ?? null,
        ];
    }

    private function assertClubPair(int $fromClubId, int $guestId): void
    {
        if ($fromClubId < 1) {
            throw ValidationException::withMessages([
                'guest_club_id' => 'Нет текущей локации.',
            ]);
        }
        if ($guestId < 1 || $guestId === $fromClubId) {
            throw ValidationException::withMessages([
                'guest_club_id' => 'Выберите другой клуб сети.',
            ]);
        }
        if (! Club::query()->whereKey($guestId)->exists()) {
            throw ValidationException::withMessages([
                'guest_club_id' => 'Клуб не найден.',
            ]);
        }
        if (! $this->features->enabled($fromClubId, 'tournaments')) {
            throw ValidationException::withMessages([
                'name' => 'Турниры выключены в Конфигурация → Фичи',
            ]);
        }
        if (! $this->features->enabled($guestId, 'tournaments')) {
            throw ValidationException::withMessages([
                'guest_club_id' => 'У второй локации выключены турниры.',
            ]);
        }
        $open = TournamentChallenge::query()
            ->where('status', TournamentChallenge::STATUS_PENDING)
            ->where(function ($q) use ($fromClubId, $guestId) {
                $q->where(function ($q) use ($fromClubId, $guestId) {
                    $q->where('host_club_id', $fromClubId)->where('guest_club_id', $guestId);
                })->orWhere(function ($q) use ($fromClubId, $guestId) {
                    $q->where('host_club_id', $guestId)->where('guest_club_id', $fromClubId);
                });
            })
            ->exists();
        if ($open) {
            throw ValidationException::withMessages([
                'guest_club_id' => 'С этим клубом уже идёт согласование. Закройте его или ответьте.',
            ]);
        }
    }

    private function assertWaiting(TournamentChallenge $challenge, int $clubId): void
    {
        if (! $challenge->isPending()) {
            throw ValidationException::withMessages([
                'challenge' => 'Согласование уже закрыто.',
            ]);
        }
        if ((int) $challenge->waiting_club_id !== $clubId) {
            throw ValidationException::withMessages([
                'challenge' => 'Сейчас ход другой локации.',
            ]);
        }
    }

    private function record(
        TournamentChallenge $challenge,
        int $clubId,
        Admin $admin,
        string $action,
        ?string $comment,
    ): void {
        TournamentChallengeRevision::query()->create([
            'challenge_id' => $challenge->id,
            'club_id' => $clubId,
            'admin_id' => $admin->id,
            'action' => $action,
            'terms' => $challenge->terms(),
            'comment' => $comment ? trim($comment) : null,
        ]);
    }
}
