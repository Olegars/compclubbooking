<?php

namespace App\Support;

/**
 * Конструктор названия комплектующих: поля по типу + словари для автоподсказок.
 */
class StoreComponentSpecs
{
    /**
     * Схема полей формы по типу.
     * key — ключ в specs JSON, label — подпись, suggest — словарь автоподсказок.
     */
    public static function schemas(): array
    {
        return [
            'cpu' => [
                ['key' => 'brand', 'label' => 'Бренд', 'suggest' => 'cpu_brand'],
                ['key' => 'socket', 'label' => 'Сокет', 'suggest' => 'cpu_socket'],
                ['key' => 'series', 'label' => 'Линейка', 'suggest' => 'cpu_series'],
                ['key' => 'model', 'label' => 'Модель', 'suggest' => 'cpu_model'],
            ],
            'ram' => [
                ['key' => 'brand', 'label' => 'Бренд', 'suggest' => 'ram_brand'],
                ['key' => 'ddr', 'label' => 'Тип памяти', 'suggest' => 'ram_ddr'],
                ['key' => 'modules', 'label' => 'Комплект (модулей)', 'suggest' => 'ram_modules'],
                ['key' => 'capacity', 'label' => 'Объём модуля', 'suggest' => 'ram_capacity'],
                ['key' => 'speed', 'label' => 'Частота, МГц', 'suggest' => 'ram_speed'],
                ['key' => 'form', 'label' => 'Форм-фактор', 'suggest' => 'ram_form'],
            ],
            'motherboard' => [
                ['key' => 'brand', 'label' => 'Бренд', 'suggest' => 'mb_brand'],
                ['key' => 'socket', 'label' => 'Сокет', 'suggest' => 'cpu_socket'],
                ['key' => 'chipset', 'label' => 'Чипсет', 'suggest' => 'mb_chipset'],
                ['key' => 'model', 'label' => 'Модель', 'suggest' => 'mb_model'],
            ],
            'gpu' => [
                ['key' => 'brand', 'label' => 'Бренд', 'suggest' => 'gpu_brand'],
                ['key' => 'chip', 'label' => 'Чип / модель', 'suggest' => 'gpu_chip'],
                ['key' => 'vram', 'label' => 'Видеопамять', 'suggest' => 'gpu_vram'],
            ],
            'storage_ssd' => [
                ['key' => 'brand', 'label' => 'Бренд', 'suggest' => 'ssd_brand'],
                ['key' => 'interface', 'label' => 'Интерфейс', 'suggest' => 'ssd_interface'],
                ['key' => 'capacity', 'label' => 'Объём', 'suggest' => 'ssd_capacity'],
                ['key' => 'model', 'label' => 'Модель', 'suggest' => 'ssd_model'],
            ],
            'storage_hdd' => [
                ['key' => 'brand', 'label' => 'Бренд', 'suggest' => 'hdd_brand'],
                ['key' => 'capacity', 'label' => 'Объём', 'suggest' => 'hdd_capacity'],
                ['key' => 'rpm', 'label' => 'Обороты', 'suggest' => 'hdd_rpm'],
            ],
            'psu' => [
                ['key' => 'brand', 'label' => 'Бренд', 'suggest' => 'psu_brand'],
                ['key' => 'wattage', 'label' => 'Мощность, Вт', 'suggest' => 'psu_watt'],
                ['key' => 'cert', 'label' => 'Сертификат', 'suggest' => 'psu_cert'],
            ],
            'case' => [
                ['key' => 'brand', 'label' => 'Бренд', 'suggest' => 'case_brand'],
                ['key' => 'form', 'label' => 'Форм-фактор', 'suggest' => 'case_form'],
                ['key' => 'model', 'label' => 'Модель', 'suggest' => 'case_model'],
            ],
            'cooler' => [
                ['key' => 'brand', 'label' => 'Бренд', 'suggest' => 'cooler_brand'],
                ['key' => 'kind', 'label' => 'Тип', 'suggest' => 'cooler_kind'],
                ['key' => 'model', 'label' => 'Модель', 'suggest' => 'cooler_model'],
            ],
            'fan' => [
                ['key' => 'brand', 'label' => 'Бренд', 'suggest' => 'fan_brand'],
                ['key' => 'size', 'label' => 'Размер', 'suggest' => 'fan_size'],
            ],
            'network' => [
                ['key' => 'brand', 'label' => 'Бренд', 'suggest' => 'net_brand'],
                ['key' => 'kind', 'label' => 'Тип', 'suggest' => 'net_kind'],
                ['key' => 'model', 'label' => 'Модель', 'suggest' => 'net_model'],
            ],
            'os' => [
                ['key' => 'name', 'label' => 'Название ОС', 'suggest' => 'os_name'],
            ],
            'other' => [
                ['key' => 'title', 'label' => 'Название', 'suggest' => 'other_title'],
            ],
        ];
    }

