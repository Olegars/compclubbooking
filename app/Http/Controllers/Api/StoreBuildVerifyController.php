<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StoreBuiltPc;
use App\Models\StoreComponent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StoreBuildVerifyController extends Controller
{
    public function __invoke(Request $request)
    {
        $this->assertToken($request);

        $data = $request->validate([
            'built_pc_id' => 'nullable|integer',
            'order_id' => 'nullable|integer', // alias
            'serial_number' => 'nullable|string|max:128',
            'hostname' => 'nullable|string|max:128',
            'components' => 'required|array|min:1',
            'components.*.type' => 'nullable|string|max:32',
            'components.*.name' => 'nullable|string|max:255',
            'components.*.serial' => 'nullable|string|max:128',
            'components.*.vendor' => 'nullable|string|max:128',
            'components.*.extra' => 'nullable|array',
            'update_names' => 'nullable|boolean',
        ]);

        $pcId = $data['built_pc_id'] ?? $data['order_id'] ?? null;
        $pc = null;

        if ($pcId) {
            $pc = StoreBuiltPc::query()->with(['componentLinks', 'components'])->find($pcId);
        } elseif (! empty($data['serial_number'])) {
            $pc = StoreBuiltPc::query()
                ->with(['componentLinks', 'components'])
                ->where('serial_number', $data['serial_number'])
                ->latest()
                ->first();
        }

        if (! $pc) {
            return response()->json([
                'ok' => false,
                'message' => 'Сборка не найдена. Укажите built_pc_id / order_id или serial_number ПК.',
            ], 200);
        }

        $reported = collect($data['components'])->map(function ($row) {
            return [
                'type' => $this->normalizeType($row['type'] ?? null),
                'name' => trim((string) ($row['name'] ?? '')),
                'serial' => $this->normalizeSerial($row['serial'] ?? null),
                'vendor' => trim((string) ($row['vendor'] ?? '')),
                'extra' => $row['extra'] ?? [],
            ];
        })->values();

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

            // Комплект ОЗУ 2x16 → две ожидаемые планки с разными S/N
            return collect($serials)->map(fn ($serial) => [...$base, 'serial' => $serial])->all();
        })->values();

        // Если комплектация только в build_spec
        if ($expected->isEmpty() && is_array($pc->build_spec)) {
            $expected = collect($pc->build_spec)->flatMap(function ($row) {
                $base = [
                    'link_id' => null,
                    'component_id' => $row['component_id'] ?? null,
                    'type' => $row['type'] ?? null,
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
            })->values();
        }

        $matched = [];
        $missing = [];
        $extra = [];
        $usedReported = [];

        foreach ($expected as $exp) {
            $hitIndex = null;
            if ($exp['serial']) {
                foreach ($reported as $i => $rep) {
                    if (isset($usedReported[$i])) {
                        continue;
                    }
                    if ($rep['serial'] && strcasecmp($rep['serial'], $exp['serial']) === 0) {
                        $hitIndex = $i;
                        break;
                    }
                }
            }

            // fallback: тип + похожее имя
            if ($hitIndex === null && $exp['type']) {
                foreach ($reported as $i => $rep) {
                    if (isset($usedReported[$i])) {
                        continue;
                    }
                    if ($rep['type'] === $exp['type'] && $rep['name'] !== '' && $exp['name']
                        && (str_contains(mb_strtolower($rep['name']), mb_strtolower((string) $exp['name']))
                            || str_contains(mb_strtolower((string) $exp['name']), mb_strtolower($rep['name'])))) {
                        $hitIndex = $i;
                        break;
                    }
                }
            }

            if ($hitIndex === null) {
                $missing[] = $exp;
                continue;
            }

            $usedReported[$hitIndex] = true;
            $rep = $reported[$hitIndex];
            $matched[] = [
                'expected' => $exp,
                'reported' => $rep,
                'serial_match' => $exp['serial'] && $rep['serial'] && strcasecmp($exp['serial'], $rep['serial']) === 0,
            ];
        }

        foreach ($reported as $i => $rep) {
            if (! isset($usedReported[$i])) {
                $extra[] = $rep;
            }
        }

        $updateNames = array_key_exists('update_names', $data)
            ? (bool) $data['update_names']
            : (bool) config('store.build_verify_update_names', true);

        $updated = [];
        if ($updateNames) {
            $updated = DB::transaction(function () use ($matched, $pc) {
                $out = [];
                foreach ($matched as $row) {
                    $repName = $row['reported']['name'] ?? '';
                    if ($repName === '') {
                        continue;
                    }
                    $componentId = $row['expected']['component_id'] ?? null;
                    if (! $componentId) {
                        continue;
                    }
                    $component = StoreComponent::query()->find($componentId);
                    if (! $component) {
                        continue;
                    }

                    $old = $component->name;
                    if ($old !== $repName) {
                        $component->update(['name' => $repName]);
                        $out[] = [
                            'component_id' => $component->id,
                            'old_name' => $old,
                            'new_name' => $repName,
                        ];
                    }

                    // обновить имя в связи сборки
                    if (! empty($row['expected']['link_id'])) {
                        $pc->componentLinks()
                            ->whereKey($row['expected']['link_id'])
                            ->update(['name' => $repName]);
                    }
                }

                // зеркало build_spec (сохраняем массив parts, если был массив)
                $originalSpec = $pc->build_spec;
                if (is_array($originalSpec) && array_is_list($originalSpec)) {
                    $spec = collect($originalSpec)->map(function ($item) use ($out) {
                        foreach ($out as $u) {
                            if (! empty($item['component_id']) && (int) $item['component_id'] === (int) $u['component_id']) {
                                $item['name'] = $u['new_name'];
                                $item['verified_name'] = $u['new_name'];
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
                        'notes' => trim(($pc->notes ? $pc->notes."\n" : '').'Проверено check_build: '.now()->format('Y-m-d H:i')),
                    ]);
                } else {
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
                        ],
                        'notes' => trim(($pc->notes ? $pc->notes."\n" : '').'Проверено check_build: '.now()->format('Y-m-d H:i')),
                    ]);
                }

                return $out;
            });
        }

        $ok = count($missing) === 0;

        return response()->json([
            'ok' => $ok,
            'built_pc' => [
                'id' => $pc->id,
                'title' => $pc->title,
                'status' => $pc->status,
                'serial_number' => $pc->serial_number,
            ],
            'hostname' => $data['hostname'] ?? null,
            'summary' => [
                'matched' => count($matched),
                'missing' => count($missing),
                'extra' => count($extra),
                'updated_names' => count($updated),
            ],
            'matched' => $matched,
            'missing' => $missing,
            'extra' => $extra,
            'updated_names' => $updated,
            'message' => $ok
                ? 'Сборка совпала с железом.'.(count($updated) ? ' Названия обновлены.' : '')
                : 'Есть расхождения: не найдено '.count($missing).', лишних '.count($extra).'.',
        ]);
    }

    private function assertToken(Request $request): void
    {
        $expected = (string) config('store.build_verify_token');
        if ($expected === '') {
            abort(503, 'STORE_BUILD_VERIFY_TOKEN не задан на сервере.');
        }

        $given = $request->bearerToken()
            ?: $request->header('X-Build-Verify-Token')
            ?: $request->input('token');

        if (! is_string($given) || ! hash_equals($expected, $given)) {
            abort(401, 'Неверный токен сверки сборки.');
        }
    }

    private function normalizeSerial(?string $serial): ?string
    {
        $serial = trim((string) $serial);
        if ($serial === '' || strcasecmp($serial, 'To Be Filled By O.E.M.') === 0 || strcasecmp($serial, 'None') === 0) {
            return null;
        }

        return $serial;
    }

    private function normalizeType(?string $type): ?string
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
