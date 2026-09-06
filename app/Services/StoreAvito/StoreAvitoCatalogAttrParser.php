<?php

namespace App\Services\StoreAvito;

use App\Support\AvitoPcXmlDict;
use App\Support\StoreComponentSpecs;

class StoreAvitoCatalogAttrParser
{
    /** Старые / профессиональные / посторонние карты — не идут в сборки Avito. */
    public const SKIP_GPU = 'SKIP';

    /**
     * @return array{
     *   type: ?string,
     *   socket: ?string,
     *   ddr: ?string,
     *   ram_gb: ?int,
     *   wattage: ?int,
     *   form: ?string,
     *   avito_brand: ?string,
     *   avito_model: ?string,
     *   avito_code: ?string
     * }
     */
    public function parse(string $type, string $name, string $part = '', string $vendor = ''): array
    {
        $hay = trim($name.' '.$part.' '.$vendor);
        $type = strtolower(trim($type));

        $attrs = [
            'type' => $type !== '' ? $type : null,
            'socket' => $this->socket($hay),
            'ddr' => $this->ddr($hay),
            'ram_gb' => $this->ramGb($hay),
            'wattage' => $this->wattage($hay),
            'form' => $this->form($hay),
            'avito_brand' => null,
            'avito_model' => null,
            'avito_code' => null,
        ];

        return match ($type) {
            'cpu' => $this->cpu($hay, $attrs),
            'gpu' => $this->gpu($hay, $vendor, $attrs),
            'ram' => $this->ram($hay, $attrs),
            'motherboard' => $this->motherboard($hay, $vendor, $attrs),
            'psu' => $this->psu($hay, $vendor, $attrs),
            'storage_ssd', 'ssd' => $this->named($hay, $vendor, $attrs),
            'case', 'cooler' => $this->named($hay, $vendor, $attrs),
            default => $attrs,
        };
    }

    private function cpu(string $hay, array $attrs): array
    {
        if (preg_match('/\bamd\b/iu', $hay) || preg_match('/\bryzen\b/iu', $hay)) {
            $attrs['avito_brand'] = 'AMD';
        } elseif (preg_match('/\bintel\b/iu', $hay) || preg_match('/\bcore\b/iu', $hay)) {
            $attrs['avito_brand'] = 'Intel';
        }

        $attrs['avito_model'] = AvitoPcXmlDict::closest(
            AvitoPcXmlDict::processorModels(),
            $this->processorModel($hay),
        );

        $attrs['avito_code'] = AvitoPcXmlDict::closest(
            AvitoPcXmlDict::processorCodes(),
            $this->processorCode($hay),
        );

        if (! $attrs['socket']) {
            $attrs['socket'] = $this->inferCpuSocket($attrs['avito_code'], $attrs['avito_brand']);
        }

        return $attrs;
    }

    private function gpu(string $hay, string $vendor, array $attrs): array
    {
        $attrs['avito_brand'] = $this->gpuMaker($hay, $vendor);
        $attrs['avito_model'] = $this->cleanName($hay);
        $allowed = $this->allowedAvitoGpuChip($hay);
        if ($allowed !== null) {
            $attrs['avito_code'] = $allowed;

            return $attrs;
        }
        if ($this->isSkippedAvitoGpu($hay)) {
            $attrs['avito_code'] = self::SKIP_GPU;

            return $attrs;
        }
        $attrs['avito_code'] = $this->gpuChip($hay);

        return $attrs;
    }

    public function isAllowedAvitoGpu(string $hay): bool
    {
        return $this->allowedAvitoGpuChip($hay) !== null;
    }

    /**
     * Не 40xx/50xx и не свежий AMD — помечаем SKIP, в объявления не берём.
     */
    public function isSkippedAvitoGpu(string $hay): bool
    {
        if ($this->allowedAvitoGpuChip($hay) !== null) {
            return false;
        }
        if ($this->isWorkstationGpu($hay)) {
            return true;
        }
        $c = $this->compactGpuHay($hay);
        if (preg_match('/rtx(20|30)\d{2}/', $c)) {
            return true;
        }
        if (preg_match('/gtx\d/', $c)) {
            return true;
        }
        if (preg_match('/rx[56]\d{3}/', $c)) {
            return true;
        }
        if (preg_match('/arc[ab]\d{3}/', $c)) {
            return true;
        }

        return false;
    }