    public static function dictionaries(): array
    {
        return [
            'cpu_brand' => ['Intel', 'AMD'],
            'cpu_socket' => ['AM4', 'AM5', 'LGA1851', 'LGA1700', 'LGA1200', 'LGA1151', 'LGA1150', 'sTR5', 'TR4'],
            'cpu_series' => [
                'Core i3', 'Core i5', 'Core i7', 'Core i9',
                'Core Ultra', 'Core Ultra 5', 'Core Ultra 7', 'Core Ultra 9',
                'Pentium', 'Celeron',
                'Ryzen 3', 'Ryzen 5', 'Ryzen 7', 'Ryzen 9',
                'Ryzen Threadripper',
            ],
            'cpu_model' => [
                // Intel 12/13/14 gen
                '12100', '12100F', '12400', '12400F', '12600', '12600K', '12600KF',
                '12700', '12700F', '12700K', '12700KF', '12900', '12900K', '12900KF',
                '13100', '13100F', '13400', '13400F', '13500', '13600', '13600K', '13600KF',
                '13700', '13700F', '13700K', '13700KF', '13900', '13900K', '13900KF',
                '14100', '14100F', '14400', '14400F', '14500', '14600', '14600K', '14600KF',
                '14700', '14700F', '14700K', '14700KF', '14900', '14900K', '14900KF',
                // Intel Core Ultra (Meteor Lake / Arrow Lake) — Series 1
                '125H', '135H', '155H', '165H',
                '125U', '135U', '155U',
                '225H', '235H', '245H', '255H', '265H', '285H',
                // Desktop Arrow Lake (LGA1851) — Series 2
                '225', '235', '245', '245K', '245KF',
                '265', '265K', '265KF',
                '285', '285K', '285T',
                // AMD AM4 / AM5
                '5600', '5600X', '5600G', '5700X', '5700G', '5800X', '5800X3D', '5900X', '5950X',
                '7500F', '7600', '7600X', '7700', '7700X', '7800X3D', '7900', '7900X', '7950X', '7950X3D',
                '9600X', '9700X', '9800X3D', '9900X', '9950X',
            ],

            'ram_brand' => ['ADATA', 'Kingston', 'Samsung', 'Crucial', 'Corsair', 'G.Skill', 'Patriot', 'Goodram', 'Apacer'],
            'ram_ddr' => ['DDR4', 'DDR5'],
            'ram_modules' => ['1', '2', '4'],
            'ram_capacity' => ['4GB', '8GB', '16GB', '32GB', '48GB', '64GB'],
            'ram_speed' => ['2400', '2666', '3000', '3200', '3600', '4000', '4800', '5200', '5600', '6000', '6400'],
            'ram_form' => ['DIMM', 'SO-DIMM'],

            'mb_brand' => ['ASUS', 'MSI', 'Gigabyte', 'ASRock', 'Biostar'],
            'mb_chipset' => [
                'B450', 'B550', 'X570', 'A520',
                'B650', 'B650E', 'X670', 'X670E', 'A620', 'X870', 'X870E', 'B850',
                'B760', 'Z790', 'H610', 'B660', 'Z690',
                'B860', 'Z890', 'H810',
            ],
            'mb_model' => [],

            'gpu_brand' => ['ASUS', 'MSI', 'Gigabyte', 'Palit', 'NVIDIA', 'AMD', 'Sapphire', 'PowerColor'],
            'gpu_chip' => [
                'RTX 4060', 'RTX 4060 Ti', 'RTX 4070', 'RTX 4070 Super', 'RTX 4070 Ti', 'RTX 4070 Ti Super',
                'RTX 4080', 'RTX 4080 Super', 'RTX 4090',
                'RTX 5050', 'RTX 5060', 'RTX 5060 Ti', 'RTX 5070', 'RTX 5070 Ti', 'RTX 5080', 'RTX 5090',
                'RX 7600', 'RX 7600 XT', 'RX 7700 XT', 'RX 7800 XT',
                'RX 7900 GRE', 'RX 7900 XT', 'RX 7900 XTX',
                'RX 9060 XT', 'RX 9070', 'RX 9070 XT',
            ],
            'gpu_vram' => ['4GB', '6GB', '8GB', '12GB', '16GB', '24GB'],

            'ssd_brand' => ['Samsung', 'Kingston', 'WD', 'Crucial', 'ADATA', 'Seagate', 'Patriot'],
            'ssd_interface' => ['NVMe', 'SATA'],
            'ssd_capacity' => ['256GB', '512GB', '1TB', '2TB', '4TB'],
            'ssd_model' => [],

            'hdd_brand' => ['Seagate', 'WD', 'Toshiba'],
            'hdd_capacity' => ['1TB', '2TB', '4TB', '6TB', '8TB', '10TB', '12TB'],
            'hdd_rpm' => ['5400', '7200'],

            'psu_brand' => ['be quiet!', 'Corsair', 'Seasonic', 'Deepcool', 'Chieftec', 'Thermaltake'],
            'psu_watt' => ['450', '500', '550', '650', '750', '850', '1000'],
            'psu_cert' => ['White', 'Bronze', 'Silver', 'Gold', 'Platinum', 'Titanium'],

            'case_brand' => ['Deepcool', 'Corsair', 'Fractal', 'NZXT', 'Thermaltake', 'Zalman'],
            'case_form' => ['Mini-ITX', 'Micro-ATX', 'ATX', 'E-ATX'],
            'case_model' => [],

            'cooler_brand' => ['Deepcool', 'Noctua', 'be quiet!', 'ID-COOLING', 'Arctic', 'Cooler Master'],
            'cooler_kind' => ['воздушный', 'СЖО 120', 'СЖО 240', 'СЖО 360'],
            'cooler_model' => [],

            'fan_brand' => ['Arctic', 'Noctua', 'Deepcool', 'be quiet!', 'Corsair'],
            'fan_size' => ['120mm', '140mm'],

            'net_brand' => ['TP-Link', 'Intel', 'ASUS', 'Realtek'],
            'net_kind' => ['Wi-Fi', 'Ethernet', 'Wi-Fi + BT'],
            'net_model' => [],

            'os_name' => ['Windows 11 Home', 'Windows 11 Pro', 'Windows 10 Pro', 'без ОС'],
            'other_title' => [],
        ];
    }

