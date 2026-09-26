<?php

namespace App\Support;

/**
 * Разложение ставки за смену на окладную часть по МРОТ и премию.
 * Считаем в копейках, чтобы 241,50 × 1,2 не плавало.
 */
final class StaffShiftPay
{
    public static function hourlyKopeks(): int
    {
        return (int) round(((float) config('staff_pay.hourly_mrot', 241.5)) * 100);
    }

    public static function dayHours(): int
    {
        return (int) config('staff_pay.day_hours', 16);
    }

    public static function nightHours(): int
    {
        return (int) config('staff_pay.night_hours', 8);
    }

    public static function nightCoefficient(): float
    {
        return (float) config('staff_pay.night_coefficient', 1.2);
    }

    public static function dayKopeks(): int
    {
        return self::hourlyKopeks() * self::dayHours();
    }

    public static function nightHourKopeks(): int
    {
        $coefMilli = (int) round(self::nightCoefficient() * 1000);

        return intdiv(self::hourlyKopeks() * $coefMilli + 500, 1000);
    }

    public static function nightKopeks(): int
    {
        return self::nightHourKopeks() * self::nightHours();
    }

    public static function officialKopeks(): int
    {
        return self::dayKopeks() + self::nightKopeks();
    }

    public static function hourly(): float
    {
        return self::rubles(self::hourlyKopeks());
    }

    public static function dayPay(): float
    {
        return self::rubles(self::dayKopeks());
    }

    public static function nightPay(): float
    {
        return self::rubles(self::nightKopeks());
    }

    public static function official(): float
    {
        return self::rubles(self::officialKopeks());
    }

    /**
     * @return array{shift_rate: float, official: float, bonus: float}|null
     */
    public static function split(float $shiftRate): ?array
    {
        $entered = (int) round($shiftRate * 100);
        $official = self::officialKopeks();
        if ($entered < $official) {
            return null;
        }

        return [
            'shift_rate' => self::rubles($entered),
            'official' => self::rubles($official),
            'bonus' => self::rubles($entered - $official),
        ];
    }

    public static function rubles(int $kopeks): float
    {
        return $kopeks / 100;
    }
}
