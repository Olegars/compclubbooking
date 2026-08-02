<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Computer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Питание ПК по расписанию бронирований.
 *
 * Правило desired=on: есть бронь (confirmed/paid/active), которая
 * пересекается с окном [now, now + warmup_minutes].
 * Иначе desired=off.
 *
 * Magic packet шлёт не облако, а MikroTik в LAN (pull:
 * GET /api/power/wol-targets → /tool wol).
 *
 * Фактический power_state:
 *   on      — шелл жив (heartbeat недавно)
 *   booting — релей подтвердил WOL, ждём heartbeat
 *   off     — выключен / давно не отвечали при desired=off
 *   error   — после WOL не ожил за wol_timeout / нет MAC
 */
class ComputerPowerService
{
    public const DESIRED_ON = 'on';

    public const DESIRED_OFF = 'off';

    public const STATE_ON = 'on';

    public const STATE_OFF = 'off';

    public const STATE_BOOTING = 'booting';

    public const STATE_ERROR = 'error';

    public function __construct(
        private readonly WakeOnLan $wol,
    ) {}

    public function warmupMinutes(): int
    {
        return max(1, (int) config('club.power.warmup_minutes', 30));
    }

    public function staleSeconds(): int
    {
        return max(30, (int) config('club.power.heartbeat_stale_seconds', 90));
    }

    public function wolTimeoutSeconds(): int
    {
        return max(60, (int) config('club.power.wol_timeout_seconds', 180));
    }

    /**
     * Пересчитать desired + фактическое состояние (без отправки WOL).
     */
    public function syncAll(?CarbonImmutable $now = null): int
    {
        $now = $now ?? CarbonImmutable::now();
        $ids = DB::table('computers')
            ->whereNotNull('hwid')
            ->where('hwid', '!=', '')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        if ($ids === []) {
            return 0;
        }

        return $this->syncFor($ids, $now);
    }

    /**
     * @param  iterable<int|string>|int  $computerIds
     */
    public function syncFor(iterable|int $computerIds, ?CarbonImmutable $now = null): int
    {
        $now = $now ?? CarbonImmutable::now();
        $ids = $this->normalizeIds($computerIds);
        if ($ids === []) {
            return 0;
        }

        $needOn = $this->computersNeedingPower($ids, $now);
        $changed = 0;

        $rows = DB::table('computers')->whereIn('id', $ids)->get();
        foreach ($rows as $row) {
            $desired = in_array((int) $row->id, $needOn, true)
                ? self::DESIRED_ON
                : self::DESIRED_OFF;

            $patch = $this->reconcileRow($row, $desired, $now);
            if ($patch === []) {
                continue;
            }

            DB::table('computers')->where('id', $row->id)->update($patch);
            $changed++;
        }

        return $changed;
    }

    /**
     * Очередь для MikroTik: ПК с desired=on, офлайн, есть MAC, пора будить/ретраить.
     *
     * @return list<array{id: int, name: string, mac: string}>
     */
    public function wolTargets(?CarbonImmutable $now = null, bool $claim = true): array
    {
        $now = $now ?? CarbonImmutable::now();
        $this->syncAll($now);

        $staleBefore = $now->subSeconds($this->staleSeconds());
        $retryBefore = $now->subSeconds($this->wolTimeoutSeconds());

        $rows = DB::table('computers')
            ->whereNotNull('hwid')
            ->where('hwid', '!=', '')
            ->where('power_desired', self::DESIRED_ON)
            ->whereNotNull('mac_address')
            ->where('mac_address', '!=', '')
            ->where(function ($q) use ($staleBefore) {
                $q->whereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<', $staleBefore);
            })
            ->where(function ($q) use ($retryBefore) {
                // Ещё не будили / off / error → в очередь.
                // booting → только после таймаута (ретрай).
                $q->whereIn('power_state', [self::STATE_OFF, self::STATE_ERROR])
                    ->orWhereNull('power_state')
                    ->orWhere(function ($booting) use ($retryBefore) {
                        $booting->where('power_state', self::STATE_BOOTING)
                            ->where(function ($w) use ($retryBefore) {
                                $w->whereNull('wol_sent_at')
                                    ->orWhere('wol_sent_at', '<=', $retryBefore);
                            });
                    });
            })
            ->orderBy('id')
            ->get(['id', 'name', 'mac_address']);

        $targets = [];
        $claimIds = [];

        foreach ($rows as $row) {
            $mac = $this->wol->normalizeMac((string) $row->mac_address);
            if ($mac === null) {
                continue;
            }
            $targets[] = [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'mac' => $mac,
            ];
            $claimIds[] = (int) $row->id;
        }

        if ($claim && $claimIds !== []) {
            $this->markWolSent($claimIds, $now);
        }

        return $targets;
    }

