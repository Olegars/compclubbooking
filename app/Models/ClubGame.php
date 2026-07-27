<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClubGame extends Model
{
    protected $fillable = [
        'club_id',
        'game_id',
        'is_enabled',
        'billing_mode',
        'unit_price_minor',
        'billing_unit_minutes',
        'currency',
    ];

    protected $appends = [
        'is_paid',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'unit_price_minor' => 'integer',
            'billing_unit_minutes' => 'integer',
        ];
    }

    /**
     * Платная игра = любой режим кроме free.
     * Бесплатные выдаются только через shell без предварительной брони.
     */
    public function getIsPaidAttribute(): bool
    {
        return static::isPaidMode((string) ($this->attributes['billing_mode'] ?? 'free'));
    }

    public static function isPaidMode(string $billingMode): bool
    {
        return $billingMode !== 'free';
    }

    public function scopePaid($query)
    {
        return $query->where('billing_mode', '!=', 'free');
    }

    public function scopeFree($query)
    {
        return $query->where('billing_mode', 'free');
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function bookingGames(): HasMany
    {
        return $this->hasMany(BookingGame::class);
    }
}
