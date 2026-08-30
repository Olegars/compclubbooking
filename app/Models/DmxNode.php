<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DmxNode extends Model
{
    public const DEFAULT_PORT = 6454;

    protected $fillable = [
        'club_id',
        'name',
        'host',
        'port',
        'universe',
        'is_active',
    ];

    protected $casts = [
        'club_id' => 'integer',
        'port' => 'integer',
        'universe' => 'integer',
        'is_active' => 'boolean',
    ];

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function spaceLights(): HasMany
    {
        return $this->hasMany(SpaceLight::class);
    }
}
