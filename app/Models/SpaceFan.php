<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpaceFan extends Model
{
    public const MODE_AUTO = 'auto';

    public const MODE_FORCE_ON = 'force_on';

    public const MODE_FORCE_OFF = 'force_off';

    protected $fillable = [
        'club_id',
        'space_id',
        'relay_board_id',
        'channel',
        'manual_mode',
        'desired_power',
        'applied_power',
        'default_on_power',
        'thermal_on_c',
        'thermal_off_c',
        'last_error',
        'last_applied_at',
    ];

    protected $casts = [
        'club_id' => 'integer',
        'space_id' => 'integer',
        'relay_board_id' => 'integer',
        'channel' => 'integer',
        'desired_power' => 'integer',
        'applied_power' => 'integer',
        'default_on_power' => 'integer',
        'thermal_on_c' => 'integer',
        'thermal_off_c' => 'integer',
        'last_applied_at' => 'datetime',
    ];

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function relayBoard(): BelongsTo
    {
        return $this->belongsTo(RelayBoard::class);
    }

    public function isOn(): bool
    {
        return (int) $this->applied_power > 0;
    }
}
