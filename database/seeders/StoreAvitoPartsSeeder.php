<?php

namespace Database\Seeders;

use App\Models\StoreAvitoPart;
use Illuminate\Database\Seeder;

class StoreAvitoPartsSeeder extends Seeder
{
    public function run(): void
    {
        $order = 0;
        foreach ($this->cpus() as $row) {
            $this->upsert('cpu', $row, $order++);
        }
        foreach ($this->gpus() as $row) {
            $this->upsert('gpu', $row, $order++);
        }
        foreach ($this->rams() as $row) {
            $this->upsert('ram', $row, $order++);
        }
        foreach ($this->ssds() as $row) {
            $this->upsert('ssd', $row, $order++);
        }
        foreach ($this->psus() as $row) {
            $this->upsert('psu', $row, $order++);
        }

        $keepGpu = array_column($this->gpus(), 'code');
        StoreAvitoPart::query()
            ->where('type', 'gpu')
            ->whereNotIn('code', $keepGpu)
            ->update(['enabled' => false]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function upsert(string $type, array $row, int $order): void
    {
        StoreAvitoPart::query()->updateOrCreate(
            ['code' => $row['code']],
            array_merge($row, [
                'type' => $type,
                'sort_order' => $order,
                'enabled' => true,
            ])
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cpus(): array
    {
        $out = [];
        foreach ([
            'LGA1700' => [
                'Intel' => [
                    'Core i3' => ['12100', '12100F', '13100', '13100F', '14100', '14100F'],
                    'Core i5' => ['12400', '12400F', '12600', '12600K', '12600KF', '13400', '13400F', '13500', '13600', '13600K', '13600KF', '14400', '14400F', '14500', '14600', '14600K', '14600KF'],
                    'Core i7' => ['12700', '12700F', '12700K', '12700KF', '13700', '13700F', '13700K', '13700KF', '14700', '14700F', '14700K', '14700KF'],
                    'Core i9' => ['12900', '12900K', '12900KF', '13900', '13900K', '13900KF', '14900', '14900K', '14900KF'],
                ],
            ],
            'LGA1851' => [
                'Intel' => [
                    'Core Ultra 5' => ['225', '235', '245', '245K', '245KF'],
                    'Core Ultra 7' => ['265', '265K', '265KF'],
                    'Core Ultra 9' => ['285', '285K', '285T'],
                ],
            ],
            'AM4' => [
                'AMD' => [
                    'Ryzen 5' => ['5600', '5600X', '5600G'],
                    'Ryzen 7' => ['5700G', '5700X', '5800X', '5800X3D'],
                    'Ryzen 9' => ['5900X', '5950X'],
                ],
            ],
            'AM5' => [
                'AMD' => [
                    'Ryzen 5' => ['7500F', '7600', '7600X', '9600X'],
                    'Ryzen 7' => ['7700', '7700X', '7800X3D', '9700X', '9800X3D'],
                    'Ryzen 9' => ['7900', '7900X', '7950X', '7950X3D', '9900X', '9950X'],
                ],
            ],
        ] as $socket => $brands) {
            foreach ($brands as $brand => $seriesMap) {
                foreach ($seriesMap as $series => $codes) {
                    foreach ($codes as $code) {
                        $out[] = [
                            'code' => 'cpu-'.strtolower($code),
                            'label' => $brand.' '.$series.'-'.$code.' · '.$socket,
                            'socket' => $socket,
                            'avito_brand' => $brand,
                            'avito_model' => $series,
                            'avito_code' => $code,
                        ];
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function gpus(): array
    {
        $chips = [
            'RTX 4060', 'RTX 4060 Ti', 'RTX 4070', 'RTX 4070 Super', 'RTX 4070 Ti', 'RTX 4070 Ti Super',
            'RTX 4080', 'RTX 4080 Super', 'RTX 4090',
            'RTX 5050', 'RTX 5060', 'RTX 5060 Ti', 'RTX 5070', 'RTX 5070 Ti', 'RTX 5080', 'RTX 5090',
            'RX 7600', 'RX 7600 XT', 'RX 7700 XT', 'RX 7800 XT',
            'RX 7900 GRE', 'RX 7900 XT', 'RX 7900 XTX',
            'RX 9060 XT', 'RX 9070', 'RX 9070 XT',
        ];
        $out = [];
        foreach ($chips as $chip) {
            $out[] = [
                'code' => 'gpu-'.strtolower(str_replace(' ', '-', $chip)),
                'label' => $chip,
                'avito_code' => $chip,
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rams(): array
    {
        $out = [];
        foreach (['DDR4', 'DDR5'] as $ddr) {
            foreach ([16, 32] as $gb) {
                $out[] = [
                    'code' => 'ram-'.strtolower($ddr).'-'.$gb,
                    'label' => $ddr.' '.$gb.' ГБ',
                    'ddr' => $ddr,
                    'ram_gb' => $gb,
                    'avito_code' => $gb.' ГБ',
                ];
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ssds(): array
    {
        $out = [];
        foreach ([256, 512] as $gb) {
            $out[] = [
                'code' => 'ssd-m2-'.$gb,
                'label' => 'SSD M.2 '.$gb.' ГБ',
                'capacity_gb' => $gb,
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function psus(): array
    {
        $out = [];
        foreach ([500, 550, 600, 650, 700, 750, 800, 850] as $w) {
            $out[] = [
                'code' => 'psu-'.$w,
                'label' => 'БП '.$w.' Вт',
                'wattage' => $w,
            ];
        }

        return $out;
    }
}
