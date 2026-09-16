<?php

namespace App\Services;

use App\Models\Computer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Инциденты с шелла: износ подшипника SpaceFan, drift игрового диска, патч-корд,
 * неисправный свитч/микрик мыши или клавиатуры.
 * Пишет в общую таблицу incidents — лента /admin/incidents.
 */
class ShellIncidentService
{
    public const TYPE_FAN_BEARING = 'fan_bearing_wear';

    public const TYPE_GOLDEN_IMAGE = 'golden_image_drift';

    public const TYPE_NIC_LINK_FLAP = 'nic_link_flap';

    public const TYPE_HARDWARE_SWITCH = 'hardware_switch_fault';

    public const TYPES = [
        self::TYPE_FAN_BEARING,
        self::TYPE_GOLDEN_IMAGE,
        self::TYPE_NIC_LINK_FLAP,
        self::TYPE_HARDWARE_SWITCH,
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array{id: int, created: bool, description: string}
     */
    public function record(
        Computer $computer,
        string $type,
        string $description,
        string $severity = 'medium',
        array $payload = [],
    ): array {
        $type = strtolower(trim($type));
        if (! in_array($type, self::TYPES, true)) {
            $type = self::TYPE_GOLDEN_IMAGE;
        }

        $severity = match (strtolower(trim($severity))) {
            'critical', 'high' => 'high',
            'low', 'info' => 'low',
            default => 'medium',
        };

        $description = trim($description);
        if ($description === '') {
            $description = $this->defaultDescription($computer, $type);
        }

        $open = DB::table('incidents')
            ->where('type', $type)
            ->where('computer_id', $computer->id)
            ->whereNull('resolved_at')
            ->orderByDesc('id')
            ->first();

        $now = now();
        $payloadJson = $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_UNICODE);

        if ($open) {
            DB::table('incidents')->where('id', $open->id)->update([
                'description' => $description,
                'severity' => $severity,
                'payload' => $payloadJson,
                'updated_at' => $now,
            ]);

            Log::info('[SHELL-INCIDENT] refreshed', [
                'id' => $open->id,
                'type' => $type,
                'computer_id' => $computer->id,
            ]);

            return [
                'id' => (int) $open->id,
                'created' => false,
                'description' => $description,
            ];
        }

        $id = DB::table('incidents')->insertGetId([
            'type' => $type,
            'description' => $description,
            'severity' => $severity,
            'order_id' => null,
            'computer_id' => $computer->id,
            'payload' => $payloadJson,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Log::warning('[SHELL-INCIDENT] created', [
            'id' => $id,
            'type' => $type,
            'computer_id' => $computer->id,
            'pc' => $computer->name,
        ]);

        return [
            'id' => (int) $id,
            'created' => true,
            'description' => $description,
        ];
    }

    public function defaultDescription(Computer $computer, string $type): string
    {
        $pc = $computer->name ?: ('ПК-'.$computer->id);

        return match ($type) {
            self::TYPE_FAN_BEARING => "Подшипник SpaceFan на {$pc} изношен, требуется смазка",
            self::TYPE_GOLDEN_IMAGE => "На {$pc} повреждены файлы игрового диска — нужен тихий re-sync",
            self::TYPE_NIC_LINK_FLAP => "Заменить патч-корд на {$pc}",
            self::TYPE_HARDWARE_SWITCH => "Проверить свитч/микрик на {$pc}",
            default => "Инцидент на {$pc}",
        };
    }
}
