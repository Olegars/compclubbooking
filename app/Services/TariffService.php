<?php
namespace App\Services;

use App\Models\Tariff;

class TariffService
{
    /**
     * Рассчитать лучшую стоимость для указанного количества часов
     */
    public function calculateBestPrice(float $hours)
    {
        // Ищем самый выгодный подходящий тариф
        // Берем тариф, где порог часов максимальный, но не больше текущего времени игрока
        $tariff = Tariff::where('is_active', true)
            ->where('threshold_hours', '<=', $hours)
            ->orderBy('threshold_hours', 'desc')
            ->first();

        if (!$tariff) return $hours * 150; // Цена по умолчанию, если тарифов нет

        // Если это точное совпадение (например, ровно 3 часа)
        if ($tariff->threshold_hours == $hours) {
            return $tariff->price_per_package;
        }

        // Если часов больше, чем в пакете (например, 4 часа при пакете на 3)
        // Считаем пакет + остаток по стандартной цене (или ищем следующий уровень)
        $extraHours = $hours - $tariff->threshold_hours;
        $standardRate = 150;

        return $tariff->price_per_package + ($extraHours * $standardRate);
    }
}
