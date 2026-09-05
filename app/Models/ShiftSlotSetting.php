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
    ];

    protected $casts = [
        'hours' => 'integer',
    ];

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public static function hoursFor(?int $clubId): int
    {
        $hours = (int) static::query()->where('club_id', $clubId)->value('hours');

        return in_array($hours, [self::HOURS_12, self::HOURS_24], true)
            ? $hours
            : self::HOURS_12;
    }

    public static function put(?int $clubId, int $hours): self
    {
        if (! in_array($hours, [self::HOURS_12, self::HOURS_24], true)) {
            throw new RuntimeException('Модель смены: 12 или 24 часа.');
        }

        return static::query()->updateOrCreate(
            ['club_id' => $clubId],
            ['hours' => $hours]
        );
    }
}