    /**
     * Релей сообщил, что magic packet ушёл (или claim при GET).
     *
     * @param  list<int|string>  $computerIds
     */
    public function markWolSent(array $computerIds, ?CarbonImmutable $now = null): int
    {
        $now = $now ?? CarbonImmutable::now();
        $ids = $this->normalizeIds($computerIds);
        if ($ids === []) {
            return 0;
        }

        return DB::table('computers')
            ->whereIn('id', $ids)
            ->update([
                'power_state' => self::STATE_BOOTING,
                'power_state_updated_at' => $now,
                'wol_sent_at' => $now,
            ]);
    }

    /**
     * Heartbeat от шелла: ПК жив, желаемое питание, опционально MAC.
     *
     * @return array{power_desired: string, power_state: string, power_action: string, session_active: bool}
     */
    public function heartbeat(Computer $computer, ?string $mac = null): array
    {
        $now = CarbonImmutable::now();
        $patch = [
            'last_seen_at' => $now,
            'power_state' => self::STATE_ON,
            'power_state_updated_at' => $now,
        ];

        if ($mac) {
            $normalized = $this->wol->normalizeMac($mac);
            if ($normalized && $normalized !== $computer->mac_address) {
                $patch['mac_address'] = $normalized;
            }
        }

        $needOn = $this->computersNeedingPower([(int) $computer->id], $now);
        $desired = $needOn !== [] ? self::DESIRED_ON : self::DESIRED_OFF;
        $patch['power_desired'] = $desired;

        $computer->update($patch);

        $sessionActive = $this->hasActiveSession((int) $computer->id);

        return [
            'power_desired' => $desired,
            'power_state' => self::STATE_ON,
            'power_action' => $sessionActive ? 'none' : $this->actionForDesired($desired),
            'session_active' => $sessionActive,
        ];
    }

    /**
     * Команда шеллу после закрытия сессии (logout / expire).
     */
    public function powerActionFor(int $computerId, ?CarbonImmutable $now = null): string
    {
        $now = $now ?? CarbonImmutable::now();
        $this->syncFor([$computerId], $now);

        $desired = DB::table('computers')->where('id', $computerId)->value('power_desired');

        return $this->actionForDesired((string) $desired);
    }

    public function actionForDesired(string $desired): string
    {
        return $desired === self::DESIRED_ON ? 'reboot' : 'shutdown';
    }

    /**
     * Лёгкий touch «шелл на связи» без полного пересчёта desired.
     * Для эндпоинтов, которые старый/новый шелл дергает на гостевом экране.
     */
    public function touchOnline(int $computerId, ?string $mac = null): void
    {
        $computer = Computer::query()->find($computerId);
        if (! $computer) {
            return;
        }

        $this->heartbeat($computer, $mac);
    }

