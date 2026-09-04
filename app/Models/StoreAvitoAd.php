<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreAvitoAd extends Model
{
    public const STATUSES = ['active', 'archived', 'blocked'];

    protected $fillable = [
        'config_id', 'fingerprint', 'store_avito_config_id', 'title', 'description', 'price',
        'components', 'xml', 'images', 'status', 'avito_id', 'generated_at',
    ];

    protected $casts = [
        'components' => 'array',
        'xml' => 'array',
        'images' => 'array',
        'generated_at' => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
