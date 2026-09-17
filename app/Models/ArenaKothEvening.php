<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArenaKothEvening extends Model
{
    protected $fillable = [
        'club_id',
        'recorded_on',
        'user_id',
        'streak',
        'perk_kind',
        'perk_label',
        'perk_awarded_at',
    ];

    protected function casts(): array
    {
        return [
            'recorded_on' => 'date',
            'streak' => 'integer',
            'perk_awarded_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
