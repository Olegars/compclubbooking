<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

class ClubBookingSetting extends Model
{
    protected $fillable = [
        'cancel_before_minutes',
    ];

    protected $casts = [
        'cancel_before_minutes' => 'integer',
    ];

    public static function current(): self
    {
        $row = static::query()->orderBy('id')->first();
        if ($row) {
            return $row;
        }

        return static::query()->create([
            'cancel_before_minutes' => max(
                0,
                (int) config('club.booking.cancel_before_minutes', 120)
            ),
        ]);
    }

    public function cancelBeforeMinutes(): int
    {
        return max(0, (int) $this->cancel_before_minutes);
    }

    public function cancelDeadline(CarbonImmutable $startsAt): CarbonImmutable
    {
        return $startsAt->subMinutes($this->cancelBeforeMinutes());
    }

    public function canCancelAt(CarbonImmutable $startsAt, ?CarbonImmutable $now = null): bool
    {
        $now = $now ?? CarbonImmutable::now();

        return $now->lt($this->cancelDeadline($startsAt));
    }
}