    public function allowedAvitoGpuChip(string $hay): ?string
    {
        if ($this->isWorkstationGpu($hay)) {
            return null;
        }
        $c = $this->compactGpuHay($hay);
        if (preg_match('/rtx(40|50)(\d{2})(ti)?(super)?/', $c, $m)) {
            return $this->formatRtxChip($m[1].$m[2], ! empty($m[3]), ! empty($m[4]));
        }
        if (preg_match('/geforce(40|50)(\d{2})(ti)?(super)?/', $c, $m)) {
            return $this->formatRtxChip($m[1].$m[2], ! empty($m[3]), ! empty($m[4]));
        }
        if (preg_match('/rx([79]\d{3})(xtx|xt|gre)?/', $c, $m)) {
            $raw = 'RX '.strtoupper($m[1]);
            if (! empty($m[2])) {
                $raw .= ' '.strtoupper($m[2]);
            }

            return $raw;
        }
        if (preg_match('/(?<![0-9])(40|50)(\d{2})(ti)?(super)?(?![0-9])/', $c, $m)) {
            return $this->formatRtxChip($m[1].$m[2], ! empty($m[3]), ! empty($m[4]));
        }

        return null;
    }

    public function isWorkstationGpu(string $hay): bool
    {
        $c = $this->compactGpuHay($hay);

        return (bool) preg_match(
            '/quadro|tesla|\bnvs\b|rtxa\d{3,4}|l40s?|nvidial4|rtx[456]000|a100|h100|h200|a800|\ba40\b|t400|t600|t1000|t2000/',
            $c
        );
    }

    private function compactGpuHay(string $hay): string
    {
        $h = mb_strtolower($hay);
        $h = str_replace(['™', '®', '©'], ' ', $h);
        $h = preg_replace('/[^a-z0-9]+/u', '', $h) ?? $h;

        return $h;
    }

    private function formatRtxChip(string $num, bool $ti, bool $super): string
    {
        $raw = 'RTX '.$num;
        if ($ti) {
            $raw .= ' Ti';
        }
        if ($super) {
            $raw .= ' Super';
        }

        return $raw;
    }

    private function ram(string $hay, array $attrs): array
    {
        $gb = $attrs['ram_gb'] ?: 16;
        $attrs['avito_brand'] = $this->vendorBrand($hay, AvitoPcXmlDict::motherboardBrands());
        $attrs['avito_model'] = ($attrs['ddr'] ?: 'DDR4').' '.$gb.'GB';
        $attrs['avito_code'] = AvitoPcXmlDict::ramSizeForGb($gb);

        return $attrs;
    }

    private function motherboard(string $hay, string $vendor, array $attrs): array
    {
        $brandHay = $vendor !== '' ? $vendor : $this->firstWord($hay);
        $attrs['avito_brand'] = AvitoPcXmlDict::closest(
            AvitoPcXmlDict::motherboardBrands(),
            $brandHay,
            $brandHay !== '' ? $brandHay : 'Другой',
        );
        $chipsets = StoreComponentSpecs::dictionaries()['mb_chipset'];
        $chipset = AvitoPcXmlDict::closest($chipsets, $hay);
        $attrs['avito_model'] = $this->cleanName($hay);
        $attrs['avito_code'] = $chipset;

        if (! $attrs['socket'] && $chipset) {
            $attrs['socket'] = $this->socketFromChipset($chipset);
        }
        if (! $attrs['ddr']) {
            $attrs['ddr'] = $this->ddrFromChipset((string) $chipset);
        }

        return $attrs;
    }

    private function psu(string $hay, string $vendor, array $attrs): array
    {
        if (empty($attrs['wattage'])) {
            $attrs['wattage'] = $this->psuWattFromModel($hay);
        }
        $attrs['avito_brand'] = $vendor !== '' ? $vendor : $this->firstWord($hay);
        $attrs['avito_model'] = $attrs['wattage'] ? $attrs['wattage'].'W' : mb_substr($hay, 0, 80);
        $attrs['avito_code'] = $attrs['avito_model'];

        return $attrs;
    }

    private function named(string $hay, string $vendor, array $attrs): array
    {
        $attrs['avito_brand'] = $vendor !== '' ? $vendor : $this->firstWord($hay);
        $attrs['avito_model'] = mb_substr($hay, 0, 80);
        $attrs['avito_code'] = $attrs['avito_model'];

        return $attrs;
    }

