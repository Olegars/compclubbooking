<?php

namespace App\Support;

use RuntimeException;

class RussianIdentity
{
    public static function normalizeSnils(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) !== 11 || ! self::snilsChecksumOk($digits)) {
            throw new RuntimeException('СНИЛС: формат XXX-XXX-XXX YY, контрольное число не сходится.');
        }

        return substr($digits, 0, 3).'-'.substr($digits, 3, 3).'-'.substr($digits, 6, 3).' '.substr($digits, 9, 2);
    }

    public static function normalizeInn(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) !== 12 || ! self::inn12Ok($digits)) {
            throw new RuntimeException('ИНН физлица: 12 цифр, контрольные разряды не сходятся.');
        }

        return $digits;
    }

    public static function employerInnOk(string $value): bool
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) === 10) {
            return self::inn10Ok($digits);
        }
        if (strlen($digits) === 12) {
            return self::inn12Ok($digits);
        }

        return false;
    }

    /**
     * @return array{last: string, first: string, middle: string}
     */
    public static function splitFio(string $full): array
    {
        $parts = preg_split('/\s+/u', trim($full)) ?: [];
        $parts = array_values(array_filter($parts, fn ($part) => $part !== ''));
        if (count($parts) < 2) {
            throw new RuntimeException('В анкете нужно полное ФИО: фамилия и имя.');
        }

        return [
            'last' => $parts[0],
            'first' => $parts[1],
            'middle' => implode(' ', array_slice($parts, 2)),
        ];
    }

    public static function shortName(string $full): string
    {
        try {
            $fio = self::splitFio($full);
        } catch (RuntimeException) {
            return trim($full);
        }

        $initial = mb_substr($fio['first'], 0, 1);

        return $fio['last'].' '.$initial.'.';
    }

    private static function snilsChecksumOk(string $digits): bool
    {
        $number = (int) substr($digits, 0, 9);
        if ($number <= 1001998) {
            return true;
        }

        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $digits[$i] * (9 - $i);
        }

        if ($sum < 100) {
            $check = $sum;
        } elseif ($sum === 100 || $sum === 101) {
            $check = 0;
        } else {
            $check = $sum % 101;
            if ($check === 100) {
                $check = 0;
            }
        }

        return $check === (int) substr($digits, 9, 2);
    }

    private static function inn12Ok(string $digits): bool
    {
        $n1 = self::control($digits, [7, 2, 4, 10, 3, 5, 9, 4, 6, 8]);
        $n2 = self::control($digits, [3, 7, 2, 4, 10, 3, 5, 9, 4, 6, 8]);

        return $n1 === (int) $digits[10] && $n2 === (int) $digits[11];
    }

    private static function inn10Ok(string $digits): bool
    {
        $n = self::control($digits, [2, 4, 10, 3, 5, 9, 4, 6, 8]);

        return $n === (int) $digits[9];
    }

    /**
     * @param  list<int>  $coeffs
     */
    private static function control(string $digits, array $coeffs): int
    {
        $sum = 0;
        foreach ($coeffs as $i => $coeff) {
            $sum += (int) $digits[$i] * $coeff;
        }

        return ($sum % 11) % 10;
    }
}
