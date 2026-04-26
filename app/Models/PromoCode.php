<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromoCode extends Model
{
    protected $fillable = [
        'code',
        'type',
        'value',
        'max_uses',
        'used_count'
    ];

    /**
     * Связь: Пользователи, которые уже активировали этот код.
     * Используется для защиты от повторной активации одним человеком.
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'promo_code_user')->withTimestamps();
    }
}
