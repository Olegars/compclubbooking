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
 * online = last_seen_at свежий (шелл недавно ответил).
 * Magic packet шлёт MikroTik (GET /api/power/wol-targets), не облако.
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
        return max(30, (int) config('club.power.heartbeat_stale_seconds', 180));
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
        $aliveIds = array_flip($this->aliveComputerIds($ids));
        $changed = 0;

        $rows = DB::table('computers')->whereIn('id', $ids)->get();
        foreach ($rows as $row) {
            $id = (int) $row->id;
            $desired = in_array($id, $needOn, true) ? self::DESIRED_ON : self::DESIRED_OFF;
            $alive = isset($aliveIds[$id]);

            $patch = $this->reconcileRow($row, $desired, $alive, $now);
            if ($patch === []) {
                continue;
            }

            DB::table('computers')->where('id', $id)->update($patch);
            $changed++;
        }

        return $changed;
    }

    /**
     * Очередь для MikroTik.
     *
     * @return list<array{id: int, name: string, mac: string}>
     */
    public function wolTargets(?CarbonImmutable $now = null, bool $claim = true): array
    {
        $now = $now ?? CarbonImmutable::now();
        $this->syncAll($now);

        $staleSec = $this->staleSeconds();
        $retrySec = $this->wolTimeoutSeconds();

        $rows = DB::table('computers')
            ->whereNotNull('hwid')
            ->where('hwid', '!=', '')
            ->where('power_desired', self::DESIRED_ON)
            ->whereNotNull('mac_address')
            ->where('mac_address', '!=', '')
            ->where(function ($q) use ($staleSec) {
                $q->whereNull('last_seen_at')
                    ->orWhereRaw("last_seen_at < NOW() - (? * INTERVAL '1 second')", [$staleSec]);
            })
            ->where(function ($q) use ($retrySec) {
                $q->whereIn('power_state', [self::STATE_OFF, self::STATE_ERROR])
                    ->orWhereNull('power_state')
                    ->orWhere(function ($booting) use ($retrySec) {
                        $booting->where('power_state', self::STATE_BOOTING)
                            ->where(function ($w) use ($retrySec) {
                                $w->whereNull('wol_sent_at')
                                    ->orWhereRaw("wol_sent_at <= NOW() - (? * INTERVAL '1 second')", [$retrySec]);
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
     * @param  list<int|string>  $computerIds
     */
    public function markWolSent(array $computerIds, ?CarbonImmutable $now = null): int
    {
        $ids = $this->normalizeIds($computerIds);
        if ($ids === []) {
            return 0;
        }

        return DB::table('computers')
            ->whereIn('id', $ids)
            ->update([
                'power_state' => self::STATE_BOOTING,
                'power_state_updated_at' => DB::raw('NOW()'),
                'wol_sent_at' => DB::raw('NOW()'),
            ]);
    }

    /**
     * Heartbeat / любой сигнал что шелл жив.
     *
     * @return array{power_desired: string, power_state: string, power_action: string, session_active: bool}
     */
    public function heartbeat(Computer $computer, ?string $mac = null): array
    {
        $id = (int) $computer->id;
        $this->markOnline($id, $mac);

        $desired = self::DESIRED_OFF;
        try {
            $needOn = $this->computersNeedingPower([$id], CarbonImmutable::now());
            $desired = $needOn !== [] ? self::DESIRED_ON : self::DESIRED_OFF;
            DB::table('computers')->where('id', $id)->update(['power_desired' => $desired]);
        } catch (\Throwable $e) {
            Log::warning('Power desired recalculation failed', [
                'computer_id' => $id,
                'error' => $e->getMessage(),
            ]);
            $desired = (string) (DB::table('computers')->where('id', $id)->value('power_desired') ?: self::DESIRED_OFF);
        }

        $sessionActive = $this->hasActiveSession($id);

        return [
            'power_desired' => $desired,
            'power_state' => self::STATE_ON,
            'power_action' => $sessionActive ? 'none' : $this->actionForDesired($desired),
            'session_active' => $sessionActive,
        ];
    }

    /**
     * Шелл штатно выключается — сразу off, не ждём stale-таймаут.
     */
    public function markOffline(int $computerId): void
    {
        if ($computerId <= 0) {
            return;
        }

        DB::table('computers')->where('id', $computerId)->update([
            'power_state' => self::STATE_OFF,
            'power_state_updated_at' => DB::raw('NOW()'),
            // null → statusSnapshot не считает ПК онлайн
            'last_seen_at' => null,
            'updated_at' => DB::raw('NOW()'),
        ]);
    }

    /**
     * Пометить ПК онлайн. Пишет через SQL NOW() — без сюрпризов таймзоны PHP.
     */
    public function markOnline(int $computerId, ?string $mac = null): void
    {
        if ($computerId <= 0) {
            return;
        }

        $patch = [
            'last_seen_at' => DB::raw('NOW()'),
            'power_state' => self::STATE_ON,
            'power_state_updated_at' => DB::raw('NOW()'),
            'updated_at' => DB::raw('NOW()'),
        ];

        if ($mac) {
            $normalized = $this->wol->normalizeMac($mac);
            if ($normalized) {
                $patch['mac_address'] = $normalized;
            }
        }

        $affected = DB::table('computers')->where('id', $computerId)->update($patch);
        if ($affected === 0) {
            Log::warning('Power markOnline: computer not found', ['computer_id' => $computerId]);
        }
    }

    public function touchOnline(int $computerId, ?string $mac = null): void
    {
        $this->markOnline($computerId, $mac);
    }

    /**
     * Команда шеллу после закрытия сессии.
     * Сам факт HTTP logout значит ПК сейчас онлайн.
     */
    public function powerActionFor(int $computerId, ?CarbonImmutable $now = null): string
    {
        $this->markOnline($computerId);
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
     * Снимок для админ-дашборда: online выводим по last_seen (SQL NOW()), не слепо по колонке.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public function statusSnapshot(?int $clubId = null)
    {
        $stale = $this->staleSeconds();

        $sql = "SELECT id, name, status, power_desired, last_seen_at,
                       CASE
                           WHEN last_seen_at IS NOT NULL
                                AND last_seen_at >= NOW() - (? * INTERVAL '1 second')
                           THEN 'on'
                           ELSE COALESCE(power_state, 'off')
                       END AS power_state
                FROM computers";
        $bindings = [$stale];

        if ($clubId !== null) {
            $sql .= ' WHERE club_id = ?';
            $bindings[] = $clubId;
        }

        $sql .= ' ORDER BY name ASC';

        return collect(DB::select($sql, $bindings));
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function aliveComputerIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $stale = $this->staleSeconds();

        return DB::table('computers')
            ->whereIn('id', $ids)
            ->whereRaw("last_seen_at IS NOT NULL AND last_seen_at >= NOW() - (? * INTERVAL '1 second')", [$stale])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
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
     * @param  object  $row
     * @return array<string, mixed>
     */
    private function reconcileRow(object $row, string $desired, bool $alive, CarbonImmutable $now): array
    {
        $patch = [];
        $state = (string) ($row->power_state ?? self::STATE_OFF);

        if ((string) ($row->power_desired ?? '') !== $desired) {
            $patch['power_desired'] = $desired;
        }

        if ($alive) {
            if ($state !== self::STATE_ON) {
                $patch['power_state'] = self::STATE_ON;
                $patch['power_state_updated_at'] = DB::raw('NOW()');
            }

            return $patch;
        }

        if ($desired === self::DESIRED_OFF) {
            if ($state !== self::STATE_OFF) {
                $patch['power_state'] = self::STATE_OFF;
                $patch['power_state_updated_at'] = DB::raw('NOW()');
            }

            return $patch;
        }

        // desired=on, шелл молчит
        $wolSent = $row->wol_sent_at ? CarbonImmutable::parse($row->wol_sent_at) : null;
        if ($state === self::STATE_BOOTING && $wolSent) {
            $timeoutAt = CarbonImmutable::now()->subSeconds($this->wolTimeoutSeconds());
            if ($wolSent->lessThanOrEqualTo($timeoutAt)) {
                $patch['power_state'] = self::STATE_ERROR;
                $patch['power_state_updated_at'] = DB::raw('NOW()');
                Log::warning('WOL timeout (relay)', ['computer_id' => $row->id, 'mac' => $row->mac_address]);
            }

            return $patch;
        }

        $mac = (string) ($row->mac_address ?? '');
        if ($mac === '' || $this->wol->normalizeMac($mac) === null) {
            if ($state !== self::STATE_ERROR) {
                $patch['power_state'] = self::STATE_ERROR;
                $patch['power_state_updated_at'] = DB::raw('NOW()');
                Log::warning('WOL pending skipped: no MAC', ['computer_id' => $row->id]);
            }

            return $patch;
        }

        if ($state !== self::STATE_OFF && $state !== self::STATE_ERROR && $state !== self::STATE_BOOTING) {
            $patch['power_state'] = self::STATE_OFF;
            $patch['power_state_updated_at'] = DB::raw('NOW()');
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
