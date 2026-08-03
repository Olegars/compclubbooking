<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RelayBoard extends Model
{
    public const DRIVER_W5100_HTTP = 'w5100_http';

    public const DRIVER_KINCONY_HTTP = 'kincony_http';

    public const DRIVER_DINGTIAN_HTTP = 'dingtian_http';

    protected $fillable = [
        'club_id',
        'name',
        'driver',
        'host',
        'port',
        'meta',
        'is_active',
    ];

    protected $casts = [
        'club_id' => 'integer',
        'port' => 'integer',
        'meta' => 'array',
        'is_active' => 'boolean',
    ];

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function spaceFans(): HasMany
    {
        return $this->hasMany(SpaceFan::class);
    }
}
