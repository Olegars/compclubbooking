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

        // 2) Остаток — по типу (+ похожее имя)
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

                foreach ($byType as $ri) {
                    $rep = $reported[$ri];
                    if ($rep['name'] !== '' && $exp['name']
                        && (str_contains(mb_strtolower($rep['name']), mb_strtolower((string) $exp['name']))
                            || str_contains(mb_strtolower((string) $exp['name']), mb_strtolower($rep['name'])))) {
                        $hitIndex = $ri;
                        break;
                    }
                }
                if ($hitIndex === null && count($byType) === 1) {
                    $hitIndex = $byType[0];
                } elseif ($hitIndex === null && $byType !== []) {
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
            unset($row['expected_index'], $row['expected']['_done']);

            return $row;
        }, $matched);
        $missing = array_map(function (array $row) {
            unset($row['_done']);

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

            // Имена
            $componentId = $row['expected']['component_id'] ?? $componentId;
            if ($repName !== '' && $componentId) {
                $component = StoreComponent::query()->find($componentId);
                if ($component && $component->name !== $repName) {
                    $old = $component->name;
                    $component->update(['name' => $repName]);
                    $updatedNames[] = [
                        'component_id' => $component->id,
                        'old_name' => $old,
                        'new_name' => $repName,
                    ];
                }
                if ($linkId) {
                    StoreBuiltPcComponent::query()->whereKey($linkId)->update(['name' => $repName]);
                }
                $row['expected']['name'] = $repName;
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
                        $item['name'] = $u['new_name'];
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
                $base = [
                    'link_id' => null,
                    'component_id' => $row['component_id'] ?? null,
                    'type' => $this->normalizeType($row['type'] ?? null),
                    'name' => $row['name'] ?? null,
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
