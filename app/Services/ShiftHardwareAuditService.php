<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Booking;
use App\Models\Computer;
use App\Models\ComputerInputAlert;
use App\Models\ComputerInputDevice;
use App\Models\Shift;
use App\Support\AdminShift;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Аудит зала в момент передачи смены: WOL выключенных ПК, снимок heartbeat,
 * тикеты по периферии и кабелям, ответственность сдающего в личном кабинете.
 */
class ShiftHardwareAuditService
{
    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_TIMED_OUT = 'timed_out';

    /** @var list<string> */
    private const SALARY_CODES = ['hid.disconnected', 'hardware_switch_fault', 'hardware_abuse'];

    public function timeoutSeconds(): int
    {
        return max(30, (int) config('club.power.shift_audit_timeout_seconds', 120));
    }

    public function wearWarnPct(): int
    {
        return max(1, (int) config('club.power.shift_audit_ssd_wear_warn_pct', 80));
    }

    public function start(Shift $shift): void
    {
        if ($shift->hardware_audit_status === self::STATUS_IN_PROGRESS && is_array($shift->hardware_snapshot)) {
            return;
        }

        $built = $this->buildInitial($shift);
        $shift->update([
            'hardware_audit_status' => $built['pending'] > 0 ? self::STATUS_IN_PROGRESS : self::STATUS_COMPLETED,
            'hardware_audit_started_at' => now(),
            'hardware_snapshot' => $built['stations'],
            'hardware_wake_ids' => $built['wake_ids'],
            'unresolved_hardware_damages' => null,
        ]);
    }

    /**
     * Повторно выставить desired=on для ПК, которые будим только ради аудита.
     */
    public function wakeAll(Admin $incoming): array
    {
        $shift = $this->requireTransfer($incoming);
        $ids = array_map('intval', $shift->hardware_wake_ids ?? []);
        if ($ids !== []) {
            DB::table('computers')->whereIn('id', $ids)->update([
                'power_desired' => ComputerPowerService::DESIRED_ON,
                'shift_audit_hold' => true,
                'updated_at' => now(),
            ]);
        }

        return $this->status($shift->fresh());
    }

    /**
     * @return array<string, mixed>
     */
    public function statusFor(?Admin $viewer): array
    {
        $shift = AdminShift::openShift();
        if (! $shift || $shift->status !== 'transferring') {
            return $this->emptyStatus();
        }
        if ($viewer && (int) $shift->incoming_admin_id !== (int) $viewer->id && ! $viewer->hasFullClubOps()) {
            return $this->emptyStatus();
        }

        return $this->status($shift);
    }

