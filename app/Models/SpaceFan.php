<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpaceFan extends Model
{
    public const MODE_AUTO = 'auto';

    public const MODE_FORCE_ON = 'force_on';

    public const MODE_FORCE_OFF = 'force_off';

    /** Cascade stages (desired_power / applied_power). */
    public const SPEED_NIGHT = 1; // 120V — K1 OFF, K2 OFF

    public const SPEED_MID = 2;   // 170V — K1 ON,  K2 OFF

    public const SPEED_HIGH = 3;  // 220V — K1 OFF, K2 ON

    protected $fillable = [
        'club_id',
        'space_id',
        'relay_board_id',
        'channel',
        'channel2',
        'manual_mode',
        'desired_power',
        'applied_power',
        'default_on_power',
        'thermal_on_c',
        'thermal_off_c',
        'last_error',
        'last_applied_at',
        'last_manual_at',
        'last_manual_by_computer_id',
        'last_applied_by_computer_id',
    ];

    protected $casts = [
        'club_id' => 'integer',
        'space_id' => 'integer',
        'relay_board_id' => 'integer',
        'channel' => 'integer',
        'channel2' => 'integer',
        'desired_power' => 'integer',
        'applied_power' => 'integer',
        'default_on_power' => 'integer',
        'thermal_on_c' => 'integer',
        'thermal_off_c' => 'integer',
        'last_applied_at' => 'datetime',
        'last_manual_at' => 'datetime',
        'last_manual_by_computer_id' => 'integer',
        'last_applied_by_computer_id' => 'integer',
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

    public function lastManualByComputer(): BelongsTo
    {
        return $this->belongsTo(Computer::class, 'last_manual_by_computer_id');
    }

    public function lastAppliedByComputer(): BelongsTo
    {
        return $this->belongsTo(Computer::class, 'last_applied_by_computer_id');
    }

    /**
     * True when above night/duty (mid or high) — used for orphan / UI "spinning hard".
     */
    public function isOn(): bool
    {
        return (int) $this->applied_power >= self::SPEED_MID;
    }

    public static function normalizeSpeed(int $power): int
    {
        if ($power <= 0) {
            return self::SPEED_NIGHT;
        }
        if ($power === self::SPEED_MID) {
            return self::SPEED_MID;
        }
        if ($power === self::SPEED_HIGH || $power >= 90) {
            return self::SPEED_HIGH;
        }
        if ($power === self::SPEED_NIGHT || $power < 50) {
            return self::SPEED_NIGHT;
        }

        return self::SPEED_MID;
    }

    /**
     * @return array{0:bool,1:bool} [K1, K2]
     */
    public static function speedToRelays(int $speed): array
    {
        return match (self::normalizeSpeed($speed)) {
            self::SPEED_MID => [true, false],
            self::SPEED_HIGH => [false, true],
            default => [false, false], // night
        };
    }

    public static function relaysToSpeed(bool $k1, bool $k2): int
    {
        if ($k2) {
            return self::SPEED_HIGH;
        }
        if ($k1) {
            return self::SPEED_MID;
        }

        return self::SPEED_NIGHT;
    }
}