    private function gpuMaker(string $hay, string $vendor): ?string
    {
        $makers = AvitoPcXmlDict::videocardBrands();
        usort($makers, fn ($a, $b) => mb_strlen((string) $b) <=> mb_strlen((string) $a));
        $blob = mb_strtolower($vendor.' '.$hay);
        foreach ($makers as $maker) {
            $maker = (string) $maker;
            if (in_array(mb_strtolower($maker), ['nvidia', 'amd', 'intel'], true)) {
                continue;
            }
            if ($maker !== '' && str_contains($blob, mb_strtolower($maker))) {
                return $maker;
            }
        }
        if ($vendor !== '') {
            return $vendor;
        }

        return $this->firstWord($this->cleanName($hay)) ?: null;
    }

    private function gpuChip(string $hay): ?string
    {
        return $this->allowedAvitoGpuChip($hay) ?: $this->legacyGpuChip($hay);
    }

    private function legacyGpuChip(string $hay): ?string
    {
        if (preg_match('/(?:rtx|geforce)\s*(\d{4})\s*(ti)?\s*(super)?/iu', $hay, $m)) {
            $raw = 'RTX '.$m[1];
            if (! empty($m[2])) {
                $raw .= ' Ti';
            }
            if (! empty($m[3])) {
                $raw .= ' Super';
            }

            return $raw;
        }
        if (preg_match('/gtx\s*(\d{3,4}\s*(?:super|ti)?)/iu', $hay, $m)) {
            return 'GTX '.trim(preg_replace('/\s+/', ' ', $m[1]));
        }
        if (preg_match('/rx\s*(\d{3,4})\s*(xtx|xt|gre)?/iu', $hay, $m)) {
            $raw = 'RX '.strtoupper($m[1]);
            if (! empty($m[2])) {
                $raw .= ' '.strtoupper($m[2]);
            }

            return $raw;
        }
        if (preg_match('/arc\s*([ab]\d{3})/iu', $hay, $m)) {
            return 'Arc '.strtoupper($m[1]);
        }

        return null;
    }

    private function cleanName(string $hay): string
    {
        $hay = trim($hay);
        $hay = preg_replace('/^(видеокарта|процессор|материнская\s+плата|оперативная\s+память)\s+/iu', '', $hay) ?? $hay;

        return mb_substr(trim($hay), 0, 180);
    }

    private function processorModel(string $hay): ?string
    {
        if (preg_match('/core\s*ultra\s*([579])/iu', $hay, $m)) {
            return 'Core Ultra '.$m[1];
        }
        if (preg_match('/core\s*i\s*([3579])/iu', $hay, $m)) {
            return 'Core i'.$m[1];
        }
        if (preg_match('/ryzen\s*(threadripper|\s*9|\s*7|\s*5|\s*3)/iu', $hay, $m)) {
            $n = strtolower(trim($m[1]));
            if ($n === 'threadripper') {
                return 'Ryzen Threadripper';
            }

            return 'Ryzen '.$n;
        }
        if (preg_match('/\bpentium\b/iu', $hay)) {
            return 'Pentium';
        }
        if (preg_match('/\bceleron\b/iu', $hay)) {
            return 'Celeron';
        }

        return null;
    }

    private function processorCode(string $hay): ?string
    {
        $codes = AvitoPcXmlDict::processorCodes();
        usort($codes, fn ($a, $b) => mb_strlen((string) $b) <=> mb_strlen((string) $a));
        $compactHay = ' '.mb_strtolower($hay).' ';
        foreach ($codes as $code) {
            $code = (string) $code;
            if ($code !== '' && str_contains($compactHay, mb_strtolower($code))) {
                return $code;
            }
        }
        if (preg_match('/\b(\d{4,5}[a-z]{0,3})\b/iu', $hay, $m)) {
            return $m[1];
        }

        return null;
    }

    private function socket(string $hay): ?string
    {
        if (preg_match('/\b(lga\s*1851|1851)\b/iu', $hay)) {
            return 'LGA1851';
        }
        if (preg_match('/\b(lga\s*1700|1700)\b/iu', $hay)) {
            return 'LGA1700';
        }
        if (preg_match('/\b(lga\s*1200|1200)\b/iu', $hay)) {
            return 'LGA1200';
        }
        if (preg_match('/\bam5\b/iu', $hay)) {
            return 'AM5';
        }
        if (preg_match('/\bam4\b/iu', $hay)) {
            return 'AM4';
        }

        return null;
    }

