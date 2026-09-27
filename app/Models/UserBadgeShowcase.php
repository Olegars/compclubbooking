<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserBadgeShowcase extends Model
{
    protected $fillable = ['user_id', 'badge_id', 'slot'];
}
