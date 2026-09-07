<?php

namespace App\Services\StoreAvito;

use App\Models\StoreAvitoAd;
use App\Models\StoreAvitoConfig;
use App\Models\StoreAvitoPart;
use App\Models\StoreAvitoProductAttr;
use App\Models\StoreAvitoSetting;
use App\Models\StoreSupplierCatalogProduct;
use App\Support\AvitoPcXmlDict;
use Illuminate\Support\Collection;

class StoreAvitoBuildComposer
{
    /** @var list<string> */
    private array $lastFailures = [];

    public function __construct(
        private readonly StoreAvitoDictMatcher $matcher,
        private readonly StoreAvitoCatalogAttrParser $parser,
    ) {}

    /**
     * @return list<string>
     */
    public function lastFailures(): array
    {
        return $this->lastFailures;
    }

    /**
     * @return list<array{
     *   fingerprint: string,
     *   components: list<array<string, mixed>>,
     *   xml: array<string, string>,
     *   store_avito_config_id?: int
     * }>
     */
    public function compose(int $count, ?StoreAvitoSetting $settings = null): array
    {
        $this->lastFailures = [];
        $settings ??= StoreAvitoSetting::current();
        $templates = StoreAvitoConfig::query()
            ->enabled()
            ->with(['cpu', 'gpu', 'mb', 'ram', 'ssd', 'psu'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->values();

        if ($templates->isEmpty()) {
            $this->lastFailures[] = 'Нет включённых конфигураций. Соберите шаблоны на вкладке «Конфигурации» — случайные сборки из каталога отключены.';

            return [];
        }

        $out = $this->composeFromTemplates($templates, $count, $settings);
        if ($out === [] && $this->lastFailures === []) {
            $this->lastFailures[] = 'Не удалось подобрать живые SKU из каталога под включённые конфигурации.';
        }

        return $out;
    }

    /**
     * @param  Collection<int, StoreAvitoConfig>  $templates
     * @return list<array<string, mixed>>
     */
    private function composeFromTemplates(Collection $templates, int $count, StoreAvitoSetting $settings): array
    {
        $used = array_fill_keys(StoreAvitoAd::query()->pluck('fingerprint')->all(), true);
        $pools = $this->pools();
        if ($pools['motherboard']->isEmpty() || $pools['ram']->isEmpty() || $pools['ssd']->isEmpty()) {
            $this->lastFailures[] = 'В каталоге нет размеченных плат/ОЗУ/SSD в наличии — не из чего собрать шаблон.';

            return [];
        }

        $needGpu = ($settings->pc_type ?: 'Игровой') !== 'Офисный';
        $ids = $templates->pluck('id')->values()->all();
        $start = 0;
        $lastId = (int) $settings->last_config_id;
        if ($lastId > 0) {
            $idx = array_search($lastId, $ids, true);
            if ($idx !== false) {
                $start = ($idx + 1) % count($ids);
            }
        }

        $out = [];
        $lastUsed = $lastId;
        $i = 0;
        $attempts = 0;
        $maxAttempts = max(count($ids) * 6, $count * 12);

        while (count($out) < $count && $attempts < $maxAttempts) {
            $attempts++;
            /** @var StoreAvitoConfig $tpl */
            $tpl = $templates[($start + $i) % count($ids)];
            $i++;
            $build = $this->instantiate($tpl, $settings, $pools, $used, $needGpu);
            if (! $build) {
                continue;
            }
            $used[$build['fingerprint']] = true;
            $out[] = $build;
            $lastUsed = (int) $tpl->id;
            $tpl->forceFill([
                'use_count' => (int) $tpl->use_count + 1,
                'last_used_at' => now(),
            ])->save();
        }

        if ($lastUsed > 0) {
            $settings->forceFill(['last_config_id' => $lastUsed])->save();
        }

        $this->lastFailures = array_values(array_unique($this->lastFailures));

        return $out;
    }

    /**
     * @param  array<string, Collection<int, array<string, mixed>>>  $pools
     * @param  array<string, true>  $used
     * @return array{fingerprint:string, components:list<array<string,mixed>>, xml:array<string,string>, store_avito_config_id:int}|null
     */
    private function instantiate(StoreAvitoConfig $tpl, StoreAvitoSetting $settings, array $pools, array $used, bool $needGpu): ?array
    {
        $cpus = $this->matchCpu($pools['cpu'], $tpl);
        $rams = $this->matchRam($pools['ram'], $tpl);
        $ssds = $this->matchSsd($pools['ssd'], $tpl);
        $psus = $this->matchPsu($pools['psu'], $tpl);
        $gpus = $this->matchGpu($pools['gpu'], $tpl);
        $label = '№'.$tpl->sort_order;
        if ($cpus->isEmpty()) {
            $this->lastFailures[] = $label.': в каталоге нет процессора '.($tpl->cpu?->avito_code ?: $tpl->cpu?->label ?: 'из шаблона');

            return null;
        }
        if ($rams->isEmpty()) {
            $this->lastFailures[] = $label.': в каталоге нет ОЗУ '.($tpl->ram?->label ?: '');

            return null;
        }
        if ($ssds->isEmpty()) {
            $this->lastFailures[] = $label.': в каталоге нет SSD '.((int) ($tpl->ssd?->capacity_gb ?? 0) ?: ($tpl->ssd?->label ?: ''));

            return null;
        }
        if ($needGpu && $gpus->isEmpty()) {
            $this->lastFailures[] = $label.': в каталоге нет видеокарты '.($tpl->gpu?->avito_code ?: $tpl->gpu?->label ?: '');

            return null;
        }
        if ($psus->isEmpty() && (int) ($tpl->psu?->wattage ?? 0) > 0) {
            $this->lastFailures[] = $label.': в каталоге нет БП ~'.(int) $tpl->psu->wattage.' Вт';

            return null;
        }

        $chipset = strtoupper(trim((string) ($tpl->mb?->avito_code ?? '')));
        $boards = $this->matchMotherboard($pools['motherboard'], $tpl);
        if ($chipset !== '' && $boards->isEmpty()) {
            $this->lastFailures[] = $label.': в каталоге нет платы '.$chipset;

            return null;
        }
        $boardPool = $chipset !== '' ? $boards : $pools['motherboard'];

        $tries = 0;
        while ($tries < 24) {
            $tries++;
            $cpu = $cpus->random();
            $ram = $rams->random();
            $board = $this->compatibleBoardFor($boardPool, $cpu, $ram);
            if (! $board) {
                continue;
            }
            $gpu = $gpus->isNotEmpty() ? $gpus->random() : null;
            if ($needGpu && ! $gpu) {
                continue;
            }
            $ssd = $ssds->random();
            $psu = $psus->isNotEmpty() ? $psus->random() : null;
            $parts = array_values(array_filter(
                [$cpu, $board, $ram, $gpu, $ssd, $psu],
                fn ($p) => is_array($p) && in_array($p['type'] ?? '', ['cpu', 'motherboard', 'ram', 'gpu', 'ssd', 'psu'], true)
            ));
            $fingerprint = $this->fingerprint($parts);
            if (isset($used[$fingerprint])) {
                continue;
            }

            return [
                'fingerprint' => $fingerprint,
                'components' => $parts,
                'xml' => $this->xmlFrom($settings, $cpu, $board, $ram, $gpu),
                'store_avito_config_id' => (int) $tpl->id,
            ];
        }

        $this->lastFailures[] = $label.': нет уникальной сборки (плата '.($chipset !== '' ? $chipset : $tpl->socket).' '.$tpl->ddr.' или повтор SKU)';

        return null;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $cpus
     * @return Collection<int, array<string, mixed>>
     */
    private function matchCpu(Collection $cpus, StoreAvitoConfig $tpl): Collection
    {
        return $this->matchByStandard($cpus, $tpl->cpu, 'cpu')->filter(function (array $c) {
            $hay = mb_strtolower(trim(($c['name'] ?? '').' '.($c['part'] ?? '')));

            return $hay !== '' && ! preg_match('/epyc|threadripper|xeon|для ноут|ноутбук|laptop/iu', $hay);
        })->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $pool
     * @return Collection<int, array<string, mixed>>
     */
    private function matchByStandard(Collection $pool, ?StoreAvitoPart $part, string $kind): Collection
    {
        $want = $this->templateStandard($part, $kind);
        if ($want === '') {
            return $pool;
        }

        return $pool->filter(fn (array $row) => $this->standardEquals(
            $want,
            $this->rowStandard($row, $kind),
            $kind,
        ))->values();
    }

    private function templateStandard(?StoreAvitoPart $part, string $kind): string
    {
        if ($part === null) {
            return '';
        }

        return match ($kind) {
            'gpu' => (string) ($this->parser->gpuStandard($part->avito_code) ?? ''),
            'ram' => (string) ($this->parser->ramStandard($part->ddr, $part->ram_gb) ?? ''),
            'ssd' => (string) ($this->parser->parseSsdStandard((string) (int) $part->capacity_gb) ?? ''),
            'psu' => (int) $part->wattage > 0 ? (string) (int) $part->wattage : '',
            default => trim((string) $part->avito_code),
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowStandard(array $row, string $kind): string
    {
        $s = trim((string) ($row['standard'] ?? ''));
        if ($kind === 'ram') {
            $parsed = $this->parser->parseRamStandard($s)
                ?? $this->parser->parseRamStandard(trim((string) ($row['ddr'] ?? '').' '.(string) ($row['ram_gb'] ?? '')));

            return $parsed['standard'] ?? '';
        }
        if ($kind === 'ssd') {
            return $this->parser->parseSsdStandard($s)
                ?? $this->parser->parseSsdStandard((string) ($row['ram_gb'] ?? ''))
                ?? $this->parser->parseSsdStandard(trim((string) ($row['name'] ?? '').' '.(string) ($row['part'] ?? '')))
                ?? '';
        }
        if ($kind === 'gpu') {
            return $this->parser->gpuStandard($s)
                ?: $this->parser->gpuStandard((string) ($row['avito_code'] ?? ''))
                ?: '';
        }
        if ($s !== '' && strcasecmp($s, StoreAvitoCatalogAttrParser::SKIP_GPU) !== 0) {
            return $s;
        }

        return match ($kind) {
            'cpu', 'motherboard' => trim((string) ($row['avito_code'] ?? '')),
            'psu' => (int) ($row['wattage'] ?? 0) > 0 ? (string) (int) $row['wattage'] : '',
            default => trim((string) ($row['avito_code'] ?? '')),
        };
    }

    private function standardEquals(string $want, string $got, string $kind): bool
    {
        if ($want === '' || $got === '' || strcasecmp($got, StoreAvitoCatalogAttrParser::SKIP_GPU) === 0) {
            return false;
        }
        if ($kind === 'gpu') {
            $a = $this->parser->gpuStandard($want);
            $b = $this->parser->gpuStandard($got);

            return $a !== null && $a === $b;
        }
        if ($kind === 'motherboard') {
            return $this->chipsetTokenEquals($got, $want);
        }
        if ($kind === 'ram') {
            $a = $this->parser->parseRamStandard($want);
            $b = $this->parser->parseRamStandard($got);

            return $a !== null && $b !== null && $a['standard'] === $b['standard'];
        }
        if ($kind === 'ssd') {
            $a = $this->parser->parseSsdStandard($want);
            $b = $this->parser->parseSsdStandard($got);

            return $a !== null && $b !== null && $a === $b;
        }
        if ($kind === 'psu') {
            $a = (int) $this->digitsFrom($want);
            $b = (int) $this->digitsFrom($got);

            return $a > 0 && $a === $b;
        }

        return $this->normToken($want) === $this->normToken($got);
    }

    private function normToken(string $s): string
    {
        return mb_strtolower(preg_replace('/\s+/u', '', trim($s)) ?? $s);
    }

    private function digitsFrom(string $s): string
    {
        return preg_match('/(\d+)/', $s, $m) ? $m[1] : '';
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $gpus
     * @return Collection<int, array<string, mixed>>
     */
    private function matchGpu(Collection $gpus, StoreAvitoConfig $tpl): Collection
    {
        return $this->matchByStandard(
            $gpus->filter(fn (array $g) => $this->isAllowedGpuRow($g))->values(),
            $tpl->gpu,
            'gpu',
        );
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $boards
     * @return Collection<int, array<string, mixed>>
     */
    private function matchMotherboard(Collection $boards, StoreAvitoConfig $tpl): Collection
    {
        $part = $tpl->mb;
        $socket = $part?->socket ?: $tpl->cpu?->socket ?: $tpl->socket;
        $match = $this->matchByStandard($boards, $part, 'motherboard');
        if ($socket) {
            $match = $match->filter(fn (array $b) => empty($b['socket']) || $b['socket'] === $socket);
        }

        return $match->values();
    }

    /**
     * B650 совпадает с B650 / B650M / B650I, но не с B650E.
     */
    private function chipsetTokenEquals(string $token, string $wanted): bool
    {
        $token = strtoupper(preg_replace('/[^A-Z0-9]/', '', $token) ?? '');
        $wanted = strtoupper(preg_replace('/[^A-Z0-9]/', '', $wanted) ?? '');
        if (preg_match('/^([A-Z]\d{3}E?)[MI]?$/', $token, $m)) {
            $token = $m[1];
        }
        if (preg_match('/^([A-Z]\d{3}E?)[MI]?$/', $wanted, $m)) {
            $wanted = $m[1];
        }

        return $token === $wanted;
    }

    /**
     * type=gpu + standard канона. Имя проверяем только чтобы отсечь хлам (A400, барабан, 3060).
     *
     * @param  array<string, mixed>  $gpu
     */
    private function isAllowedGpuRow(array $gpu): bool
    {
        if ($this->isRejectedGpuRow($gpu)) {
            return false;
        }
        $std = $this->rowStandard($gpu, 'gpu');

        return $std !== '';
    }

    /**
     * @param  array<string, mixed>  $gpu
     */
    private function isRejectedGpuRow(array $gpu): bool
    {
        $hay = trim(($gpu['name'] ?? '').' '.($gpu['part'] ?? ''));

        return $this->parser->isJunkAvitoGpu($hay)
            || $this->parser->isWorkstationGpu($hay)
            || ($this->parser->isSkippedAvitoGpu($hay) && ! $this->parser->isAllowedAvitoGpu($hay));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rams
     * @return Collection<int, array<string, mixed>>
     */
    private function matchRam(Collection $rams, StoreAvitoConfig $tpl): Collection
    {
        return $this->matchByStandard($rams, $tpl->ram, 'ram');
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $ssds
     * @return Collection<int, array<string, mixed>>
     */
    private function matchSsd(Collection $ssds, StoreAvitoConfig $tpl): Collection
    {
        return $this->matchByStandard($ssds, $tpl->ssd, 'ssd');
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $psus
     * @return Collection<int, array<string, mixed>>
     */
    private function matchPsu(Collection $psus, StoreAvitoConfig $tpl): Collection
    {
        return $this->matchByStandard($psus, $tpl->psu, 'psu');
    }

    private function compatibleBoardFor(Collection $boards, array $cpu, array $ram): ?array
    {
        $socket = $cpu['socket'] ?? null;
        $ddr = $ram['ddr'] ?? null;
        $match = $boards;
        if ($socket) {
            $match = $match->filter(fn ($b) => ($b['socket'] ?? null) === $socket);
        }
        if ($ddr && $match->isNotEmpty()) {
            $withDdr = $match->filter(fn ($b) => ($b['ddr'] ?? null) === $ddr);
            if ($withDdr->isNotEmpty()) {
                $match = $withDdr;
            }
        }
        if ($match->isEmpty()) {
            return null;
        }

        return $match->random();
    }

    /**
     * @return array<string, Collection<int, array<string, mixed>>>
     */
    private function pools(): array
    {
        return [
            'cpu' => $this->pool('cpu'),
            'motherboard' => $this->pool('motherboard'),
            'ram' => $this->pool('ram'),
            'gpu' => $this->pool('gpu'),
            'ssd' => $this->pool('ssd'),
            'psu' => $this->pool('psu'),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function pool(string $type): Collection
    {
        $types = match ($type) {
            'motherboard' => ['motherboard', 'mb'],
            'ssd' => ['ssd', 'storage_ssd'],
            default => [$type],
        };
        $rows = StoreAvitoProductAttr::query()
            ->whereIn('type', $types)
            ->with('product')
            ->get();

        return $rows->map(function (StoreAvitoProductAttr $attr) use ($type) {
            $p = $attr->product;
            if (! $p instanceof StoreSupplierCatalogProduct) {
                return null;
            }
            $price = (float) ($p->price ?: $p->rrp ?: 0);
            if ($price <= 0) {
                return null;
            }
            $hay = (string) $p->name.' '.(string) ($p->part ?? '');
            $parsed = $this->parser->parse(
                $type,
                (string) $p->name,
                (string) ($p->part ?? ''),
                (string) ($p->vendor ?? ''),
            );
            $socket = $attr->socket ?: ($parsed['socket'] ?? null);
            $ddr = $attr->ddr ?: ($parsed['ddr'] ?? null);
            $ramGb = (int) ($attr->ram_gb ?: ($parsed['ram_gb'] ?? 0));
            $wattage = (int) ($attr->wattage ?: ($parsed['wattage'] ?? 0));
            $avitoBrand = $attr->avito_brand ?: ($parsed['avito_brand'] ?? null);
            $avitoModel = $attr->avito_model ?: ($parsed['avito_model'] ?? null);
            $standard = trim((string) ($attr->standard ?: ''));
            $avitoCode = (string) ($attr->avito_code ?: '');
            // GPU: выборка только по type+standard из attrs. Имя каталога не парсим.
            if ($type !== 'gpu') {
                if ($avitoCode === '') {
                    $fromParse = (string) ($parsed['avito_code'] ?? '');
                    if ($fromParse !== '' && strcasecmp($fromParse, StoreAvitoCatalogAttrParser::SKIP_GPU) !== 0) {
                        $avitoCode = $fromParse;
                    }
                }
                if ($standard === '' || strcasecmp($standard, StoreAvitoCatalogAttrParser::SKIP_GPU) === 0) {
                    $standard = (string) ($this->parser->deriveStandard([
                        'type' => $type,
                        'avito_code' => $avitoCode,
                        'ddr' => $ddr,
                        'ram_gb' => $ramGb ?: null,
                        'wattage' => $wattage ?: null,
                    ]) ?? '');
                }
                if ($avitoCode === '' && $standard !== '') {
                    $avitoCode = $standard;
                }
            }
            $row = [
                'type' => $type,
                'sku' => (int) $attr->sku,
                'name' => (string) $p->name,
                'part' => (string) ($p->part ?? ''),
                'purchase' => $price,
                'socket' => $socket,
                'ddr' => $ddr,
                'ram_gb' => $ramGb ?: null,
                'wattage' => $wattage ?: null,
                'standard' => $standard !== '' ? $standard : null,
                'avito_brand' => $avitoBrand,
                'avito_model' => $avitoModel,
                'avito_code' => $avitoCode,
                'vendor' => (string) ($p->vendor ?? ''),
                'has_image' => (bool) $p->has_image,
            ];
            if ($type === 'gpu' && ! $this->isAllowedGpuRow($row)) {
                return null;
            }
            if ($type === 'cpu' && preg_match('/epyc|threadripper|xeon|для ноут|ноутбук|laptop/iu', $hay)) {
                return null;
            }

            return $row;
        })->filter()->values();
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
        $cpuBrand = $this->matcher->match('BrandProcessor', $cpuHay) ?: (string) ($cpu['avito_brand'] ?? '');
        $cpuModel = $this->matcher->match('ModelProcessor', $cpuHay, $cpuBrand) ?: (string) ($cpu['avito_model'] ?? '');
        $cpuCode = (string) ($cpu['standard'] ?? $cpu['avito_code'] ?? '')
            ?: $this->matcher->match('CodeProcessor', $cpuHay, $cpuModel)
            ?: '';

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
            $gpuCode = $this->parser->gpuStandardPretty((string) ($gpu['standard'] ?? ''))
                ?: $this->parser->gpuStandardPretty((string) ($gpu['avito_code'] ?? ''))
                ?: '';
            if ($gpuBrand !== '') {
                $xml['BrandVideocard'] = $gpuBrand;
            }
            if ($gpuModel !== '') {
                $xml['ModelVideocard'] = $gpuModel;
            }
            if ($gpuCode !== '' && $gpuCode !== StoreAvitoCatalogAttrParser::SKIP_GPU) {
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
