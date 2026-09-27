<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserIdentity extends Model
{
    protected $fillable = [
        'user_id', 'provider', 'external_id', 'meta', 'synced_at', 'unlinked_at', 'purge_after',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'synced_at' => 'datetime',
            'unlinked_at' => 'datetime',
            'purge_after' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
