<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewClaim extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';

    public const SOURCE_YANDEX = 'yandex';
    public const SOURCE_2GIS = '2gis';

    protected $fillable = [
        'user_id',
        'review_text',
        'source',
        'external_review_id',
        'external_author_id',
        'matched_score',
        'review_link',
        'bonus_amount',
        'status',
        'verified_at',
        'expires_at',
    ];

    protected $casts = [
        'bonus_amount' => 'float',
        'matched_score' => 'float',
        'verified_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function normalizeText(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $text = str_replace('ё', 'е', $text);
        $text = str_replace(
            ['«', '»', '“', '”', '„', '‟', '‘', '’', '"', "'"],
            '',
            $text
        );
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * Similarity ratio 0..1 between two strings (after optional normalize).
     */
    public static function similarity(string $a, string $b, bool $normalize = true): float
    {
        if ($normalize) {
            $a = static::normalizeText($a);
            $b = static::normalizeText($b);
        }

        if ($a === '' || $b === '') {
            return 0.0;
        }

        if ($a === $b) {
            return 1.0;
        }

        similar_text($a, $b, $percent);

        return round($percent / 100, 4);
    }
}
