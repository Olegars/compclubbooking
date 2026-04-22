<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Club extends Model
{
    // 1. Добавляем новые поля в разрешенные для заполнения
    protected $fillable = [
        'name',
        'slug',
        'address',
        'map_config', // Наш JSON
        'viewbox'     // Наша рамка
    ];

    // 2. ДОБАВЛЯЕМ СЮДА: Автоматическое приведение типов
    protected $casts = [
        'map_config' => 'array', // Laravel сам сделает json_decode при чтении
    ];

    public function computers(): HasMany
    {
        return $this->hasMany(Computer::class);
    }
}
