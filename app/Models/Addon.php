<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Addon extends Model
{
    public const BILLING_ALWAYS = 'always';

    public const BILLING_OPTIONAL = 'optional';

    protected $fillable = [
        'name',
        'slug',
        'color',
        'billing_mode',
        'sort',
        'is_active',
    ];

    protected $casts = [
        'sort' => 'integer',
        'is_active' => 'boolean',
    ];

    public function prices(): HasMany
    {
        return $this->hasMany(AddonPrice::class);
    }

    public function spaces(): BelongsToMany
    {
        return $this->belongsToMany(Space::class, 'space_addon')->withTimestamps();
    }

    public function isAlways(): bool
    {
        return $this->billing_mode === self::BILLING_ALWAYS;
    }

    public function isOptional(): bool
    {
        return $this->billing_mode === self::BILLING_OPTIONAL;
    }

    public function priceForClub(int $clubId): ?float
    {
        $row = $this->relationLoaded('prices')
            ? $this->prices->firstWhere('club_id', $clubId)
            : $this->prices()->where('club_id', $clubId)->first();

        return $row ? (float) $row->price_per_hour : null;
    }
}