    public static function composeName(string $type, array $specs): string
    {
        $s = fn (string $key) => trim((string) ($specs[$key] ?? ''));

        return match ($type) {
            'cpu' => (function () use ($s) {
                $series = $s('series');
                $model = $s('model');
                $mid = '';
                if ($series !== '' && $model !== '') {
                    $mid = str_contains(mb_strtolower($series), 'ultra')
                        ? $series.' '.$model
                        : $series.'-'.$model;
                } else {
                    $mid = trim($series.' '.$model);
                }

                return trim(implode(' ', array_filter([
                    $s('brand'),
                    $mid,
                    $s('socket') !== '' ? '('.$s('socket').')' : '',
                ])));
            })(),
            'ram' => trim(implode(' ', array_filter([
                $s('brand'),
                $s('ddr'),
                self::ramCapacityLabel($specs),
                $s('speed'),
                $s('form'),
            ]))),
            'motherboard' => trim(implode(' ', array_filter([
                $s('brand'), $s('chipset'), $s('model'), $s('socket') !== '' ? '('.$s('socket').')' : '',
            ]))),
            'gpu' => trim(implode(' ', array_filter([
                $s('brand'), $s('chip'), $s('vram'),
            ]))),
            'storage_ssd' => trim(implode(' ', array_filter([
                $s('brand'), $s('model'), $s('capacity'), $s('interface'),
            ]))),
            'storage_hdd' => trim(implode(' ', array_filter([
                $s('brand'), $s('capacity'), $s('rpm') !== '' ? $s('rpm').'rpm' : '',
            ]))),
            'psu' => trim(implode(' ', array_filter([
                $s('brand'), $s('wattage') !== '' ? $s('wattage').'W' : '', $s('cert'),
            ]))),
            'case' => trim(implode(' ', array_filter([
                $s('brand'), $s('model'), $s('form'),
            ]))),
            'cooler' => trim(implode(' ', array_filter([
                $s('brand'), $s('kind'), $s('model'),
            ]))),
            'fan' => trim(implode(' ', array_filter([
                $s('brand'), $s('size'),
            ]))),
            'network' => trim(implode(' ', array_filter([
                $s('brand'), $s('kind'), $s('model'),
            ]))),
            'os' => $s('name'),
            'other' => $s('title'),
            default => '',
        };
    }

    /** 2x16GB / 16GB */
    public static function ramCapacityLabel(array $specs): string
    {
        $capacity = trim((string) ($specs['capacity'] ?? ''));
        if ($capacity === '') {
            return '';
        }

        $modules = (int) preg_replace('/\D+/', '', (string) ($specs['modules'] ?? '1'));
        if ($modules < 1) {
            $modules = 1;
        }

        return $modules > 1 ? $modules.'x'.$capacity : $capacity;
    }

    public static function ramModuleCount(array $specs): int
    {
        $modules = (int) preg_replace('/\D+/', '', (string) ($specs['modules'] ?? '1'));

        return max(1, min(8, $modules ?: 1));
    }

    /** Подсказки: словарь + значения из истории specs. */
    public static function suggest(string $dictKey, string $query, array $history = [], int $limit = 12): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        // Короткие запросы (бренд WD, AM5…) — от 1 символа; иначе от 2
        if (mb_strlen($query) < 1) {
            return [];
        }

        $seed = self::dictionaries()[$dictKey] ?? [];
        $pool = array_values(array_unique(array_filter(array_merge($seed, $history))));

        $q = mb_strtolower($query);
        $matched = array_values(array_filter($pool, function ($item) use ($q) {
            return str_contains(mb_strtolower((string) $item), $q);
        }));

        usort($matched, function ($a, $b) use ($q) {
            $aStarts = str_starts_with(mb_strtolower((string) $a), $q) ? 0 : 1;
            $bStarts = str_starts_with(mb_strtolower((string) $b), $q) ? 0 : 1;
            if ($aStarts !== $bStarts) {
                return $aStarts <=> $bStarts;
            }

            return strlen((string) $a) <=> strlen((string) $b);
        });

        return array_slice($matched, 0, $limit);
    }
}
