<?php

namespace App\Services\StoreAvito;

use App\Models\StoreAvitoAd;
use App\Models\StoreAvitoProductAttr;
use App\Models\StoreAvitoSetting;
use App\Models\StoreSupplierCatalogProduct;
use App\Support\AvitoPcXmlDict;
use Illuminate\Support\Collection;

class StoreAvitoBuildComposer
{
    public function __construct(
        private readonly StoreAvitoDictMatcher $matcher,
    ) {}
    /**
     * @return list<array{
     *   fingerprint: string,
     *   components: list<array<string, mixed>>,
     *   xml: array<string, string>
     * }>
     */
    public function compose(int $count, ?StoreAvitoSetting $settings = null): array
    {
        $settings ??= StoreAvitoSetting::current();
        $used = StoreAvitoAd::query()->pluck('fingerprint')->all();
        $used = array_fill_keys($used, true);

        $cpus = $this->pool('cpu');
        $boards = $this->pool('motherboard');
        $rams = $this->pool('ram');
        $gpus = $this->pool('gpu');
        $ssds = $this->pool('ssd');
        $psus = $this->pool('psu');
        $coolers = $this->pool('cooler');
        $cases = $this->pool('case');

        if ($cpus->isEmpty() || $boards->isEmpty() || $rams->isEmpty() || $ssds->isEmpty()) {
            return [];
        }

        $needGpu = ($settings->pc_type ?: 'Игровой') !== 'Офисный';
        $out = [];
        $attempts = 0;
        $maxAttempts = max(40, $count * 40);

        while (count($out) < $count && $attempts < $maxAttempts) {
            $attempts++;
            $cpu = $cpus->random();
            $board = $this->compatibleBoard($boards, $cpu) ?? $boards->random();
            $ram = $this->compatibleRam($rams, $board) ?? $rams->random();
            $gpu = $gpus->isNotEmpty() ? $gpus->random() : null;
            if ($needGpu && ! $gpu) {
                continue;
            }
            $ssd = $ssds->random();
            $psu = $psus->isNotEmpty() ? $psus->random() : null;
            $cooler = $coolers->isNotEmpty() ? $coolers->random() : null;
            $case = $cases->isNotEmpty() ? $cases->random() : null;

            $parts = array_values(array_filter([$cpu, $board, $ram, $gpu, $ssd, $psu, $cooler, $case]));
            $fingerprint = $this->fingerprint($parts);
            if (isset($used[$fingerprint])) {
                continue;
            }
            $used[$fingerprint] = true;

            $xml = $this->xmlFrom($settings, $cpu, $board, $ram, $gpu);
            $out[] = [
                'fingerprint' => $fingerprint,
                'components' => $parts,
                'xml' => $xml,
            ];
        }

        return $out;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function pool(string $type): Collection
    {
        $rows = StoreAvitoProductAttr::query()
            ->where('type', $type)
            ->with('product')
            ->get();

        return $rows->map(function (StoreAvitoProductAttr $attr) {
            $p = $attr->product;
            if (! $p instanceof StoreSupplierCatalogProduct) {
                return null;
            }
            $price = (float) ($p->price ?: $p->rrp ?: 0);
            if ($price <= 0) {
                return null;
            }
            if ($p->stock_qty !== null && (int) $p->stock_qty <= 0) {
                return null;
            }

            return [
                'type' => $attr->type,
                'sku' => (int) $attr->sku,
                'name' => (string) $p->name,
                'part' => (string) ($p->part ?? ''),
                'purchase' => $price,
                'socket' => $attr->socket,
                'ddr' => $attr->ddr,
                'ram_gb' => $attr->ram_gb,
                'wattage' => $attr->wattage,
                'avito_brand' => $attr->avito_brand,
                'avito_model' => $attr->avito_model,
                'avito_code' => $attr->avito_code,
                'vendor' => (string) ($p->vendor ?? ''),
                'has_image' => (bool) $p->has_image,
            ];
        })->filter()->values();
    }

    private function compatibleBoard(Collection $boards, array $cpu): ?array
    {
        $socket = $cpu['socket'] ?? null;
        if (! $socket) {
            return null;
        }
        $match = $boards->filter(fn ($b) => ($b['socket'] ?? null) === $socket);
        if ($match->isEmpty()) {
            return null;
        }

        return $match->random();
    }

    private function compatibleRam(Collection $rams, array $board): ?array
    {
        $ddr = $board['ddr'] ?? null;
        $match = $rams;
        if ($ddr) {
            $filtered = $rams->filter(fn ($r) => ($r['ddr'] ?? null) === $ddr);
            if ($filtered->isNotEmpty()) {
                $match = $filtered;
            }
        }
        if ($match->isEmpty()) {
            return null;
        }

        return $match->random();
    }

    /**
     * @param  list<array<string, mixed>>  $parts
     */
    private function fingerprint(array $parts): string
    {
        $skus = array_map(fn ($p) => (int) $p['sku'], $parts);
        sort($skus);

        return sha1(implode('-', $skus));
    }

    private function xmlFrom(StoreAvitoSetting $settings, array $cpu, array $board, array $ram, ?array $gpu): array
    {
        $ramGb = (int) ($ram['ram_gb'] ?? 16);
        $cpuHay = $this->hay($cpu);
        $boardHay = $this->hay($board);
        $cpuBrand = $this->matcher->match('BrandProcessor', $cpuHay) ?: (string) ($cpu['avito_brand'] ?: 'Intel');
        $cpuModel = $this->matcher->match('ModelProcessor', $cpuHay, $cpuBrand) ?: (string) ($cpu['avito_model'] ?: 'Core i5');
        $cpuCode = $this->matcher->match('CodeProcessor', $cpuHay, $cpuModel) ?: (string) ($cpu['avito_code'] ?: '');

        $mbBrand = $this->matcher->match('BrandMotherboard', $boardHay) ?: (string) ($board['avito_brand'] ?: 'Другой');
        $mbModel = $this->matcher->match('ModelMotherboard', $boardHay, $mbBrand)
            ?: (string) ($board['avito_model'] ?: $board['name']);

        $xml = [
            'Category' => AvitoPcXmlDict::CATEGORY,
            'GoodsSubType' => $this->matcher->match('GoodsSubType', AvitoPcXmlDict::GOODS_SUB_TYPE) ?: AvitoPcXmlDict::GOODS_SUB_TYPE,
            'AdType' => $this->matcher->match('AdType', AvitoPcXmlDict::AD_TYPE) ?: AvitoPcXmlDict::AD_TYPE,
            'Condition' => $this->matcher->match('Condition', AvitoPcXmlDict::CONDITION) ?: AvitoPcXmlDict::CONDITION,
            'Brand' => $this->matcher->match('Brand', AvitoPcXmlDict::BRAND) ?: AvitoPcXmlDict::BRAND,
            'Type' => $this->matcher->match('Type', (string) $settings->pc_type) ?: (AvitoPcXmlDict::closest(AvitoPcXmlDict::pcTypes(), $settings->pc_type, 'Игровой') ?: 'Игровой'),
            'BrandProcessor' => $cpuBrand,
            'ModelProcessor' => $cpuModel,
            'CodeProcessor' => $cpuCode,
            'BrandMotherboard' => $mbBrand,
            'ModelMotherboard' => $mbModel,
            'RamSize' => $this->matcher->match('RamSize', $ramGb.' ГБ') ?: AvitoPcXmlDict::ramSizeForGb($ramGb),
        ];

        if ($gpu) {
            $gpuHay = $this->hay($gpu);
            $gpuBrand = $this->matcher->match('BrandVideocard', $gpuHay) ?: (string) ($gpu['avito_brand'] ?? '');
            $gpuModel = $this->matcher->match('ModelVideocard', $gpuHay, $gpuBrand)
                ?: (string) ($gpu['avito_model'] ?? $gpu['name'] ?? '');
            $gpuCode = $this->matcher->match('CodeVideocard', $gpuHay, $gpuModel);
            if ($gpuBrand !== '') {
                $xml['BrandVideocard'] = $gpuBrand;
            }
            if ($gpuModel !== '') {
                $xml['ModelVideocard'] = $gpuModel;
            }
            if ($gpuCode) {
                $xml['CodeVideocard'] = $gpuCode;
            }
        }

        return array_filter($xml, fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @param  array<string, mixed>  $part
     */
    private function hay(array $part): string
    {
        return trim(implode(' ', array_filter([
            $part['name'] ?? null,
            $part['part'] ?? null,
            $part['vendor'] ?? null,
        ], fn ($v) => is_string($v) && trim($v) !== '')));
    }
}
