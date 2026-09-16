<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClanWarEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'clan_war_id',
        'user_id',
        'computer_id',
        'club_id',
        'side',
        'event',
        'game',
        'dedupe_key',
        'points',
        'created_at',
    ];

    protected $casts = [
        'clan_war_id' => 'integer',
        'user_id' => 'integer',
        'computer_id' => 'integer',
        'club_id' => 'integer',
        'points' => 'integer',
        'created_at' => 'datetime',
    ];

    public function war(): BelongsTo
    {
        return $this->belongsTo(ClanWar::class, 'clan_war_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
