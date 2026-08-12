<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StoreBuiltPc;
use App\Models\StoreOrder;
use App\Services\StoreBuildVerifyService;
use App\Services\StoreOrderBuiltPcService;
use Illuminate\Http\Request;

class StoreBuildVerifyController extends Controller
{
    public function __invoke(
        Request $request,
        StoreBuildVerifyService $verify,
        StoreOrderBuiltPcService $orderBuiltPcs
    ) {
        $tokenError = $this->assertToken($request);
        if ($tokenError !== null) {
            return $tokenError;
        }

        $data = $request->validate([
            'built_pc_id' => 'nullable|integer',
            'order_id' => 'nullable|integer', // alias / номер заказа
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

            // ID заказа → связанный готовый ПК (или создать из заказа)
            if (! $pc) {
                $pc = StoreBuiltPc::query()
                    ->with(['componentLinks', 'components'])
                    ->where('store_order_id', $pcId)
                    ->first();
            }

            if (! $pc) {
                $order = StoreOrder::query()
                    ->with(['items.component', 'client'])
                    ->find($pcId);
                if ($order && in_array($order->status, ['assembling', 'ready', 'issued'], true)) {
                    $pc = $orderBuiltPcs->ensureFromOrder($order);
                    $pc->load(['componentLinks', 'components']);
                }
            }
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
                'message' => 'Сборка не найдена. Укажите ID готового ПК, номер заказа (на этапе Сборка/Готов/Выдан) или серийник ПК.',
            ], 200);
        }

        $applyFixes = array_key_exists('update_names', $data)
            ? (bool) $data['update_names']
            : (bool) config('store.build_verify_update_names', true);

        $result = $verify->verify($pc, $data['components'], $applyFixes);
        $pc->refresh();

        $ok = count($result['missing']) === 0 && count($result['conflicts']) === 0;

        $bits = [];
        if ($ok) {
            $bits[] = 'Сборка совпала с железом.';
        } else {
            $bits[] = 'Есть расхождения: не найдено '.count($result['missing'])
                .', лишних '.count($result['extra'])
                .(count($result['conflicts']) ? ', конфликтов S/N '.count($result['conflicts']) : '')
                .'.';
        }
        if (count($result['updated_names'])) {
            $bits[] = 'Оригинальные названия записаны: '.count($result['updated_names']).'.';
        }
        if (count($result['updated_serials'])) {
            $bits[] = 'Дописаны серийники: '.count($result['updated_serials']).'.';
        }
        if (count($result['swapped'])) {
            $bits[] = 'Заменены комплектующие в заказе: '.count($result['swapped']).'.';
        }

        return response()->json([
            'ok' => $ok,
            'built_pc' => [
                'id' => $pc->id,
                'store_order_id' => $pc->store_order_id,
                'title' => $pc->title,
                'status' => $pc->status,
                'serial_number' => $pc->serial_number,
            ],
            'hostname' => $data['hostname'] ?? null,
            'summary' => [
                'matched' => count($result['matched']),
                'missing' => count($result['missing']),
                'extra' => count($result['extra']),
                'updated_names' => count($result['updated_names']),
                'updated_serials' => count($result['updated_serials']),
                'swapped' => count($result['swapped']),
                'conflicts' => count($result['conflicts']),
            ],
            'matched' => $result['matched'],
            'missing' => $result['missing'],
            'extra' => $result['extra'],
            'updated_names' => $result['updated_names'],
            'updated_serials' => $result['updated_serials'],
            'swapped' => $result['swapped'],
            'conflicts' => $result['conflicts'],
            'message' => implode(' ', $bits),
        ]);
    }

    private function assertToken(Request $request): ?\Illuminate\Http\JsonResponse
    {
        $expected = (string) config('store.build_verify_token');
        if ($expected === '') {
            return response()->json([
                'ok' => false,
                'message' => 'На сервере не задан STORE_BUILD_VERIFY_TOKEN.',
            ], 200);
        }

        $given = $request->bearerToken()
            ?: $request->header('X-Build-Verify-Token')
            ?: $request->input('token');

        if (! is_string($given) || ! hash_equals($expected, $given)) {
            return response()->json([
                'ok' => false,
                'message' => 'Неверный токен. Проверьте token в config.ini.',
            ], 200);
        }

        return null;
    }
}