    private function ddr(string $hay): ?string
    {
        if (preg_match('/ddr\s*5/iu', $hay)) {
            return 'DDR5';
        }
        if (preg_match('/ddr\s*4/iu', $hay)) {
            return 'DDR4';
        }

        return null;
    }

    private function ramGb(string $hay): ?int
    {
        if (preg_match('/(\d)\s*[xх]\s*(\d{1,2})\s*(gb|гб)/iu', $hay, $m)) {
            return (int) $m[1] * (int) $m[2];
        }
        if (preg_match('/\b(\d{1,3})\s*(gb|гб)\b/iu', $hay, $m)) {
            $n = (int) $m[1];
            if (in_array($n, [4, 8, 16, 32, 48, 64, 96, 128], true)) {
                return $n;
            }
        }

        return null;
    }

    private const PSU_WATTS = [
        300, 350, 400, 450, 500, 550, 600, 650, 700, 750, 800, 850, 900,
        1000, 1050, 1200, 1300, 1600, 2000,
    ];

    private function wattage(string $hay): ?int
    {
        if (preg_match('/(\d{3,4})\s*(w|вт|watt|ватт)\b/iu', $hay, $m)) {
            $n = (int) $m[1];
            if ($n >= 300 && $n <= 2000) {
                return $n;
            }
        }

        return null;
    }

    private function psuWattFromModel(string $hay): ?int
    {
        if (! preg_match_all('/\d{3,4}/u', $hay, $m)) {
            return null;
        }
        foreach ($m[0] as $raw) {
            $n = (int) $raw;
            if (in_array($n, self::PSU_WATTS, true)) {
                return $n;
            }
        }

        return null;
    }

    private function form(string $hay): ?string
    {
        if (preg_match('/e-?atx/iu', $hay)) {
            return 'eatx';
        }
        if (preg_match('/mini-?itx|\bitx\b/iu', $hay)) {
            return 'itx';
        }
        if (preg_match('/micro-?atx|m-?atx|matx/iu', $hay)) {
            return 'matx';
        }
        if (preg_match('/\batx\b/iu', $hay)) {
            return 'atx';
        }

        return null;
    }

    private function inferCpuSocket(?string $code, ?string $brand): ?string
    {
        $code = strtoupper((string) $code);
        if ($brand === 'AMD') {
            if (preg_match('/^(7|8|9)\d{3}/', $code)) {
                return 'AM5';
            }
            if (preg_match('/^(5|4|3)\d{3}/', $code)) {
                return 'AM4';
            }
        }
        if ($brand === 'Intel') {
            if (preg_match('/^(2[2-8]5)/', $code)) {
                return 'LGA1851';
            }
            if (preg_match('/^(12|13|14)\d{3}/', $code)) {
                return 'LGA1700';
            }
        }

        return null;
    }

    private function socketFromChipset(string $chipset): ?string
    {
        $c = strtoupper($chipset);
        if (preg_match('/^(B650|B650E|X670|X670E|A620|B850|X870)/', $c)) {
            return 'AM5';
        }
        if (preg_match('/^(B550|B450|X570|A520)/', $c)) {
            return 'AM4';
        }
        if (preg_match('/^(Z890|B860|H810)/', $c)) {
            return 'LGA1851';
        }
        if (preg_match('/^(Z790|B760|H610|B660|Z690)/', $c)) {
            return 'LGA1700';
        }

        return null;
    }

    private function ddrFromChipset(?string $chipset): ?string
    {
        $c = strtoupper((string) $chipset);
        if ($c === '') {
            return null;
        }
        if (preg_match('/^(B650|B650E|X670|X670E|A620|B850|X870|Z890|B860|H810|B760|Z790)/', $c)) {
            return 'DDR5';
        }
        if (preg_match('/^(B550|B450|X570|A520|H610|B660|Z690)/', $c)) {
            return 'DDR4';
        }

        return null;
    }

    private function vendorBrand(string $hay, array $allowed): ?string
    {
        return AvitoPcXmlDict::closest($allowed, $this->firstWord($hay));
    }

    private function firstWord(string $hay): string
    {
        if (preg_match('/^[^\s,\/]+/u', trim($hay), $m)) {
            return $m[0];
        }

        return '';
    }
}