    /**
     * @param  array<string, mixed>  $extras
     */
    public function ingestHeartbeat(Computer $computer, array $extras): bool
    {
        $shift = Shift::query()
            ->where('status', 'transferring')
            ->whereIn('hardware_audit_status', [self::STATUS_IN_PROGRESS, self::STATUS_TIMED_OUT])
            ->orderByDesc('id')
            ->first();
        if (! $shift) {
            return false;
        }

        return DB::transaction(function () use ($shift, $computer, $extras) {
            /** @var Shift|null $locked */
            $locked = Shift::query()->whereKey($shift->id)->lockForUpdate()->first();
            if (! $locked || ! in_array($locked->hardware_audit_status, [self::STATUS_IN_PROGRESS, self::STATUS_TIMED_OUT], true)) {
                return false;
            }

            $stations = $this->stationsOf($locked);
            $index = $this->indexOf($stations, (int) $computer->id);
            if ($index === null) {
                return false;
            }

            $row = $stations[$index];
            $row['booted'] = true;
            $row['wol_success'] = true;
            $row = $this->applyTelemetry($row, $computer, $extras);

            $explicit = filter_var($extras['audit_complete'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $fromSession = ($row['source'] ?? '') === 'session';
            if ($explicit || $fromSession) {
                $row['responded'] = true;
                $row['source'] = $explicit ? 'audit' : 'session';
                $row = $this->decorate($row);
            }

            $stations[$index] = $row;
            $this->saveStations($locked, $stations);

            return ! ($row['responded'] ?? false);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function status(Shift $shift): array
    {
        $shift = $this->expire($shift, false);
        $stations = $this->stationsOf($shift);
        $total = count($stations);
        $polled = count(array_filter($stations, fn ($row) => ! empty($row['responded'])));
        $started = $shift->hardware_audit_started_at;
        $left = $this->timeoutSeconds();
        if ($started) {
            $left = max(0, $this->timeoutSeconds() - (now()->getTimestamp() - $started->getTimestamp()));
        }

        $counts = ['critical' => 0, 'warning' => 0, 'ok' => 0, 'pending' => 0];
        foreach ($stations as $row) {
            $key = (string) ($row['status'] ?? 'pending');
            if (! isset($counts[$key])) {
                $key = 'pending';
            }
            $counts[$key]++;
        }

        return [
            'status' => $shift->hardware_audit_status,
            'polled' => $polled,
            'total' => $total,
            'seconds_left' => $left,
            'timeout_seconds' => $this->timeoutSeconds(),
            'started_at' => $started?->toIso8601String(),
            'counts' => $counts,
            'stations' => array_values($stations),
        ];
    }

    public function finalize(Shift $shift, ?Admin $outgoing, Admin $incoming): void
    {
        if ($shift->hardware_audit_status === null) {
            return;
        }

        $shift = $this->expire($shift->fresh(), true);
        $stations = $this->stationsOf($shift);
        $damages = [];

        foreach ($stations as $row) {
            if (($row['status'] ?? 'ok') === 'ok' || ($row['status'] ?? '') === 'pending') {
                continue;
            }

            $codes = array_values(array_unique(array_map(
                'strval',
                $row['active_incidents'] ?? []
            )));
            $attributable = array_values(array_intersect($codes, self::SALARY_CODES));
            $responsibleId = ($outgoing && $attributable !== []) ? (int) $outgoing->id : null;

            foreach ($this->ticketsFor($row) as $ticket) {
                $this->ensureIncident(
                    (int) $row['pc_id'],
                    $ticket['type'],
                    $ticket['description'],
                    $ticket['severity'],
                    in_array($ticket['type'], self::SALARY_CODES, true) ? $responsibleId : null,
                    [
                        'shift_id' => $shift->id,
                        'pc_name' => $row['pc_name'] ?? null,
                        'code' => $ticket['type'],
                    ]
                );
            }

            $damages[] = [
                'pc_id' => (int) $row['pc_id'],
                'pc_name' => (string) ($row['pc_name'] ?? ''),
                'status' => (string) ($row['status'] ?? 'warning'),
                'codes' => $codes,
                'labels' => array_values(array_map(
                    fn ($badge) => (string) ($badge['label'] ?? ''),
                    $row['badges'] ?? []
                )),
                'responsible_admin_id' => $responsibleId,
                'accepted_by_admin_id' => (int) $incoming->id,
            ];
        }

        $wakeIds = array_map('intval', $shift->hardware_wake_ids ?? []);
        if ($wakeIds !== []) {
            DB::table('computers')->whereIn('id', $wakeIds)->update([
                'shift_audit_hold' => false,
                'updated_at' => now(),
            ]);
            try {
                app(ComputerPowerService::class)->syncFor($wakeIds);
            } catch (\Throwable $e) {
                Log::warning('Shift audit power release failed', ['error' => $e->getMessage()]);
            }
        }

        $shift->update([
            'hardware_audit_status' => $shift->hardware_audit_status === self::STATUS_TIMED_OUT
                ? self::STATUS_TIMED_OUT
                : self::STATUS_COMPLETED,
            'unresolved_hardware_damages' => $damages,
            'hardware_snapshot' => $stations,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function salaryNotes(Admin $admin): array
    {
        if (! $this->incidentsHaveResponsible()) {
            return [];
        }

        return DB::table('incidents')
            ->leftJoin('computers', 'computers.id', '=', 'incidents.computer_id')
            ->where('incidents.responsible_admin_id', $admin->id)
            ->orderByDesc('incidents.id')
            ->limit(30)
            ->get([
                'incidents.id',
                'incidents.description',
                'incidents.created_at',
                'computers.name as pc_name',
            ])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'description' => (string) $row->description,
                'created_at' => $row->created_at ? (string) $row->created_at : null,
                'pc_name' => $row->pc_name ? (string) $row->pc_name : null,
            ])
            ->all();
    }

    private function expire(Shift $shift, bool $force): Shift
    {
        if (! in_array($shift->hardware_audit_status, [self::STATUS_IN_PROGRESS, self::STATUS_TIMED_OUT], true)) {
            return $shift;
        }

        $started = $shift->hardware_audit_started_at;
        $elapsed = $started ? now()->getTimestamp() - $started->getTimestamp() : 0;
        $due = $force || ($started && $elapsed >= $this->timeoutSeconds());
        if (! $due) {
            return $shift;
        }

        $stations = $this->stationsOf($shift);
        $changed = false;
        $computers = Computer::query()
            ->whereIn('id', array_map(fn ($row) => (int) $row['pc_id'], $stations))
            ->get()
            ->keyBy('id');

        foreach ($stations as $i => $row) {
            if (! empty($row['responded'])) {
                continue;
            }
            $pc = $computers->get((int) $row['pc_id']);
            $fresh = $pc && $pc->last_seen_at && $pc->last_seen_at->greaterThan(now()->subSeconds(90));
            if ($fresh && $pc) {
                $row = $this->applyTelemetry($row, $pc, []);
                $row['responded'] = true;
                $row['source'] = 'cache';
                $row['wol_success'] = true;
                $stations[$i] = $this->decorate($row);
            } else {
                $row['responded'] = true;
                $row['wol_success'] = false;
                $row['wol_timeout'] = true;
                $row['source'] = 'timeout';
                $row['active_incidents'] = array_values(array_unique(array_merge(
                    $row['active_incidents'] ?? [],
                    ['wol_timeout']
                )));
                $stations[$i] = $this->decorate($row);
            }
            $changed = true;
        }

        if ($changed || $shift->hardware_audit_status === self::STATUS_IN_PROGRESS) {
            $pending = count(array_filter($stations, fn ($row) => empty($row['responded'])));
            $shift->update([
                'hardware_snapshot' => $stations,
                'hardware_audit_status' => $pending > 0
                    ? self::STATUS_IN_PROGRESS
                    : ($force ? ($shift->hardware_audit_status === self::STATUS_TIMED_OUT ? self::STATUS_TIMED_OUT : self::STATUS_COMPLETED) : self::STATUS_TIMED_OUT),
            ]);
        }

        return $shift->fresh();
    }

    /**
     * @return array{stations: list<array<string, mixed>>, wake_ids: list<int>, pending: int}
     */
    private function buildInitial(Shift $shift): array
    {
        $computers = Computer::query()
            ->where(function ($q) {
                $q->where('kind', Computer::KIND_PC)->orWhereNull('kind');
            })
            ->whereNotNull('hwid')
            ->where('hwid', '!=', '')
            ->orderBy('id')
            ->get();

        $ids = $computers->pluck('id')->map(fn ($id) => (int) $id)->all();
        $context = $this->context($shift, $ids);
        $stations = [];
        $wakeIds = [];
        $pending = 0;
        $staleBefore = now()->subSeconds(30);

        foreach ($computers as $pc) {
            $id = (int) $pc->id;
            $session = isset($context['sessions'][$id]);
            $fresh = $pc->power_state === ComputerPowerService::STATE_ON
                && $pc->last_seen_at
                && $pc->last_seen_at->greaterThan($staleBefore);
            $keepUp = $pc->isInMaintenance() || (bool) $pc->super_client;

            if ($session || $fresh) {
                $row = $this->blank($pc);
                $row['responded'] = true;
                $row['booted'] = true;
                $row['wol_success'] = true;
                $row['source'] = $session ? 'session' : 'cache';
                $row['session_active'] = $session;
                $row = $this->applyTelemetry($row, $pc, [], $context);
                $stations[] = $this->decorate($row);

                continue;
            }

            $row = $this->blank($pc);
            $row['responded'] = false;
            $row['booted'] = false;
            $row['wol_success'] = null;
            $row['source'] = 'wake';
            $row['status'] = 'pending';
            $row['session_active'] = false;
            $mac = trim((string) $pc->mac_address);
            if ($mac === '') {
                $row['responded'] = true;
                $row['wol_success'] = false;
                $row['wol_timeout'] = true;
                $row['source'] = 'timeout';
                $row['active_incidents'] = ['wol_timeout'];
                $stations[] = $this->decorate($row);

                continue;
            }

            $pending++;
            $hold = ! $keepUp;
            if ($hold) {
                $wakeIds[] = $id;
            }
            DB::table('computers')->where('id', $id)->update([
                'power_desired' => ComputerPowerService::DESIRED_ON,
                'shift_audit_hold' => $hold,
                'updated_at' => now(),
            ]);
            $stations[] = $row;
        }

        return [
            'stations' => $stations,
            'wake_ids' => $wakeIds,
            'pending' => $pending,
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return array{sessions: array<int, true>, incidents: array<int, list<string>>, missing: array<int, list<string>>}
     */
    private function context(Shift $shift, array $ids): array
    {
        $since = $shift->started_at ?? $shift->transfer_started_at ?? now()->subHours(16);
        $sessions = [];
        $incidents = [];
        $missing = [];
        if ($ids === []) {
            return compact('sessions', 'incidents', 'missing');
        }

        Booking::query()
            ->whereIn('computer_id', $ids)
            ->where('status', 'active')
            ->pluck('computer_id')
            ->each(function ($id) use (&$sessions) {
                $sessions[(int) $id] = true;
            });

        DB::table('incidents')
            ->whereIn('computer_id', $ids)
            ->whereNull('resolved_at')
            ->where('created_at', '>=', $since)
            ->whereIn('type', ['hardware_switch_fault', 'hardware_abuse', 'nic_link_flap', 'hid_disconnected'])
            ->get(['computer_id', 'type'])
            ->each(function ($row) use (&$incidents) {
                $incidents[(int) $row->computer_id][] = (string) $row->type;
            });

        ComputerInputAlert::query()
            ->whereIn('computer_id', $ids)
            ->where('type', ComputerInputAlert::TYPE_DISCONNECTED)
            ->where('created_at', '>=', $since)
            ->get(['computer_id', 'payload'])
            ->each(function (ComputerInputAlert $alert) use (&$missing) {
                $id = (int) $alert->computer_id;
                foreach ($this->missingFromAlert($alert->payload ?? []) as $kind) {
                    $missing[$id][] = $kind;
                }
            });

        return compact('sessions', 'incidents', 'missing');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function missingFromAlert(array $payload): array
    {
        $current = $payload['current'] ?? null;
        if (! is_array($current)) {
            return ['peripheral'];
        }
        $missing = [];
        if (array_key_exists('mice', $current) && $current['mice'] === []) {
            $missing[] = 'mouse';
        }
        if (array_key_exists('keyboards', $current) && $current['keyboards'] === []) {
            $missing[] = 'keyboard';
        }
        if ($missing === []) {
            $missing[] = 'peripheral';
        }

        return $missing;
    }

    /**
     * @return array<string, mixed>
     */
    private function blank(Computer $pc): array
    {
        return [
            'pc_id' => (int) $pc->id,
            'pc_name' => (string) ($pc->name ?: ('ПК-'.$pc->id)),
            'status' => 'pending',
            'wol_success' => null,
            'responded' => false,
            'booted' => false,
            'source' => 'cache',
            'session_active' => false,
            'nic_link_mbps' => $pc->nic_link_mbps !== null ? (int) $pc->nic_link_mbps : null,
            'nic_flap_count' => (int) ($pc->nic_flap_count ?? 0),
            'cache_ok' => $this->nullableBool($pc->getRawOriginal('cache_ok')),
            'ssd_wear_pct' => $pc->ssd_wear_pct !== null ? (int) $pc->ssd_wear_pct : null,
            'ssd_health' => $pc->ssd_health,
            'missing_devices' => [],
            'active_incidents' => [],
            'badges' => [],
            'wol_timeout' => false,
            'hardware_switch_fault' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $extras
     * @param  array{sessions?: array<int, true>, incidents?: array<int, list<string>>, missing?: array<int, list<string>>}|null  $context
     * @return array<string, mixed>
     */
    private function applyTelemetry(array $row, Computer $pc, array $extras, ?array $context = null): array
    {
        $id = (int) $pc->id;
        if (array_key_exists('nic_link_mbps', $extras) && $extras['nic_link_mbps'] !== null && $extras['nic_link_mbps'] !== '') {
            $row['nic_link_mbps'] = (int) $extras['nic_link_mbps'];
        } elseif ($pc->nic_link_mbps !== null) {
            $row['nic_link_mbps'] = (int) $pc->nic_link_mbps;
        }
        $row['nic_flap_count'] = max((int) ($row['nic_flap_count'] ?? 0), (int) ($pc->nic_flap_count ?? 0));
        if (array_key_exists('cache_ok', $extras) && $extras['cache_ok'] !== null) {
            $row['cache_ok'] = filter_var($extras['cache_ok'], FILTER_VALIDATE_BOOLEAN);
        } else {
            $row['cache_ok'] = $this->nullableBool($pc->getRawOriginal('cache_ok'));
        }
        if (array_key_exists('ssd_wear_pct', $extras) && $extras['ssd_wear_pct'] !== null && $extras['ssd_wear_pct'] !== '') {
            $row['ssd_wear_pct'] = (int) $extras['ssd_wear_pct'];
        } elseif ($pc->ssd_wear_pct !== null) {
            $row['ssd_wear_pct'] = (int) $pc->ssd_wear_pct;
        }
        if (! empty($extras['ssd_health'])) {
            $row['ssd_health'] = (string) $extras['ssd_health'];
        } elseif ($pc->ssd_health) {
            $row['ssd_health'] = (string) $pc->ssd_health;
        }
        if (filter_var($extras['hardware_switch_fault'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $row['hardware_switch_fault'] = true;
        }

        $missing = $row['missing_devices'] ?? [];
        if (isset($extras['missing_devices']) && is_array($extras['missing_devices'])) {
            foreach ($extras['missing_devices'] as $kind) {
                $kind = strtolower(trim((string) $kind));
                if ($kind !== '') {
                    $missing[] = $kind;
                }
            }
        }
        if (isset($extras['hid_present']) && is_array($extras['hid_present'])) {
            foreach ($this->missingAgainstBaseline($id, $extras['hid_present']) as $kind) {
                $missing[] = $kind;
            }
        }
        if ($context && ! empty($context['missing'][$id])) {
            $missing = array_merge($missing, $context['missing'][$id]);
        }
        $row['missing_devices'] = array_values(array_unique($missing));

        $incidents = $row['active_incidents'] ?? [];
        if ($context && ! empty($context['incidents'][$id])) {
            $incidents = array_merge($incidents, $context['incidents'][$id]);
        }
        if (! empty($row['hardware_switch_fault'])) {
            $incidents[] = 'hardware_switch_fault';
        }
        if ((int) ($row['nic_flap_count'] ?? 0) >= 2) {
            $incidents[] = 'nic_link_flap';
        }
        $row['active_incidents'] = array_values(array_unique($incidents));

        return $row;
    }

    /**
     * @param  list<mixed>  $present
     * @return list<string>
     */
    private function missingAgainstBaseline(int $computerId, array $present): array
    {
        $device = ComputerInputDevice::query()->where('computer_id', $computerId)->first();
        $fp = $device?->fingerprint;
        if (! is_array($fp)) {
            return [];
        }
        $have = array_map(fn ($kind) => strtolower(trim((string) $kind)), $present);
        $missing = [];
        if (! empty($fp['mice']) && ! in_array('mouse', $have, true)) {
            $missing[] = 'mouse';
        }
        if (! empty($fp['keyboards']) && ! in_array('keyboard', $have, true)) {
            $missing[] = 'keyboard';
        }
        if (! empty($fp['headsets']) && ! in_array('headset', $have, true)) {
            $missing[] = 'headset';
        }

        return $missing;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function decorate(array $row): array
    {
        if (empty($row['responded'])) {
            $row['status'] = 'pending';
            $row['badges'] = [];

            return $row;
        }

        $incidents = array_values(array_unique($row['active_incidents'] ?? []));
        $badges = [];

        if (($row['missing_devices'] ?? []) !== []) {
            $badges[] = $this->badge('critical', 'hid.disconnected', 'Отсутствует периферия');
            $incidents[] = 'hid.disconnected';
        }
        if (! empty($row['wol_timeout'])) {
            $badges[] = $this->badge('critical', 'wol_timeout', 'Не ответил на WOL');
            $incidents[] = 'wol_timeout';
        }
        if (array_key_exists('cache_ok', $row) && $row['cache_ok'] === false) {
            $badges[] = $this->badge('critical', 'cache_dead', 'Кэш SSD мертв');
            $incidents[] = 'cache_dead';
        }

        $mbps = $row['nic_link_mbps'] ?? null;
        if (is_numeric($mbps) && (int) $mbps > 0 && (int) $mbps <= 100) {
            $badges[] = $this->badge('warning', 'nic_link_degraded', 'Линк 100 Мбит');
            $incidents[] = 'nic_link_degraded';
        }
        if ((int) ($row['nic_flap_count'] ?? 0) >= 2) {
            $badges[] = $this->badge('warning', 'nic_link_flap', 'Деградация кабеля');
            $incidents[] = 'nic_link_flap';
        }
        if (in_array('hardware_switch_fault', $incidents, true) || ! empty($row['hardware_switch_fault'])) {
            $badges[] = $this->badge('warning', 'hardware_switch_fault', 'Дребезг микрика/клавиши');
            $incidents[] = 'hardware_switch_fault';
        }
        if (in_array('hardware_abuse', $incidents, true)) {
            $badges[] = $this->badge('warning', 'hardware_abuse', 'Удар по столу');
        }

        $wear = $row['ssd_wear_pct'] ?? null;
        $health = (string) ($row['ssd_health'] ?? '');
        if ((is_numeric($wear) && (int) $wear >= $this->wearWarnPct()) || in_array($health, ['warning', 'unhealthy'], true)) {
            $badges[] = $this->badge('warning', 'ssd_wear', 'Критический износ SSD');
            $incidents[] = 'ssd_wear';
        }

        $level = 'ok';
        foreach ($badges as $badge) {
            if ($badge['level'] === 'critical') {
                $level = 'critical';
                break;
            }
            if ($badge['level'] === 'warning') {
                $level = 'warning';
            }
        }

        $row['status'] = $level;
        $row['badges'] = $this->uniqueBadges($badges);
        $row['active_incidents'] = array_values(array_unique($incidents));

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<array{type: string, description: string, severity: string}>
     */
    private function ticketsFor(array $row): array
    {
        $pc = (string) ($row['pc_name'] ?? 'ПК');
        $missing = $row['missing_devices'] ?? [];
        $map = [
            'hid.disconnected' => [
                'Отсутствует периферия на '.$pc.($missing !== [] ? ': '.implode(', ', $missing) : ''),
                'high',
            ],
            'nic_link_degraded' => ['Линк '.$pc.' деградировал до '.($row['nic_link_mbps'] ?? 100).' Мбит', 'medium'],
            'nic_link_flap' => ['Заменить патч-корд на '.$pc, 'high'],
            'hardware_switch_fault' => ['Проверить свитч/микрик на '.$pc, 'high'],
            'hardware_abuse' => ['Удар по столу на '.$pc, 'high'],
            'cache_dead' => ['Кэш SSD мёртв на '.$pc, 'high'],
            'wol_timeout' => ['Ошибка WOL / питание выключено на '.$pc, 'high'],
            'ssd_wear' => ['Критический износ SSD на '.$pc, 'medium'],
        ];

        $tickets = [];
        foreach (array_unique($row['active_incidents'] ?? []) as $code) {
            if (! isset($map[$code])) {
                continue;
            }
            $tickets[] = [
                'type' => $code,
                'description' => $map[$code][0],
                'severity' => $map[$code][1],
            ];
        }

        return $tickets;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function ensureIncident(
        int $computerId,
        string $type,
        string $description,
        string $severity,
        ?int $responsibleAdminId,
        array $payload,
    ): void {
        $open = DB::table('incidents')
            ->where('type', $type)
            ->where('computer_id', $computerId)
            ->whereNull('resolved_at')
            ->orderByDesc('id')
            ->first();

        $now = now();
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $patch = [
            'description' => $description,
            'severity' => $severity,
            'payload' => $json,
            'updated_at' => $now,
        ];
        if ($this->incidentsHaveResponsible() && $responsibleAdminId) {
            $patch['responsible_admin_id'] = $responsibleAdminId;
        }

        if ($open) {
            DB::table('incidents')->where('id', $open->id)->update($patch);

            return;
        }

        DB::table('incidents')->insert(array_merge($patch, [
            'type' => $type,
            'order_id' => null,
            'computer_id' => $computerId,
            'created_at' => $now,
        ]));
    }

    private function incidentsHaveResponsible(): bool
    {
        return DB::getSchemaBuilder()->hasColumn('incidents', 'responsible_admin_id');
    }

    /**
     * @param  list<array<string, mixed>>  $stations
     */
    private function saveStations(Shift $shift, array $stations): void
    {
        $pending = count(array_filter($stations, fn ($row) => empty($row['responded'])));
        $status = $shift->hardware_audit_status;
        if ($pending === 0 && $status === self::STATUS_IN_PROGRESS) {
            $status = self::STATUS_COMPLETED;
        }
        $shift->update([
            'hardware_snapshot' => $stations,
            'hardware_audit_status' => $status,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function stationsOf(Shift $shift): array
    {
        $raw = $shift->hardware_snapshot;

        return is_array($raw) ? array_values($raw) : [];
    }

    /**
     * @param  list<array<string, mixed>>  $stations
     */
    private function indexOf(array $stations, int $computerId): ?int
    {
        foreach ($stations as $i => $row) {
            if ((int) ($row['pc_id'] ?? 0) === $computerId) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @return array{level: string, code: string, label: string}
     */
    private function nullableBool(mixed $raw): ?bool
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    private function badge(string $level, string $code, string $label): array
    {
        return compact('level', 'code', 'label');
    }

    /**
     * @param  list<array{level: string, code: string, label: string}>  $badges
     * @return list<array{level: string, code: string, label: string}>
     */
    private function uniqueBadges(array $badges): array
    {
        $seen = [];
        $out = [];
        foreach ($badges as $badge) {
            if (isset($seen[$badge['code']])) {
                continue;
            }
            $seen[$badge['code']] = true;
            $out[] = $badge;
        }

        return $out;
    }

    private function requireTransfer(Admin $incoming): Shift
    {
        $shift = AdminShift::openShift();
        if (! $shift || $shift->status !== 'transferring' || (int) $shift->incoming_admin_id !== (int) $incoming->id) {
            throw new RuntimeException('Аудит зала доступен только принимающему админу во время пересменки.');
        }

        return $shift;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyStatus(): array
    {
        return [
            'status' => null,
            'polled' => 0,
            'total' => 0,
            'seconds_left' => 0,
            'timeout_seconds' => $this->timeoutSeconds(),
            'started_at' => null,
            'counts' => ['critical' => 0, 'warning' => 0, 'ok' => 0, 'pending' => 0],
            'stations' => [],
        ];
    }
}
