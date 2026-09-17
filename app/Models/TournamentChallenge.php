<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TournamentChallenge extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_AGREED = 'agreed';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_CANCELLED = 'cancelled';

    public const VENUES = ['host', 'guest', 'split'];

    public const FUNDING = ['host', 'guest', 'split', 'each'];

    public const FORMATS = ['single_elim', 'double_elim', 'round_robin'];

    protected $fillable = [
        'host_club_id',
        'guest_club_id',
        'waiting_club_id',
        'proposer_admin_id',
        'tournament_id',
        'status',
        'name',
        'game_id',
        'format',
        'start_at',
        'end_at',
        'roster_size',
        'entry_fee',
        'prize_pool',
        'prize_first_minor',
        'prize_second_minor',
        'prize_third_minor',
        'prize_funding',
        'venue',
        'lock_games',
        'rules',
        'decline_reason',
        'agreed_at',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'agreed_at' => 'datetime',
        'lock_games' => 'boolean',
        'entry_fee' => 'float',
        'roster_size' => 'integer',
        'prize_first_minor' => 'integer',
        'prize_second_minor' => 'integer',
        'prize_third_minor' => 'integer',
    ];

    public function hostClub(): BelongsTo
    {
        return $this->belongsTo(Club::class, 'host_club_id');
    }

    public function guestClub(): BelongsTo
    {
        return $this->belongsTo(Club::class, 'guest_club_id');
    }

    public function waitingClub(): BelongsTo
    {
        return $this->belongsTo(Club::class, 'waiting_club_id');
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'proposer_admin_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(TournamentChallengeRevision::class, 'challenge_id')->orderByDesc('id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function involvesClub(int $clubId): bool
    {
        return (int) $this->host_club_id === $clubId || (int) $this->guest_club_id === $clubId;
    }

    public function otherClubId(int $clubId): int
    {
        return (int) $this->host_club_id === $clubId
            ? (int) $this->guest_club_id
            : (int) $this->host_club_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function terms(): array
    {
        return [
            'name' => $this->name,
            'game_id' => (int) $this->game_id,
            'format' => $this->format,
            'start_at' => optional($this->start_at)?->toIso8601String(),
            'end_at' => optional($this->end_at)?->toIso8601String(),
            'roster_size' => (int) $this->roster_size,
            'entry_fee' => (float) $this->entry_fee,
            'prize_pool' => $this->prize_pool,
            'prize_first_minor' => (int) $this->prize_first_minor,
            'prize_second_minor' => (int) $this->prize_second_minor,
            'prize_third_minor' => (int) $this->prize_third_minor,
            'prize_funding' => $this->prize_funding,
            'venue' => $this->venue,
            'lock_games' => (bool) $this->lock_games,
            'rules' => $this->rules,
        ];
    }
}
