<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcSession extends Model
{
    protected $fillable = [
        'user_id', 'club_id', 'computer_id', 'token_hash', 'steam_id', 'hwid_digest',
        'kind', 'status', 'match_id', 'integrity_ok', 'integrity_flags',
        'client_version', 'os', 'last_heartbeat_at',
    ];

    protected function casts(): array
    {
        return [
            'integrity_ok' => 'boolean',
            'integrity_flags' => 'array',
            'last_heartbeat_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
