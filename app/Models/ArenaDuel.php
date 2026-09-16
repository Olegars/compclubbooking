<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArenaDuel extends Model
{
    public const SCOPE_HALL = 'hall';

    public const SCOPE_COMPUTER = 'computer';

    public const SCOPE_ZONE = 'zone';

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_FORFEIT = 'forfeit';

    protected $fillable = [
        'uuid',
        'club_id',
        'creator_user_id',
        'creator_computer_id',
        'creator_booking_id',
        'target_computer_id',
        'target_user_id',
        'scope',
        'zone_group',
        'game',
        'mode',
        'entry_fee',
        'total_pot',
        'rake_percent',
        'rake_amount',
        'winner_prize',
        'first_to',
        'status',
        'winner_user_id',
        'match_data_snapshot',
        'server_connect_uri',
        'server_password',
        'expires_at',
        'started_at',
        'paused_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'entry_fee' => 'float',
            'total_pot' => 'float',
            'rake_percent' => 'float',
            'rake_amount' => 'float',
            'winner_prize' => 'float',
            'first_to' => 'integer',
            'match_data_snapshot' => 'array',
            'expires_at' => 'datetime',
            'started_at' => 'datetime',
            'paused_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_user_id');
    }

    public function creatorComputer(): BelongsTo
    {
        return $this->belongsTo(Computer::class, 'creator_computer_id');
    }

    public function targetComputer(): BelongsTo
    {
        return $this->belongsTo(Computer::class, 'target_computer_id');
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'winner_user_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ArenaDuelParticipant::class, 'duel_id');
    }

    public function isOpenChallenge(): bool
    {
        return $this->status === self::STATUS_PENDING
            && $this->expires_at
            && $this->expires_at->isFuture();
    }

    public function isLive(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_ACCEPTED,
            self::STATUS_IN_PROGRESS,
            self::STATUS_PAUSED,
        ], true);
    }
}
