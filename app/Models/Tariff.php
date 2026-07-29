<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tariff extends Model
{
    use HasFactory;

    // Разрешаем массовое заполнение этих полей из админки
    protected $fillable = [
        'name',
        'category',
        'threshold_hours',
        'price_per_package',
        'is_active',
    ];

    // Указываем Laravel правильные типы данных
    protected $casts = [
        'is_active' => 'boolean',
        'threshold_hours' => 'integer',
        'price_per_package' => 'decimal:2', // Цена всегда с двумя знаками после запятой
    ];

    public function prices(): HasMany
    {
        return $this->hasMany(TariffPrice::class);
    }
}
