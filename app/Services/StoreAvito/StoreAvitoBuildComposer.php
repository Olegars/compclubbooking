<?php

namespace App\Services\StoreAvito;

use App\Models\StoreAvitoAd;
use App\Models\StoreAvitoConfig;
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
        $hasConfigs = StoreAvitoConfig::query()->exists();
        $templates = StoreAvitoConfig::query()
            ->enabled()
            ->with(['cpu', 'gpu', 'mb', 'ram', 'ssd', 'psu'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->values();

        if ($hasConfigs && $templates->isEmpty()) {
            $this->lastFailures[] = 'Конфигурации есть, но все выключены. Включи нужные кнопкой «Включить» — случайные сборки из каталога больше не создаются.';

            return [];
        }

        if ($templates->isNotEmpty()) {
            $out = $this->composeFromTemplates($templates, $count, $settings);
            if ($out === [] && $this->lastFailures === []) {
                $this->lastFailures[] = 'Не удалось подобрать живые SKU из каталога под включённые конфигурации.';
            }

            return $out;
        }

        return $this->composeRandom($count, $settings);
    }

    /**
     * @param  Collection<int, StoreAvitoConfig>  $templates
     * @return list<array<string, mixed>>
     */
    private function composeFromTemplates(Collection $templates, int $count, StoreAvitoSetting $settings): array
    {
        $used = array_fill_keys(StoreAvitoAd::query()->pluck('fingerprint')->all(), true);
        $pools = $this->pools();
        if ($pools['cpu']->isEmpty() || $pools['motherboard']->isEmpty() || $pools['ram']->isEmpty() || $pools['ssd']->isEmpty()) {
            $this->lastFailures[] = 'В каталоге нет размеченных CPU/плат/ОЗУ/SSD в наличии — не из чего собрать шаблон.';

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
            $this->lastFailures[] = $label.': в каталоге нет SSD '.($tpl->ssd?->label ?: '');

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
            $cooler = $pools['cooler']->isNotEmpty() ? $pools['cooler']->random() : null;
            $case = $pools['case']->isNotEmpty() ? $pools['case']->random() : null;
            $parts = array_values(array_filter([$cpu, $board, $ram, $gpu, $ssd, $psu, $cooler, $case]));
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
        $part = $tpl->cpu;
        $code = mb_strtolower((string) ($part?->avito_code ?? ''));
        $socket = $part?->socket;

        return $cpus->filter(fn (array $c) => $this->cpuMatches($c, $code, $socket))->values();
    }

    /**
     * @param  array<string, mixed>  $cpu
     */
    private function cpuMatches(array $cpu, string $code, ?string $socket): bool
    {
        if ($socket && filled($cpu['socket'] ?? null) && $cpu['socket'] !== $socket) {
            return false;
        }
        if ($code === '') {
            return true;
        }
        $attr = mb_strtolower(trim((string) ($cpu['avito_code'] ?? '')));
        if ($attr === $code) {
            return true;
        }
        $hay = mb_strtolower(($cpu['name'] ?? '').' '.($cpu['part'] ?? '').' '.$attr);

        return (bool) preg_match('/(?<![0-9a-zа-яё])'.preg_quote($code, '/').'(?![0-9a-zа-яё])/u', $hay);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $gpus
     * @return Collection<int, array<string, mixed>>
     */
    private function matchGpu(Collection $gpus, StoreAvitoConfig $tpl): Collection
    {
        $code = trim((string) ($tpl->gpu?->avito_code ?? ''));
        if ($code === '') {
            return $gpus;
        }

        return $gpus->filter(fn (array $g) => $this->gpuChipMatches($g, $code))->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $boards
     * @return Collection<int, array<string, mixed>>
     */
    private function matchMotherboard(Collection $boards, StoreAvitoConfig $tpl): Collection
    {
        $part = $tpl->mb;
        $code = strtoupper(trim((string) ($part?->avito_code ?? '')));
        $socket = $part?->socket ?: $tpl->cpu?->socket ?: $tpl->socket;
        $match = $boards;
        if ($socket) {
            $match = $match->filter(fn (array $b) => empty($b['socket']) || $b['socket'] === $socket);
        }
        if ($code !== '') {
            $match = $match->filter(fn (array $b) => $this->chipsetMatches($b, $code));
        }

        return $match->values();
    }

    /**
     * B650 совпадает с B650 / B650M / B650I, но не с B650E.
     *
     * @param  array<string, mixed>  $board
     */
    private function chipsetMatches(array $board, string $wanted): bool
    {
        $wanted = strtoupper(preg_replace('/\s+/', '', $wanted) ?? '');
        if ($wanted === '') {
            return true;
        }
        $attr = strtoupper(preg_replace('/\s+/', '', (string) ($board['avito_code'] ?? '')) ?? '');
        if ($attr !== '' && $this->chipsetTokenEquals($attr, $wanted)) {
            return true;
        }
        $hay = strtoupper(implode(' ', array_filter([
            $board['name'] ?? null,
            $board['part'] ?? null,
            $board['avito_code'] ?? null,
            $board['avito_model'] ?? null,
        ])));

        $quoted = preg_quote($wanted, '/');
        if (preg_match('/^[A-Z]\d{3}E$/', $wanted)) {
            $re = '/(?<![A-Z0-9])'.$quoted.'[MI]?(?![A-Z0-9])/u';
        } else {
            $re = '/(?<![A-Z0-9])'.$quoted.'(?!E)[MI]?(?![A-Z0-9])/u';
        }

        return (bool) preg_match($re, $hay);
    }

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
     * @param  array<string, mixed>  $gpu
     */
    private function gpuChipMatches(array $gpu, string $code): bool
    {
        $code = mb_strtolower(trim($code));
        $attr = mb_strtolower(trim((string) ($gpu['avito_code'] ?? '')));
        if ($attr !== '') {
            if ($attr === $code) {
                return true;
            }
            if (! str_contains($code, 'ti') && preg_match('/^'.preg_quote($code, '/').'\s+ti\b/u', $attr)) {
                return false;
            }
            if (! str_contains($code, 'super') && preg_match('/^'.preg_quote($code, '/').'\s+super\b/u', $attr)) {
                return false;
            }
        }
        $hay = mb_strtolower(implode(' ', array_filter([
            $gpu['name'] ?? null,
            $gpu['part'] ?? null,
            $gpu['avito_code'] ?? null,
            $gpu['avito_model'] ?? null,
        ])));
        $tokens = preg_split('/\s+/', $code) ?: [];
        if ($tokens === []) {
            return false;
        }
        $re = implode('\s*', array_map(fn (string $t) => preg_quote($t, '/'), $tokens));
        if (! preg_match('/'.$re.'/u', $hay)) {
            return false;
        }
        if (! str_contains($code, 'ti') && preg_match('/'.$re.'\s*ti\b/u', $hay)) {
            return false;
        }
        if (! str_contains($code, 'super') && preg_match('/'.$re.'\s*super\b/u', $hay)) {
            return false;
        }

        return true;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rams
     * @return Collection<int, array<string, mixed>>
     */
    private function matchRam(Collection $rams, StoreAvitoConfig $tpl): Collection
    {
        $part = $tpl->ram;
        $ddr = $part?->ddr;
        $gb = (int) ($part?->ram_gb ?? 0);

        return $rams->filter(function (array $r) use ($ddr, $gb) {
            if ($ddr && ($r['ddr'] ?? null) !== $ddr) {
                return false;
            }

            return $gb <= 0 || (int) ($r['ram_gb'] ?? 0) === $gb;
        })->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $ssds
     * @return Collection<int, array<string, mixed>>
     */
    private function matchSsd(Collection $ssds, StoreAvitoConfig $tpl): Collection
    {
        $want = (int) ($tpl->ssd?->capacity_gb ?? 0);
        if ($want <= 0) {
            return $ssds;
        }
        $range = $want >= 500 ? [480, 520] : [240, 260];

        return $ssds->filter(function (array $s) use ($range) {
            $gb = $this->ssdGb($s);

            return $gb !== null && $gb >= $range[0] && $gb <= $range[1];
        })->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $psus
     * @return Collection<int, array<string, mixed>>
     */
    private function matchPsu(Collection $psus, StoreAvitoConfig $tpl): Collection
    {
        $want = (int) ($tpl->psu?->wattage ?? 0);
        if ($want <= 0) {
            return $psus;
        }
        $picked = $this->pickPsuWatts($psus, $want);
        if ($picked->isNotEmpty()) {
            return $picked;
        }

        return $this->pickPsuWatts($this->catalogPsus(), $want);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $psus
     * @return Collection<int, array<string, mixed>>
     */
    private function pickPsuWatts(Collection $psus, int $want): Collection
    {
        $withW = $psus->map(function (array $p) {
            $p['wattage'] = $this->psuWatts($p);

            return $p;
        })->filter(fn (array $p) => (int) $p['wattage'] > 0)->values();

        $exact = $withW->filter(fn (array $p) => (int) $p['wattage'] === $want)->values();
        if ($exact->isNotEmpty()) {
            return $exact;
        }
        $near = $withW->filter(fn (array $p) => abs((int) $p['wattage'] - $want) <= 50)->values();
        if ($near->isNotEmpty()) {
            return $near;
        }

        return $withW
            ->filter(fn (array $p) => (int) $p['wattage'] >= $want && (int) $p['wattage'] <= $want + 200)
            ->sortBy(fn (array $p) => (int) $p['wattage'])
            ->values();
    }

    /**
     * @param  array<string, mixed>  $psu
     */
    private function psuWatts(array $psu): int
    {
        $w = (int) ($psu['wattage'] ?? 0);
        if ($w > 0) {
            return $w;
        }

        return (int) ($this->parser->parse(
            'psu',
            (string) ($psu['name'] ?? ''),
            (string) ($psu['part'] ?? ''),
            (string) ($psu['vendor'] ?? ''),
        )['wattage'] ?? 0);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function catalogPsus(): Collection
    {
        return StoreSupplierCatalogProduct::query()
            ->where(function ($w) {
                $w->where('price', '>', 0)->orWhere('rrp', '>', 0);
            })
            ->where(function ($w) {
                foreach (['блок питания', 'бп ', 'psu', 'power supply'] as $kw) {
                    $w->orWhereRaw('LOWER(name) LIKE ?', ['%'.$kw.'%']);
                }
            })
            ->orderBy('price')
            ->limit(400)
            ->get()
            ->map(function (StoreSupplierCatalogProduct $p) {
                $parsed = $this->parser->parse('psu', (string) $p->name, (string) ($p->part ?? ''), (string) ($p->vendor ?? ''));
                $price = (float) ($p->price ?: $p->rrp ?: 0);
                if ($price <= 0) {
                    return null;
                }

                return [
                    'type' => 'psu',
                    'sku' => (int) $p->sku,
                    'name' => (string) $p->name,
                    'part' => (string) ($p->part ?? ''),
                    'purchase' => $price,
                    'socket' => null,
                    'ddr' => null,
                    'ram_gb' => null,
                    'wattage' => $parsed['wattage'] ?? null,
                    'avito_brand' => $parsed['avito_brand'] ?? null,
                    'avito_model' => $parsed['avito_model'] ?? null,
                    'avito_code' => $parsed['avito_code'] ?? null,
                    'vendor' => (string) ($p->vendor ?? ''),
                    'has_image' => (bool) $p->has_image,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $boards
     * @param  array<string, mixed>  $cpu
     * @param  array<string, mixed>  $ram
     */
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
     * @param  array<string, mixed>  $part
     */
    private function ssdGb(array $part): ?int
    {
        if (! empty($part['ram_gb'])) {
            return (int) $part['ram_gb'];
        }
        $hay = (string) (($part['name'] ?? '').' '.($part['part'] ?? ''));
        if (preg_match('/\b(1\s*tb|1024|1000)\b/iu', $hay)) {
            return 1024;
        }
        if (preg_match('/\b(512|500|480)\b/iu', $hay)) {
            return 512;
        }
        if (preg_match('/\b(256|250|240)\b/iu', $hay)) {
            return 256;
        }

        return null;
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
            'cooler' => $this->pool('cooler'),
            'case' => $this->pool('case'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function composeRandom(int $count, StoreAvitoSetting $settings): array
    {
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
        $cpuBrand = $this->matcher->match('BrandProcessor', $cpuHay) ?: (string) ($cpu['avito_brand'] ?? '');
        $cpuModel = $this->matcher->match('ModelProcessor', $cpuHay, $cpuBrand) ?: (string) ($cpu['avito_model'] ?? '');
        $cpuCode = $this->matcher->match('CodeProcessor', $cpuHay, $cpuModel) ?: (string) ($cpu['avito_code'] ?? '');

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
