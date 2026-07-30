<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReviewSetting extends Model
{
    protected $fillable = [
        'bonus_amount',
        'site_reviews_limit',
        'show_on_site',
    ];

    protected $casts = [
        'bonus_amount' => 'float',
        'site_reviews_limit' => 'integer',
        'show_on_site' => 'boolean',
    ];

    public static function current(): self
    {
        $row = static::query()->orderBy('id')->first();
        if ($row) {
            return $row;
        }

        return static::query()->create([
            'bonus_amount' => (float) config('club.reviews.bonus_amount', 100),
            'site_reviews_limit' => 6,
            'show_on_site' => true,
        ]);
    }
}
