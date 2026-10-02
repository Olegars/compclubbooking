<?php

namespace App\Models;

use App\Services\Light\WledCorridorCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WledController extends Model
{
    protected $fillable = [
        'club_id',
        'name',
        'host',
        'http_port',
        'is_active',
        'idle_on',
        'idle_color',
        'idle_brightness',
        'bindings',
        'last_error',
        'last_played_at',
    ];

    protected $casts = [
        'club_id' => 'integer',
        'http_port' => 'integer',
        'is_active' => 'boolean',
        'idle_on' => 'boolean',
        'idle_brightness' => 'integer',
        'bindings' => 'array',
        'last_played_at' => 'datetime',
    ];

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function cues(): HasMany
    {
        return $this->hasMany(WledCue::class);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function binding(string $eventId): ?array
    {
        $map = WledCorridorCatalog::sanitizeMap(is_array($this->bindings) ? $this->bindings : []);

        return $map[$eventId] ?? null;
    }
}
