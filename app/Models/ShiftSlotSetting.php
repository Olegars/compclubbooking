<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class ShiftSlotSetting extends Model
{
    public const HOURS_12 = 12;

    public const HOURS_24 = 24;

    protected $fillable = [
        'club_id',
        'hours',
        'starts_hour',
    ];

    protected $casts = [
        'hours' => 'integer',
        'starts_hour' => 'integer',
    ];

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public static function hoursFor(?int $clubId): int
    {
        $row = static::rowFor($clubId);
        $hours = (int) ($row?->hours ?? self::HOURS_12);

        return in_array($hours, [self::HOURS_12, self::HOURS_24], true)
            ? $hours
            : self::HOURS_12;
    }

    public static function startsHourFor(?int $clubId): int
    {
        $row = static::rowFor($clubId);
        if (! $row) {
            return 10;
        }

        $hour = (int) $row->starts_hour;

        return $hour >= 0 && $hour <= 23 ? $hour : 10;
    }

    public static function put(?int $clubId, int $hours, int $startsHour): self
    {
        if (! in_array($hours, [self::HOURS_12, self::HOURS_24], true)) {
            throw new RuntimeException('Модель смены: 12 или 24 часа.');
        }

        if ($startsHour < 0 || $startsHour > 23) {
            throw new RuntimeException('Час начала смены: 0–23.');
        }

        return static::query()->updateOrCreate(
            ['club_id' => $clubId],
            [
                'hours' => $hours,
                'starts_hour' => $startsHour,
            ]
        );
    }

    private static function rowFor(?int $clubId): ?self
    {
        return static::query()->where('club_id', $clubId)->first();
    }
}
