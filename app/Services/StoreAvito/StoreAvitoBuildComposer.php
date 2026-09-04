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

        return [
            'Category' => AvitoPcXmlDict::CATEGORY,
            'GoodsSubType' => AvitoPcXmlDict::GOODS_SUB_TYPE,
            'AdType' => AvitoPcXmlDict::AD_TYPE,
            'Condition' => AvitoPcXmlDict::CONDITION,
            'Brand' => AvitoPcXmlDict::BRAND,
            'Type' => AvitoPcXmlDict::closest(AvitoPcXmlDict::pcTypes(), $settings->pc_type, 'Игровой') ?: 'Игровой',
            'BrandProcessor' => (string) ($cpu['avito_brand'] ?: 'Intel'),
            'ModelProcessor' => (string) ($cpu['avito_model'] ?: 'Core i5'),
            'CodeProcessor' => (string) ($cpu['avito_code'] ?: '12400F'),
            'BrandMotherboard' => (string) ($board['avito_brand'] ?: 'Другой'),
            'ModelMotherboard' => (string) ($board['avito_model'] ?: $board['name']),
            'BrandVideocard' => (string) ($gpu['avito_brand'] ?? 'NVIDIA'),
            'ModelVideocard' => (string) ($gpu['avito_model'] ?? 'GeForce RTX 4060'),
            'CodeVideocard' => (string) ($gpu['avito_code'] ?? 'RTX 4060'),
            'RamSize' => AvitoPcXmlDict::ramSizeForGb($ramGb),
        ];
    }
}
