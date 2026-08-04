<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SharedFan extends Model
{
    public const KIND_SUPPLY = 'supply';

    public const KIND_EXHAUST = 'exhaust';

    public const LOAD_STEPS = [50, 60, 70, 80, 90, 100];

    protected $fillable = [
        'club_id',
        'kind',
        'name',
        'relay_board_id',
        'channel',
        'channel2',
        'desired_power',
        'applied_power',
        'last_error',
        'last_applied_at',
    ];

    protected $casts = [
        'club_id' => 'integer',
        'relay_board_id' => 'integer',
        'channel' => 'integer',
        'channel2' => 'integer',
        'desired_power' => 'integer',
        'applied_power' => 'integer',
        'last_applied_at' => 'datetime',
    ];

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function relayBoard(): BelongsTo
    {
        return $this->belongsTo(RelayBoard::class);
    }

    public function maps(): HasMany
    {
        return $this->hasMany(SharedFanMap::class);
    }

    public function links(): HasMany
    {
        return $this->hasMany(SharedFanLink::class);
    }

    public function spaceFans(): BelongsToMany
    {
        return $this->belongsToMany(SpaceFan::class, 'shared_fan_links')
            ->withTimestamps();
    }

    public function isSupply(): bool
    {
        return $this->kind === self::KIND_SUPPLY;
    }

    public function isExhaust(): bool
    {
        return $this->kind === self::KIND_EXHAUST;
    }

    /**
     * Seed default load→output map (all → 50%).
     */
    public function seedDefaultMaps(): void
    {
        foreach (self::LOAD_STEPS as $load) {
            SharedFanMap::query()->firstOrCreate(
                [
                    'shared_fan_id' => $this->id,
                    'load_pct' => $load,
                ],
                ['output_pct' => 50]
            );
        }
    }
}
