<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MapReview extends Model
{
    protected $fillable = [
        'source',
        'external_review_id',
        'external_author_id',
        'author_name',
        'text',
        'rating',
        'url',
        'reviewed_at',
        'rewarded_user_id',
        'review_claim_id',
    ];

    protected $casts = [
        'rating' => 'float',
        'reviewed_at' => 'datetime',
    ];

    public function rewardedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rewarded_user_id');
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(ReviewClaim::class, 'review_claim_id');
    }
}
