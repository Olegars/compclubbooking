<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComputerGame extends Model
{
    protected $fillable = [
        'computer_id',
        'game_id',
        'is_installed',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'is_installed' => 'boolean',
            'verified_at' => 'immutable_datetime',
        ];
    }

    public function computer(): BelongsTo
    {
        return $this->belongsTo(Computer::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
