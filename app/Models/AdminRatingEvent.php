<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdminRatingEvent extends Model
{
    public const ADD = 'add';

    public const SUBTRACT = 'subtract';

    protected $fillable = [
        'name',
        'points',
        'direction',
    ];

    protected $casts = [
        'points' => 'integer',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(AdminRatingEntry::class, 'event_id');
    }

    public function signedPoints(): int
    {
        $points = abs((int) $this->points);

        return $this->direction === self::SUBTRACT ? -$points : $points;
    }
}
