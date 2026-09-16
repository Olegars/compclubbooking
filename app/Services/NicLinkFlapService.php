<?php

namespace App\Services;

use App\Models\Computer;
use App\Support\AdminShift;
use App\Support\SqlTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Деградация кабеля: шелл шлёт nic_flap_events в heartbeat.
 * ≥2 падения 1 Гбит→100 Мбит за смену → инцидент «Заменить патч-корд».
 */
class NicLinkFlapService
{
    public const THRESHOLD = 2;

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array{acked: int, incident_id: int|null, count: int}
     */
    public function ingest(Computer $computer, int $events, ?array $payload = null): array
    {
        $events = max(0, $events);
        $features = app(ClubFeatureService::class);
        if (! $features->enabledForComputer($computer, 'link_flap')) {
            return [
                'acked' => $events,
                'incident_id' => null,
                'count' => (int) ($computer->nic_flap_count ?? 0),
            ];
        }
        if ($events === 0) {
            return [
                'acked' => 0,
                'incident_id' => null,
                'count' => (int) ($computer->nic_flap_count ?? 0),
            ];
        }

        $shift = AdminShift::openShift();
        $shiftId = $shift?->id;

        $count = (int) ($computer->nic_flap_count ?? 0);
        $storedShift = $computer->nic_flap_shift_id ? (int) $computer->nic_flap_shift_id : null;
        if ($shiftId && $storedShift !== (int) $shiftId) {
            $count = 0;
            $storedShift = (int) $shiftId;
        } elseif (! $shiftId && $storedShift) {
            // Нет открытой смены — окно 12 часов от первого flap.
            $last = $computer->nic_flap_last_at;
            if ($last && $last->lt(now()->subHours(12))) {
                $count = 0;
                $storedShift = null;
            }
        }

        $count += $events;

        DB::table('computers')->where('id', $computer->id)->update([
            'nic_flap_count' => $count,
            'nic_flap_shift_id' => $storedShift,
            'nic_flap_last_at' => SqlTime::now(),
            'updated_at' => SqlTime::now(),
        ]);
        $computer->nic_flap_count = $count;
        $computer->nic_flap_shift_id = $storedShift;

        $incidentId = null;
        $threshold = max(1, $features->int(
            $features->clubIdForComputer($computer),
            'link_flap',
            'threshold',
            self::THRESHOLD
        ));
        if ($count >= $threshold) {
            $pc = $computer->name ?: ('ПК-'.$computer->id);
            $desc = "Заменить патч-корд на {$pc}";
            $recorded = app(ShellIncidentService::class)->record(
                $computer,
                ShellIncidentService::TYPE_NIC_LINK_FLAP,
                $desc,
                'high',
                array_merge(is_array($payload) ? $payload : [], [
                    'flap_count' => $count,
                    'shift_id' => $storedShift,
                    'threshold' => self::THRESHOLD,
                ]),
            );
            $incidentId = $recorded['id'];
            Log::warning('[NIC-FLAP] incident', [
                'computer_id' => $computer->id,
                'pc' => $pc,
                'count' => $count,
                'incident_id' => $incidentId,
            ]);
        }

        return [
            'acked' => $events,
            'incident_id' => $incidentId,
            'count' => $count,
        ];
    }
}
