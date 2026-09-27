<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcTicket extends Model
{
    protected $fillable = [
        'user_id', 'session_id', 'club_id', 'match_id', 'steam_id',
        'token_hash', 'scope', 'connect_expires_at', 'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'connect_expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcSession::class, 'session_id');
    }
}
