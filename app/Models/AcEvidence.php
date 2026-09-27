<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcEvidence extends Model
{
    protected $table = 'ac_evidence';

    protected $fillable = [
        'user_id', 'session_id', 'club_id', 'kind', 'path', 'bytes', 'sha256', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
