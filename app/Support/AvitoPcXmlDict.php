<?php

namespace App\Support;

/**
 * Допустимые значения XML Avito для «Настольные компьютеры / Системные блоки».
 * Без совпадения со справочником автозагрузка отклоняет объявление.
 */
class AvitoPcXmlDict
{
    public const CATEGORY = 'Настольные компьютеры';

    public const GOODS_SUB_TYPE = 'Системные блоки';

    public const AD_TYPE = 'Товар приобретен на продажу';

    public const CONDITION = 'Новое';

    public const BRAND = 'Другой';

    /** @return list<string> */
    public static function pcTypes(): array
    {
        return ['Игровой', 'Офисный', 'Для учебы', 'Другой'];
    }

    /** @return list<string> */
    public static function processorBrands(): array
    {
        return ['Intel', 'AMD'];
    }

    /** @return list<string> */
    public static function processorModels(): array
    {
        return [
            'Core i3', 'Core i5', 'Core i7', 'Core i9',
            'Core Ultra 5', 'Core Ultra 7', 'Core Ultra 9',
            'Pentium', 'Celeron',
            'Ryzen 3', 'Ryzen 5', 'Ryzen 7', 'Ryzen 9',
            'Ryzen Threadripper', 'Athlon',
        ];
    }

    /** @return list<string> */
    public static function processorCodes(): array
    {
        return StoreComponentSpecs::dictionaries()['cpu_model'];
    }

    /** @return list<string> */
    public static function motherboardBrands(): array
    {
        return ['ASUS', 'MSI', 'Gigabyte', 'ASRock', 'Biostar', 'Colorful', 'Maxsun', 'NZXT', 'Другой'];
    }

    /** @return list<string> */
    public static function videocardBrands(): array
    {
        return ['NVIDIA', 'AMD', 'Intel'];
    }

    /** @return list<string> */
    public static function videocardModels(): array
    {
        return [
            'GeForce GTX 1650', 'GeForce GTX 1660 Super',
            'GeForce RTX 3050', 'GeForce RTX 3060', 'GeForce RTX 3060 Ti',
            'GeForce RTX 3070', 'GeForce RTX 3070 Ti', 'GeForce RTX 3080', 'GeForce RTX 3090',
            'GeForce RTX 4060', 'GeForce RTX 4060 Ti',
            'GeForce RTX 4070', 'GeForce RTX 4070 Super', 'GeForce RTX 4070 Ti', 'GeForce RTX 4070 Ti Super',
            'GeForce RTX 4080', 'GeForce RTX 4080 Super', 'GeForce RTX 4090',
            'GeForce RTX 5050', 'GeForce RTX 5060', 'GeForce RTX 5060 Ti',
            'GeForce RTX 5070', 'GeForce RTX 5070 Ti', 'GeForce RTX 5080', 'GeForce RTX 5090',
            'Radeon RX 6600', 'Radeon RX 6700 XT', 'Radeon RX 6750 XT',
            'Radeon RX 7600', 'Radeon RX 7600 XT', 'Radeon RX 7700 XT',
            'Radeon RX 7800 XT', 'Radeon RX 7900 GRE', 'Radeon RX 7900 XT', 'Radeon RX 7900 XTX',
            'Radeon RX 9060 XT', 'Radeon RX 9070', 'Radeon RX 9070 XT',
            'Arc A750', 'Arc A770', 'Arc B580',
        ];
    }

    /** @return list<string> */
    public static function ramSizes(): array
    {
        return ['4 ГБ', '8 ГБ', '16 ГБ', '32 ГБ', '64 ГБ', '128 ГБ'];
    }

    public static function ramSizeForGb(int $gb): string
    {
        foreach ([128, 64, 32, 16, 8, 4] as $step) {
            if ($gb >= $step) {
                return $step.' ГБ';
            }
        }

        return '8 ГБ';
    }

    public static function closest(array $allowed, ?string $value, ?string $fallback = null): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return $fallback;
        }

        foreach ($allowed as $item) {
            if (mb_strtolower((string) $item) === mb_strtolower($value)) {
                return (string) $item;
            }
        }

        $compact = self::compact($value);
        $best = null;
        $bestScore = 0;
        foreach ($allowed as $item) {
            $item = (string) $item;
            $hay = self::compact($item);
            if ($hay !== '' && ($compact === $hay || str_contains($hay, $compact) || str_contains($compact, $hay))) {
                $score = mb_strlen($hay);
                if ($score > $bestScore) {
                    $best = $item;
                    $bestScore = $score;
                }
            }
        }
        if ($best !== null) {
            return $best;
        }

        $bestPct = 0;
        foreach ($allowed as $item) {
            similar_text(mb_strtolower($value), mb_strtolower((string) $item), $pct);
            if ($pct > $bestPct) {
                $bestPct = $pct;
                $best = (string) $item;
            }
        }

        return $bestPct >= 55 ? $best : $fallback;
    }

    public static function compact(string $value): string
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/\bgeforce\b/u', '', $value) ?? $value;
        $value = preg_replace('/\bradeon\b/u', '', $value) ?? $value;
        $value = preg_replace('/[^a-z0-9а-яё]+/u', '', $value) ?? $value;

        return $value;
    }
}
