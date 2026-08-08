<?php

namespace App\Services;

use App\Models\User;
use App\Models\WifiAccessSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class WifiAccessService
{
    public function __construct(
        private readonly WakeOnLan $wol,
    ) {
    }

    public function enabled(): bool
    {
        return (bool) config('wifi_access.enabled', false);
    }

    public function stationCode(): string
    {
        return trim((string) config('wifi_access.station_code', 'club'));
    }

    public function assertStation(?string $station): void
    {
        $expected = $this->stationCode();
        $got = trim((string) $station);
        if ($expected === '' || $got === '' || ! hash_equals($expected, $got)) {
            throw new RuntimeException('Неверный QR / код станции Wi-Fi.');
        }
    }

    /**
     * Игрок подтвердил вход (QR). MAC желателен сразу (Hotspot $(mac));
     * без MAC сессия pending — MikroTik может дослать при grant-applied по IP.
     *
     * @return WifiAccessSession
     */
    public function authorize(
        User $user,
        string $station,
        ?string $mac = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): WifiAccessSession {
        if (! $this->enabled()) {
            throw new RuntimeException('Гостевой Wi-Fi выключен (WIFI_ACCESS_ENABLED).');
        }

        $this->assertStation($station);

        $normalizedMac = $mac !== null && trim($mac) !== ''
            ? $this->wol->normalizeMac($mac)
            : null;

        if ($mac !== null && trim($mac) !== '' && $normalizedMac === null) {
            throw new RuntimeException('Некорректный MAC-адрес.');
        }

        $hours = max(1, (int) config('wifi_access.session_hours', 12));

        return DB::transaction(function () use ($user, $station, $normalizedMac, $ip, $userAgent, $hours) {
            // Одна активная сессия на user+mac (или user без mac)
            WifiAccessSession::query()
                ->where('user_id', $user->id)
                ->whereIn('status', [WifiAccessSession::STATUS_PENDING, WifiAccessSession::STATUS_GRANTED])
                ->when($normalizedMac, fn ($q) => $q->where('mac_address', $normalizedMac))
                ->when(! $normalizedMac, fn ($q) => $q->whereNull('mac_address'))
                ->update([
                    'status' => WifiAccessSession::STATUS_REVOKED,
                    'revoked_at' => now(),
                ]);

            $session = WifiAccessSession::query()->create([
                'user_id' => $user->id,
                'phone' => $user->phone,
                'station_code' => trim($station),
                'mac_address' => $normalizedMac,
                'client_ip' => $ip ? trim($ip) : null,
                'status' => WifiAccessSession::STATUS_PENDING,
                'authorized_at' => now(),
                'expires_at' => now()->addHours($hours),
                'user_agent' => $userAgent ? mb_substr($userAgent, 0, 512) : null,
            ]);

            Log::info('[WIFI-ACCESS] authorize', [
                'session_id' => $session->id,
                'user_id' => $user->id,
                'phone' => $user->phone,
                'mac' => $normalizedMac,
                'ip' => $ip,
            ]);

            return $session;
        });
    }

    public function revoke(WifiAccessSession $session, string $reason = 'manual'): void
    {
        if (in_array($session->status, [WifiAccessSession::STATUS_REVOKED, WifiAccessSession::STATUS_EXPIRED], true)) {
            return;
        }

        $session->update([
            'status' => WifiAccessSession::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);

        Log::info('[WIFI-ACCESS] revoke', [
            'session_id' => $session->id,
            'mac' => $session->mac_address,
            'reason' => $reason,
        ]);
    }

    /**
     * Очередь для MikroTik: кого пустить / кого забрать.
     *
     * @return array{
     *   grant: list<array{id:int,mac:?string,ip:?string,phone:?string,expires_at:?string}>,
     *   revoke: list<array{id:int,mac:?string,ip:?string}>
     * }
     */
    public function relayTargets(): array
    {
        $this->expireStale();

        $grant = WifiAccessSession::query()
            ->where('status', WifiAccessSession::STATUS_PENDING)
            ->where(function ($q) {
                $q->whereNotNull('mac_address')->orWhereNotNull('client_ip');
            })
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->map(fn (WifiAccessSession $s) => [
                'id' => (int) $s->id,
                'mac' => $s->mac_address,
                'ip' => $s->client_ip,
                'phone' => $s->phone,
                'expires_at' => optional($s->expires_at)?->toIso8601String(),
            ])
            ->values()
            ->all();

        $revoke = WifiAccessSession::query()
            ->whereIn('status', [WifiAccessSession::STATUS_REVOKED, WifiAccessSession::STATUS_EXPIRED])
            ->whereNotNull('mac_address')
            ->where('updated_at', '>=', now()->subHours(6))
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (WifiAccessSession $s) => [
                'id' => (int) $s->id,
                'mac' => $s->mac_address,
                'ip' => $s->client_ip,
            ])
            ->values()
            ->all();

        return [
            'grant' => $grant,
            'revoke' => $revoke,
            'macs_grant' => array_values(array_filter(array_map(fn ($t) => $t['mac'] ?? null, $grant))),
            'macs_revoke' => array_values(array_filter(array_map(fn ($t) => $t['mac'] ?? null, $revoke))),
        ];
    }

    /**
     * @param  list<int>  $grantIds
     * @param  list<int>  $revokeIds
     * @param  array<int, array{mac?:string,ip?:string}>  $enrich  дозаполнение MAC/IP с роутера
     */
    public function markApplied(array $grantIds, array $revokeIds = [], array $enrich = []): void
    {
        foreach ($grantIds as $id) {
            $session = WifiAccessSession::query()->find($id);
            if (! $session || $session->status !== WifiAccessSession::STATUS_PENDING) {
                continue;
            }

            $patch = [
                'status' => WifiAccessSession::STATUS_GRANTED,
                'granted_at' => now(),
            ];

            if (isset($enrich[$id]['mac'])) {
                $n = $this->wol->normalizeMac((string) $enrich[$id]['mac']);
                if ($n) {
                    $patch['mac_address'] = $n;
                }
            }
            if (isset($enrich[$id]['ip']) && filled($enrich[$id]['ip'])) {
                $patch['client_ip'] = trim((string) $enrich[$id]['ip']);
            }

            $session->update($patch);
        }

        // revoke ids уже в статусе revoked/expired — applied = ack для скрипта
        if ($revokeIds !== []) {
            Log::info('[WIFI-ACCESS] revoke applied', ['ids' => $revokeIds]);
        }
    }

    public function expireStale(): int
    {
        return WifiAccessSession::query()
            ->whereIn('status', [WifiAccessSession::STATUS_PENDING, WifiAccessSession::STATUS_GRANTED])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update([
                'status' => WifiAccessSession::STATUS_EXPIRED,
                'revoked_at' => now(),
            ]);
    }
}
