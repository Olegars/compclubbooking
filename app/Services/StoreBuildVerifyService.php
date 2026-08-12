<?php

namespace App\Services;

use App\Models\StoreBuiltPc;
use App\Models\StoreBuiltPcComponent;
use App\Models\StoreComponent;
use Illuminate\Support\Facades\DB;

class StoreBuildVerifyService
{
    /**
     * @param  list<array{type:?string,name:string,serial:?string,vendor:string,extra:array}>  $reported
     * @return array{
     *     matched: list<array>,
     *     missing: list<array>,
     *     extra: list<array>,
     *     updated_names: list<array>,
     *     updated_serials: list<array>,
     *     swapped: list<array>,
     *     conflicts: list<array>
     * }
     */
    public function verify(StoreBuiltPc $pc, array $reported, bool $applyFixes): array
    {
        $pc->loadMissing(['componentLinks', 'components']);

        $reported = array_values(array_map(function (array $row) {
            return [
                'type' => $this->normalizeType($row['type'] ?? null),
                'name' => trim((string) ($row['name'] ?? '')),
                'serial' => $this->normalizeSerial($row['serial'] ?? null),
                'vendor' => trim((string) ($row['vendor'] ?? '')),
                'extra' => $row['extra'] ?? [],
            ];
        }, $reported));

        $expected = $this->buildExpected($pc);

        $matched = [];
        $missing = [];
        $extra = [];
        $usedReported = [];

        // 1) Совпадение по серийнику
        foreach ($expected as $ei => $exp) {
            if (empty($exp['serial'])) {
                continue;
            }
            foreach ($reported as $ri => $rep) {
                if (isset($usedReported[$ri]) || empty($rep['serial'])) {
                    continue;
                }
                if (strcasecmp($rep['serial'], $exp['serial']) === 0) {
                    $usedReported[$ri] = true;
                    $matched[] = [
                        'expected' => $exp,
                        'reported' => $rep,
                        'serial_match' => true,
                        'expected_index' => $ei,
                    ];
                    $expected[$ei]['_done'] = true;
                    break;
                }
            }
        }

        // 2) Остаток — по типу + умное сравнение имён (конструктор / original_name / specs)
        foreach ($expected as $ei => $exp) {
            if (! empty($exp['_done'])) {
                continue;
            }

            $hitIndex = null;
            if ($exp['type']) {
                $byType = [];
                foreach ($reported as $ri => $rep) {
                    if (isset($usedReported[$ri])) {
                        continue;
                    }
                    if ($rep['type'] === $exp['type']) {
                        $byType[] = $ri;
                    }
                }

                $bestRi = null;
                $bestScore = 0;
                foreach ($byType as $ri) {
                    $score = $this->bestNameScore($reported[$ri]['name'] ?? '', $exp['match_names'] ?? []);
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestRi = $ri;
                    }
                }

                if ($bestRi !== null && $bestScore >= 1) {
                    $hitIndex = $bestRi;
                } elseif (count($byType) === 1) {
                    // Единственная позиция этого типа — принимаем без имени
                    $hitIndex = $byType[0];
                }
            }

            if ($hitIndex === null) {
                $missing[] = $exp;
                continue;
            }

            $usedReported[$hitIndex] = true;
            $rep = $reported[$hitIndex];
            $serialMatch = $exp['serial'] && $rep['serial']
                && strcasecmp($exp['serial'], $rep['serial']) === 0;

            $matched[] = [
                'expected' => $exp,
                'reported' => $rep,
                'serial_match' => $serialMatch,
                'expected_index' => $ei,
            ];
        }

        foreach ($reported as $ri => $rep) {
            if (! isset($usedReported[$ri])) {
                $extra[] = $rep;
            }
        }

        $updatedNames = [];
        $updatedSerials = [];
        $swapped = [];
        $conflicts = [];

        if ($applyFixes) {
            $result = DB::transaction(function () use ($pc, $matched, &$conflicts) {
                return $this->applyFixes($pc, $matched, $conflicts);
            });
            $updatedNames = $result['updated_names'];
            $updatedSerials = $result['updated_serials'];
            $swapped = $result['swapped'];
            $matched = $result['matched'];
        }

