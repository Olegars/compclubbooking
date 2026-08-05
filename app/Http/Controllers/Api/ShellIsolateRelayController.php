<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Computer;
use App\Services\WakeOnLan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Pull-API для MikroTik: изоляция / восстановление сети TV-шелла (киоск).
 *
 * GET  /api/power/isolate-targets?token=...
 * POST /api/power/isolate-applied  { token, isolate_ids?: [], restore_ids?: [] }
 */
class ShellIsolateRelayController extends Controller
{
    private const ISOLATE_KEY = 'shell_isolate_queue';

    private const RESTORE_KEY = 'shell_restore_queue';

    public static function queueIsolate(int $terminalId, array $extra = []): void
    {
        $q = Cache::get(self::ISOLATE_KEY, []);
        $q[(string) $terminalId] = array_merge([
            'computer_id' => $terminalId,
            'at' => now()->toIso8601String(),
        ], $extra);
        Cache::put(self::ISOLATE_KEY, $q, now()->addHours(12));

        $r = Cache::get(self::RESTORE_KEY, []);
        unset($r[(string) $terminalId]);
        Cache::put(self::RESTORE_KEY, $r, now()->addHours(12));
    }

    public static function queueRestore(int $terminalId, array $extra = []): void
    {
        $i = Cache::get(self::ISOLATE_KEY, []);
        unset($i[(string) $terminalId]);
        Cache::put(self::ISOLATE_KEY, $i, now()->addHours(12));

        $q = Cache::get(self::RESTORE_KEY, []);
        $q[(string) $terminalId] = array_merge([
            'computer_id' => $terminalId,
            'at' => now()->toIso8601String(),
        ], $extra);
        Cache::put(self::RESTORE_KEY, $q, now()->addHours(12));
    }

    public function targets(Request $request)
    {
        if (! $this->tokenOk($request)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $isolate = $this->expandQueue(Cache::get(self::ISOLATE_KEY, []));
        $restore = $this->expandQueue(Cache::get(self::RESTORE_KEY, []));

        return response()->json([
            'status' => 'success',
            'isolate' => $isolate,
            'restore' => $restore,
            'macs_isolate' => array_values(array_filter(array_map(fn ($t) => $t['mac'] ?? null, $isolate))),
            'macs_restore' => array_values(array_filter(array_map(fn ($t) => $t['mac'] ?? null, $restore))),
            'ips_isolate' => array_values(array_filter(array_map(fn ($t) => $t['ip'] ?? null, $isolate))),
            'ips_restore' => array_values(array_filter(array_map(fn ($t) => $t['ip'] ?? null, $restore))),
        ]);
    }

    public function applied(Request $request)
    {
        if (! $this->tokenOk($request)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $request->validate([
            'isolate_ids' => 'nullable|array',
            'isolate_ids.*' => 'integer',
            'restore_ids' => 'nullable|array',
            'restore_ids.*' => 'integer',
        ]);

        $isolateIds = array_map('intval', $request->input('isolate_ids', []) ?: []);
        $restoreIds = array_map('intval', $request->input('restore_ids', []) ?: []);

        if ($isolateIds !== []) {
            $q = Cache::get(self::ISOLATE_KEY, []);
            foreach ($isolateIds as $id) {
                unset($q[(string) $id]);
            }
            Cache::put(self::ISOLATE_KEY, $q, now()->addHours(12));
        }

        if ($restoreIds !== []) {
            $q = Cache::get(self::RESTORE_KEY, []);
            foreach ($restoreIds as $id) {
                unset($q[(string) $id]);
            }
            Cache::put(self::RESTORE_KEY, $q, now()->addHours(12));
        }

        return response()->json([
            'status' => 'success',
            'cleared_isolate' => $isolateIds,
            'cleared_restore' => $restoreIds,
        ]);
    }

    /**
     * @param  array<string, array>  $queue
     * @return list<array{id:int,name:string,mac:?string,ip:?string,at:?string}>
     */
    private function expandQueue(array $queue): array
    {
        if ($queue === []) {
            return [];
        }

        $ids = array_map('intval', array_keys($queue));
        $computers = Computer::query()->whereIn('id', $ids)->get()->keyBy('id');
        $wol = app(WakeOnLan::class);
        $out = [];

        foreach ($queue as $key => $row) {
            $id = (int) ($row['computer_id'] ?? $key);
            $pc = $computers->get($id);
            $mac = $row['mac'] ?? $pc?->mac_address;
            $mac = $mac ? $wol->normalizeMac((string) $mac) : null;
            $ip = $row['ip'] ?? null;
            if (is_string($ip)) {
                $ip = trim($ip);
                if ($ip === '' || ! filter_var($ip, FILTER_VALIDATE_IP)) {
                    $ip = null;
                }
            } else {
                $ip = null;
            }

            $out[] = [
                'id' => $id,
                'name' => (string) ($pc?->name ?: ('#'.$id)),
                'mac' => $mac,
                'ip' => $ip,
                'at' => $row['at'] ?? null,
            ];
        }

        return $out;
    }

    private function tokenOk(Request $request): bool
    {
        $expected = (string) config('club.power.wol_relay_token', '');
        if ($expected === '') {
            Log::warning('Isolate relay: CLUB_WOL_RELAY_TOKEN is empty — rejecting');

            return false;
        }

        $given = (string) (
            $request->query('token')
            ?? $request->header('X-Wol-Token')
            ?? $request->input('token')
            ?? ''
        );

        return hash_equals($expected, $given);
    }
}