    private function isAlive(?CarbonImmutable $lastSeen, CarbonImmutable $now): bool
    {
        if ($lastSeen === null) {
            return false;
        }

        return $lastSeen->greaterThanOrEqualTo($now->subSeconds($this->staleSeconds()));
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function computersNeedingPower(array $ids, CarbonImmutable $now): array
    {
        $warmup = $this->warmupMinutes();
        $horizon = $now->addMinutes($warmup);
        $nowIso = $now->utc()->toIso8601String();
        $horizonIso = $horizon->utc()->toIso8601String();

        $local = $now->timezone(config('app.timezone'));
        $today = $local->toDateString();
        $nowH = $local->hour + ($local->minute / 60);
        $horizonH = $nowH + ($warmup / 60);

        $modern = Booking::query()
            ->whereIn('computer_id', $ids)
            ->whereIn('status', ['confirmed', 'paid', 'active'])
            ->whereNotNull('starts_at')
            ->whereNotNull('ends_at')
            ->whereRaw('starts_at <= ?::timestamptz', [$horizonIso])
            ->whereRaw('ends_at > ?::timestamptz', [$nowIso])
            ->pluck('computer_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $legacy = Booking::query()
            ->whereIn('computer_id', $ids)
            ->whereIn('status', ['confirmed', 'paid', 'active'])
            ->whereNull('starts_at')
            ->where('date', $today)
            ->where('start_time', '<=', $horizonH)
            ->whereRaw('(start_time + duration) > ?', [$nowH])
            ->pluck('computer_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_unique(array_merge($modern, $legacy)));
    }

    /**
     * @param  object  $row  stdClass из computers
     * @return array<string, mixed>
     */
    private function reconcileRow(object $row, string $desired, CarbonImmutable $now): array
    {
        $patch = [];
        $state = (string) ($row->power_state ?? self::STATE_OFF);
        $lastSeen = $row->last_seen_at ? CarbonImmutable::parse($row->last_seen_at) : null;
        $wolSent = $row->wol_sent_at ? CarbonImmutable::parse($row->wol_sent_at) : null;
        $alive = $this->isAlive($lastSeen, $now);

        if ((string) ($row->power_desired ?? '') !== $desired) {
            $patch['power_desired'] = $desired;
        }

        if ($alive) {
            if ($state !== self::STATE_ON) {
                $patch['power_state'] = self::STATE_ON;
                $patch['power_state_updated_at'] = $now;
            }

            return $patch;
        }

        if ($desired === self::DESIRED_OFF) {
            if ($state !== self::STATE_OFF) {
                $patch['power_state'] = self::STATE_OFF;
                $patch['power_state_updated_at'] = $now;
            }

            return $patch;
        }

        // desired=on, шелл молчит — WOL делает MikroTik через /api/power/wol-targets.
        if ($state === self::STATE_BOOTING && $wolSent) {
            if ($wolSent->lessThanOrEqualTo($now->subSeconds($this->wolTimeoutSeconds()))) {
                $patch['power_state'] = self::STATE_ERROR;
                $patch['power_state_updated_at'] = $now;
                Log::warning('WOL timeout (relay)', ['computer_id' => $row->id, 'mac' => $row->mac_address]);
            }

            return $patch;
        }

        $mac = (string) ($row->mac_address ?? '');
        if ($mac === '' || $this->wol->normalizeMac($mac) === null) {
            if ($state !== self::STATE_ERROR) {
                $patch['power_state'] = self::STATE_ERROR;
                $patch['power_state_updated_at'] = $now;
                Log::warning('WOL pending skipped: no MAC', ['computer_id' => $row->id]);
            }

            return $patch;
        }

        // Офлайн + нужен on → ждём релей. Держам off (или оставляем error до ретрая).
        if ($state !== self::STATE_OFF && $state !== self::STATE_ERROR && $state !== self::STATE_BOOTING) {
            $patch['power_state'] = self::STATE_OFF;
            $patch['power_state_updated_at'] = $now;
        }

        return $patch;
    }

    private function hasActiveSession(int $computerId): bool
    {
        return Booking::query()
            ->where('computer_id', $computerId)
            ->where('status', 'active')
            ->exists();
    }

    /**
     * @param  iterable<int|string>|int  $computerIds
     * @return list<int>
     */
    private function normalizeIds(iterable|int $computerIds): array
    {
        $raw = is_int($computerIds) ? [$computerIds] : iterator_to_array(
            (function () use ($computerIds) {
                foreach ($computerIds as $id) {
                    yield $id;
                }
            })()
        );

        $ids = [];
        foreach ($raw as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }
}