        // Убрать служебные поля из ответа
        $matched = array_map(function (array $row) {
            unset($row['expected_index'], $row['expected']['_done'], $row['expected']['match_names']);

            return $row;
        }, $matched);
        $missing = array_map(function (array $row) {
            unset($row['_done'], $row['match_names']);

            return $row;
        }, $missing);

        return [
            'matched' => $matched,
            'missing' => $missing,
            'extra' => $extra,
            'updated_names' => $updatedNames,
            'updated_serials' => $updatedSerials,
            'swapped' => $swapped,
            'conflicts' => $conflicts,
        ];
    }

    /**
     * @param  list<array>  $matched
     * @param  list<array>  $conflicts
     * @return array{matched:list<array>,updated_names:list<array>,updated_serials:list<array>,swapped:list<array>}
     */
    private function applyFixes(StoreBuiltPc $pc, array $matched, array &$conflicts): array
    {
        $updatedNames = [];
        $updatedSerials = [];
        $swapped = [];

        foreach ($matched as &$row) {
            $exp = $row['expected'];
            $rep = $row['reported'];
            $repName = $rep['name'] ?? '';
            $repSerial = $rep['serial'] ?? null;
            $expSerial = $exp['serial'] ?? null;
            $componentId = $exp['component_id'] ?? null;
            $linkId = $exp['link_id'] ?? null;

            // Серийника с ПК нет — серийники в базе не трогаем
            if ($repSerial) {
                if ($expSerial && strcasecmp($expSerial, $repSerial) !== 0) {
                    // Оба есть, но разные — ищем правильный компонент и меняем в сборке
                    $found = $this->findComponentBySerial((int) $pc->club_id, $repSerial);
                    if ($found) {
                        $oldId = $componentId;
                        if ($linkId) {
                            StoreBuiltPcComponent::query()->whereKey($linkId)->update([
                                'store_component_id' => $found->id,
                                'name' => $repName !== '' ? $repName : $found->name,
                                'type' => $found->type ?: ($exp['type'] ?? null),
                            ]);
                        }

                        if ($found->status === 'in_stock' || $found->status === 'reserved') {
                            $found->update(['status' => 'used']);
                        }
                        if ($oldId && (int) $oldId !== (int) $found->id) {
                            $stillUsed = StoreBuiltPcComponent::query()
                                ->where('store_component_id', $oldId)
                                ->where('store_built_pc_id', '!=', $pc->id)
                                ->exists();
                            $onThis = StoreBuiltPcComponent::query()
                                ->where('store_component_id', $oldId)
                                ->where('store_built_pc_id', $pc->id)
                                ->exists();
                            if (! $stillUsed && ! $onThis) {
                                StoreComponent::query()->whereKey($oldId)->where('status', 'used')
                                    ->update(['status' => 'in_stock']);
                            }
                        }

                        $swapped[] = [
                            'link_id' => $linkId,
                            'old_component_id' => $oldId,
                            'new_component_id' => $found->id,
                            'old_serial' => $expSerial,
                            'new_serial' => $repSerial,
                            'name' => $found->name,
                        ];

                        $row['expected']['component_id'] = $found->id;
                        $row['expected']['serial'] = $repSerial;
                        $row['expected']['name'] = $repName !== '' ? $repName : $found->name;
                        $row['serial_match'] = true;
                        $componentId = $found->id;
                    } else {
                        $conflicts[] = [
                            'expected' => $exp,
                            'reported' => $rep,
                            'reason' => 'В базе нет комплектующего с серийником '.$repSerial,
                        ];
                        $row['serial_match'] = false;
                    }
                } elseif (! $expSerial && $componentId) {
                    // В базе пусто — дозапись
                    $component = StoreComponent::query()->find($componentId);
                    if ($component && $component->allSerials() === []) {
                        $component->update(['warranty_number' => $repSerial]);
                        $updatedSerials[] = [
                            'component_id' => $component->id,
                            'serial' => $repSerial,
                            'name' => $component->name,
                        ];
                        $row['expected']['serial'] = $repSerial;
                        $row['serial_match'] = true;
                    }
                }
            }

            // Оригинальное имя с ПК → original_name (конструкторское name не трогаем)
            $componentId = $row['expected']['component_id'] ?? $componentId;
            if ($repName !== '' && $componentId) {
                $component = StoreComponent::query()->find($componentId);
                if ($component) {
                    $oldOriginal = $component->original_name;
                    if ($oldOriginal !== $repName) {
                        $component->update(['original_name' => $repName]);
                        $updatedNames[] = [
                            'component_id' => $component->id,
                            'old_name' => $oldOriginal,
                            'new_name' => $repName,
                            'field' => 'original_name',
                        ];
                    }
                }
                $row['expected']['original_name'] = $repName;
            }
        }
        unset($row);

        $this->syncBuildSpec($pc, $matched, $updatedNames, $swapped, $updatedSerials);

        return [
            'matched' => $matched,
            'updated_names' => $updatedNames,
            'updated_serials' => $updatedSerials,
            'swapped' => $swapped,
        ];
    }

    /**
     * @param  list<array>  $matched
     * @param  list<array>  $updatedNames
     * @param  list<array>  $swapped
     * @param  list<array>  $updatedSerials
     */
    private function syncBuildSpec(
        StoreBuiltPc $pc,
        array $matched,
        array $updatedNames,
        array $swapped,
        array $updatedSerials
    ): void {
        $originalSpec = $pc->build_spec;
        $noteBits = ['Проверено check_build: '.now()->format('Y-m-d H:i')];
        if ($updatedSerials !== []) {
            $noteBits[] = 'дописано S/N: '.count($updatedSerials);
        }
        if ($swapped !== []) {
            $noteBits[] = 'заменено комплектующих: '.count($swapped);
        }
        $note = implode(', ', $noteBits);

        if (is_array($originalSpec) && array_is_list($originalSpec)) {
            $spec = collect($originalSpec)->map(function ($item) use ($updatedNames, $swapped, $updatedSerials) {
                $cid = isset($item['component_id']) ? (int) $item['component_id'] : null;

                foreach ($swapped as $s) {
                    if ($cid && $cid === (int) ($s['old_component_id'] ?? 0)) {
                        $item['component_id'] = $s['new_component_id'];
                        $item['name'] = $s['name'];
                        $item['serial'] = $s['new_serial'];
                        $item['warranty_number'] = $s['new_serial'];
                        $cid = (int) $s['new_component_id'];
                    }
                }
                foreach ($updatedNames as $u) {
                    if ($cid && $cid === (int) $u['component_id']) {
                        $item['original_name'] = $u['new_name'];
                        $item['verified_name'] = $u['new_name'];
                    }
                }
                foreach ($updatedSerials as $u) {
                    if ($cid && $cid === (int) $u['component_id']) {
                        $item['serial'] = $u['serial'];
                        $item['warranty_number'] = $u['serial'];
                    }
                }

                return $item;
            })->values()->all();

            foreach ($matched as $row) {
                $spec[] = [
                    'type' => $row['reported']['type'] ?? null,
                    'name' => $row['reported']['name'] ?? null,
                    'serial' => $row['reported']['serial'] ?? null,
                    'vendor' => $row['reported']['vendor'] ?? null,
                    'component_id' => $row['expected']['component_id'] ?? null,
                    'source' => 'build_verify',
                ];
            }

            $pc->update([
                'build_spec' => $spec,
                'notes' => trim(($pc->notes ? $pc->notes."\n" : '').$note),
            ]);

            return;
        }

        $detected = [];
        foreach ($matched as $row) {
            $detected[] = [
                'type' => $row['reported']['type'] ?? $row['expected']['type'] ?? null,
                'name' => $row['reported']['name'] ?? null,
                'serial' => $row['reported']['serial'] ?? null,
                'vendor' => $row['reported']['vendor'] ?? null,
                'component_id' => $row['expected']['component_id'] ?? null,
                'source' => 'build_verify',
            ];
        }

        $pc->update([
            'build_spec' => [
                'parts' => is_array($originalSpec) ? ($originalSpec['parts'] ?? $originalSpec) : [],
                'detected' => $detected,
                'verified_at' => now()->toIso8601String(),
                'swapped' => $swapped,
                'updated_serials' => $updatedSerials,
            ],
            'notes' => trim(($pc->notes ? $pc->notes."\n" : '').$note),
        ]);
    }

    /** @return list<array> */
    private function buildExpected(StoreBuiltPc $pc): array
    {
        $expected = $pc->componentLinks->flatMap(function ($link) {
            $component = $link->store_component_id
                ? StoreComponent::query()->find($link->store_component_id)
                : null;

            $base = [
                'link_id' => $link->id,
                'component_id' => $link->store_component_id,
                'type' => $link->type ?: ($component?->type),
                'name' => $link->name ?: ($component?->name),
                'original_name' => $component?->original_name,
                'match_names' => $this->matchNamesFor($component, $link->name),
            ];

            $serials = $component
                ? array_values(array_filter(array_map([$this, 'normalizeSerial'], $component->allSerials())))
                : [];

            if ($serials === []) {
                return [[...$base, 'serial' => null]];
            }

            return collect($serials)->map(fn ($serial) => [...$base, 'serial' => $serial])->all();
        })->values()->all();

        if ($expected === [] && is_array($pc->build_spec)) {
            $rows = array_is_list($pc->build_spec)
                ? $pc->build_spec
                : ($pc->build_spec['parts'] ?? []);

            $expected = collect($rows)->flatMap(function ($row) {
                if (! is_array($row) || (($row['source'] ?? null) === 'build_verify')) {
                    return [];
                }
                $component = ! empty($row['component_id'])
                    ? StoreComponent::query()->find($row['component_id'])
                    : null;
                $base = [
                    'link_id' => null,
                    'component_id' => $row['component_id'] ?? null,
                    'type' => $this->normalizeType($row['type'] ?? null),
                    'name' => $row['name'] ?? null,
                    'original_name' => $row['original_name'] ?? $component?->original_name,
                    'match_names' => $this->matchNamesFor($component, $row['name'] ?? null),
                ];
                $serials = [];
                if (! empty($row['serials']) && is_array($row['serials'])) {
                    $serials = array_values(array_filter(array_map([$this, 'normalizeSerial'], $row['serials'])));
                } elseif (! empty($row['warranty_number']) || ! empty($row['serial'])) {
                    $one = $this->normalizeSerial($row['warranty_number'] ?? $row['serial'] ?? null);
                    if ($one) {
                        $serials = [$one];
                    }
                }

                if ($serials === []) {
                    return [[...$base, 'serial' => null]];
                }

                return collect($serials)->map(fn ($serial) => [...$base, 'serial' => $serial])->all();
            })->values()->all();
        }

        return $expected;
    }

    /** Имена для сравнения с WMI: конструктор, оригинал, куски specs. */
    private function matchNamesFor(?StoreComponent $component, ?string $linkName = null): array
    {
        $names = [];
        foreach ([
            $linkName,
            $component?->name,
            $component?->original_name,
        ] as $n) {
            $n = trim((string) $n);
            if ($n !== '') {
                $names[] = $n;
            }
        }

        $specs = is_array($component?->specs) ? $component->specs : [];
        foreach (['model', 'chip', 'series', 'chipset', 'title'] as $key) {
            $v = trim((string) ($specs[$key] ?? ''));
            if ($v !== '') {
                $names[] = $v;
                $brand = trim((string) ($specs['brand'] ?? ''));
                if ($brand !== '') {
                    $names[] = $brand.' '.$v;
                }
            }
        }

        return array_values(array_unique($names));
    }

    /** @param  list<string>  $candidates */
    private function bestNameScore(?string $reported, array $candidates): int
    {
        $best = 0;
        foreach ($candidates as $c) {
            $best = max($best, $this->nameMatchScore($reported, $c));
        }

        return $best;
    }

    /**
     * 0 — нет, 1 — слабое пересечение токенов, 2 — модель/цифры, 3 — подстрока/полное.
     */
    public function nameMatchScore(?string $a, ?string $b): int
    {
        $a = trim((string) $a);
        $b = trim((string) $b);
        if ($a === '' || $b === '') {
            return 0;
        }

        $na = $this->normalizeName($a);
        $nb = $this->normalizeName($b);
        if ($na === '' || $nb === '') {
            return 0;
        }
        if ($na === $nb || str_contains($na, $nb) || str_contains($nb, $na)) {
            return 3;
        }

        $ta = $this->nameTokens($na);
        $tb = $this->nameTokens($nb);
        if ($ta === [] || $tb === []) {
            return 0;
        }

        $overlap = 0;
        $modelHit = false;
        foreach ($ta as $t) {
            foreach ($tb as $u) {
                if ($t === $u || (mb_strlen($t) >= 3 && (str_contains($t, $u) || str_contains($u, $t)))) {
                    $overlap++;
                    if (preg_match('/\d/', $t) || preg_match('/\d/', $u)) {
                        $modelHit = true;
                    }
                    break;
                }
            }
        }

        if ($modelHit) {
            return 2;
        }
        if ($overlap >= 2) {
            return 1;
        }
        if ($overlap >= 1 && min(count($ta), count($tb)) <= 2) {
            return 1;
        }

        return 0;
    }

    private function normalizeName(string $s): string
    {
        $s = mb_strtolower($s);
        $s = str_replace(['(r)', '(tm)', '®', '™'], ' ', $s);
        $s = preg_replace('/[^a-z0-9а-яё]+/u', ' ', $s) ?? $s;

        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }

    /** @return list<string> */
    private function nameTokens(string $normalized): array
    {
        $stop = ['th', 'nd', 'rd', 'gen', 'series', 'the', 'and', 'for', 'with'];
        $parts = preg_split('/\s+/u', $normalized) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '' || mb_strlen($p) < 2 || in_array($p, $stop, true)) {
                continue;
            }
            $out[] = $p;
        }

        return $out;
    }

    public function findComponentBySerial(int $clubId, string $serial): ?StoreComponent
    {
        $serial = $this->normalizeSerial($serial);
        if ($serial === null) {
            return null;
        }

        $lower = mb_strtolower($serial);

        $hit = StoreComponent::query()
            ->where('club_id', $clubId)
            ->where(function ($q) use ($lower) {
                $q->whereRaw('LOWER(TRIM(warranty_number)) = ?', [$lower])
                    ->orWhereRaw('LOWER(TRIM(COALESCE(barcode, ""))) = ?', [$lower]);
            })
            ->first();

        if ($hit) {
            return $hit;
        }

        // JSON-массив serials (комплекты ОЗУ и т.п.)
        $candidates = StoreComponent::query()
            ->where('club_id', $clubId)
            ->whereNotNull('serials')
            ->get(['id', 'serials', 'warranty_number', 'barcode', 'name', 'type', 'status', 'club_id']);

        foreach ($candidates as $c) {
            foreach ($c->allSerials() as $s) {
                if (strcasecmp((string) $s, $serial) === 0) {
                    return $c;
                }
            }
        }

        return null;
    }

    public function normalizeSerial(?string $serial): ?string
    {
        $serial = trim((string) $serial);
        $serial = rtrim($serial, ". \t\0");
        if ($serial === '' || strcasecmp($serial, 'To Be Filled By O.E.M.') === 0 || strcasecmp($serial, 'None') === 0) {
            return null;
        }

        return $serial;
    }

    public function normalizeType(?string $type): ?string
    {
        $type = strtolower(trim((string) $type));
        if ($type === '') {
            return null;
        }

        return match (true) {
            str_contains($type, 'cpu') || str_contains($type, 'processor') => 'cpu',
            str_contains($type, 'ram') || str_contains($type, 'memory') => 'ram',
            str_contains($type, 'ssd') || str_contains($type, 'nvme') => 'storage_ssd',
            str_contains($type, 'hdd') || str_contains($type, 'disk') => 'storage_hdd',
            str_contains($type, 'gpu') || str_contains($type, 'video') || str_contains($type, 'display') => 'gpu',
            str_contains($type, 'board') || str_contains($type, 'motherboard') => 'motherboard',
            default => $type,
        };
    }
}
