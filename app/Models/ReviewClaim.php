<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReviewClaim extends Model
{
    protected $fillable = ['user_id', 'review_text', 'bonus_amount', 'status', 'verified_at'];

    public function user() {
        return $this->belongsTo(User::class);
    }
}
