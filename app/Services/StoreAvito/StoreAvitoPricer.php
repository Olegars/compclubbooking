<?php

namespace App\Services\StoreAvito;

use App\Models\StoreAvitoSetting;

class StoreAvitoPricer
{
    /**
     * @param  list<array{purchase?:float|int|string|null, sale?:float|int|string|null}>  $parts
     */
    public function quote(array $parts, ?StoreAvitoSetting $settings = null): int
    {
        $settings ??= StoreAvitoSetting::current();
        $sum = 0.0;
        foreach ($parts as $part) {
            $purchase = (float) ($part['purchase'] ?? 0);
            $sum += $purchase;
        }

        $markup = max(0, (float) $settings->markup_percent) / 100;
        $extra = max(0, (float) $settings->extra_rub);
        $price = $sum * (1 + $markup) + $extra;

        if ($price >= 100000) {
            $price -= $price * (max(0, (float) $settings->discount_over_100k_pct) / 100);
        } elseif ($price > 60000) {
            $price -= $price * (max(0, (float) $settings->discount_over_60k_pct) / 100);
        }

        $step = max(1, (int) $settings->round_to);

        return (int) (round($price / $step) * $step);
    }

    public function saleOf(float $purchase, ?StoreAvitoSetting $settings = null): float
    {
        $settings ??= StoreAvitoSetting::current();
        $markup = max(0, (float) $settings->markup_percent) / 100;

        return round($purchase * (1 + $markup), 2);
    }
}
