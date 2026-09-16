<?php

namespace App\Services;

use App\Models\StoreBuiltPc;
use App\Models\StoreComponent;
use App\Models\StoreWarranty;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StoreWarrantyService
{
    /** Срок гарантии (месяцев), по умолчанию 1 год. */
    public function months(): int
    {
        return max(1, (int) config('store.warranty_months', 12));
    }

    /** Срок гарантийного ремонта (дней). */
    public function repairDays(): int
    {
        return max(1, (int) config('store.repair_days', 45));
    }

    /**
     * 10-digit unique serial (digits only).
     */
    public function generateSerial(): string
    {
        for ($i = 0; $i < 40; $i++) {
            $serial = (string) random_int(1_000_000_000, 9_999_999_999);

            $takenPc = StoreBuiltPc::query()->where('serial_number', $serial)->exists();
            $takenW = StoreWarranty::query()->where('serial', $serial)->exists();
            if (! $takenPc && ! $takenW) {
                return $serial;
            }
        }

        // Extremely unlikely fallback
        return str_pad((string) (time() % 10_000_000_000), 10, '0', STR_PAD_LEFT);
    }

    /**
     * Ensure PC has a 10-digit serial and a linked active warranty with build snapshot.
     */
    public function ensureForBuiltPc(StoreBuiltPc $pc): StoreWarranty
    {
        return DB::transaction(function () use ($pc) {
            $pc->refresh();
            $pc->loadMissing(['componentLinks', 'client']);

            $serial = is_string($pc->serial_number) ? trim($pc->serial_number) : '';
            if ($serial === '') {
                $serial = $this->generateSerial();
                $pc->update(['serial_number' => $serial]);
            }

            $snapshot = $this->buildSnapshot($pc);
            $months = $this->months();
            $repairDays = $this->repairDays();
            $started = now()->startOfDay();
            $ends = $started->copy()->addMonthsNoOverflow($months);

            $warranty = StoreWarranty::query()
                ->where('store_built_pc_id', $pc->id)
                ->first();

            $payload = [
                'club_id' => $pc->club_id,
                'store_client_id' => $pc->store_client_id,
                'store_built_pc_id' => $pc->id,
                'serial' => $serial,
                'public_token' => $warranty?->public_token ?: $this->freshPublicToken(),
                'product_name' => $pc->title ?: ('Сборка ПК #'.$pc->id),
                'started_at' => $started->toDateString(),
                'ends_at' => $ends->toDateString(),
                'warranty_months' => $months,
                'repair_days' => $repairDays,
                'build_snapshot' => $snapshot,
                'status' => $warranty?->status ?? 'active',
            ];

            if ($warranty) {
                // Не затираем claim_notes / closed; обновляем снимок и серийник
                $warranty->update([
                    'store_client_id' => $payload['store_client_id'],
                    'serial' => $payload['serial'],
                    'public_token' => $warranty->public_token ?: $payload['public_token'],
                    'product_name' => $payload['product_name'],
                    'warranty_months' => $payload['warranty_months'],
                    'repair_days' => $payload['repair_days'],
                    'build_snapshot' => $payload['build_snapshot'],
                    'started_at' => $warranty->started_at ?: $payload['started_at'],
                    'ends_at' => $warranty->ends_at ?: $payload['ends_at'],
                ]);

                return $warranty->fresh(['client', 'builtPc', 'club']);
            }

            return StoreWarranty::query()->create($payload)->load(['client', 'builtPc', 'club']);
        });
    }

    /**
     * @return list<array{
     *   type:string,
     *   type_label:string,
     *   name:string,
     *   warranty_number:?string,
     *   serials:list<string>,
     *   store_component_id:?int,
     *   warranty_months:?int,
     *   received_at:?string
     * }>
     */
    public function buildSnapshot(StoreBuiltPc $pc): array
    {
        $pc->loadMissing('componentLinks.component');

        if ($pc->componentLinks->isNotEmpty()) {
            return $pc->componentLinks->map(function ($link) {
                $type = (string) ($link->type ?: 'other');
                $component = $link->component;
                $serials = $component?->allSerials() ?? [];
                $label = $serials !== [] ? implode(' · ', $serials) : null;

                return [
                    'type' => $type,
                    'type_label' => StoreComponent::TYPES[$type] ?? $type,
                    'name' => (string) $link->name,
                    'warranty_number' => $label,
                    'serials' => $serials,
                    'store_component_id' => $component?->id,
                    'warranty_months' => $component?->warranty_months !== null
                        ? (int) $component->warranty_months
                        : null,
                    'received_at' => $component?->created_at?->toIso8601String(),
                ];
            })->values()->all();
        }

        $spec = is_array($pc->build_spec) ? $pc->build_spec : [];

        return collect($spec)->map(function ($row) {
            $type = (string) ($row['type'] ?? 'other');
            $serials = [];
            if (! empty($row['serials']) && is_array($row['serials'])) {
                $serials = array_values(array_filter(array_map('strval', $row['serials'])));
            } elseif (! empty($row['warranty_number'])) {
                $serials = [(string) $row['warranty_number']];
            }

            return [
                'type' => $type,
                'type_label' => StoreComponent::TYPES[$type] ?? $type,
                'name' => (string) ($row['name'] ?? ''),
                'warranty_number' => $serials !== [] ? implode(' · ', $serials) : null,
                'serials' => $serials,
                'store_component_id' => isset($row['store_component_id']) ? (int) $row['store_component_id'] : null,
                'warranty_months' => isset($row['warranty_months']) ? (int) $row['warranty_months'] : null,
                'received_at' => $row['received_at'] ?? null,
            ];
        })->filter(fn ($r) => $r['name'] !== '')->values()->all();
    }

    public function ensurePublicToken(StoreWarranty $warranty): string
    {
        $existing = trim((string) ($warranty->public_token ?? ''));
        if ($existing !== '') {
            return $existing;
        }

        $token = $this->freshPublicToken();
        $warranty->update(['public_token' => $token]);

        return $token;
    }

    public function freshPublicToken(): string
    {
        for ($i = 0; $i < 20; $i++) {
            $token = Str::lower(Str::random(32));
            if (! StoreWarranty::query()->where('public_token', $token)->exists()) {
                return $token;
            }
        }

        return Str::lower(Str::random(32)).dechex(time() % 0xFFFF);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public function enrichBuildItems(array $items, ?StoreBuiltPc $pc): array
    {
        $byId = [];
        $bySerial = [];
        if ($pc) {
            foreach ($pc->componentLinks as $link) {
                $component = $link->component;
                if (! $component) {
                    continue;
                }
                $byId[(int) $component->id] = $component;
                foreach ($component->allSerials() as $serial) {
                    $key = mb_strtolower(trim($serial));
                    if ($key !== '') {
                        $bySerial[$key] = $component;
                    }
                }
            }
        }

        $missingIds = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $cid = isset($item['store_component_id']) ? (int) $item['store_component_id'] : 0;
            if ($cid > 0 && ! isset($byId[$cid])) {
                $missingIds[] = $cid;
            }
        }
        if ($missingIds !== []) {
            StoreComponent::query()
                ->whereIn('id', array_values(array_unique($missingIds)))
                ->get()
                ->each(function (StoreComponent $component) use (&$byId, &$bySerial) {
                    $byId[(int) $component->id] = $component;
                    foreach ($component->allSerials() as $serial) {
                        $key = mb_strtolower(trim($serial));
                        if ($key !== '') {
                            $bySerial[$key] = $component;
                        }
                    }
                });
        }

        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $component = null;
            $cid = isset($item['store_component_id']) ? (int) $item['store_component_id'] : 0;
            if ($cid > 0 && isset($byId[$cid])) {
                $component = $byId[$cid];
            }
            if (! $component && ! empty($item['serials']) && is_array($item['serials'])) {
                foreach ($item['serials'] as $serial) {
                    $key = mb_strtolower(trim((string) $serial));
                    if ($key !== '' && isset($bySerial[$key])) {
                        $component = $bySerial[$key];
                        break;
                    }
                }
            }
            if (! $component && ! empty($item['warranty_number'])) {
                foreach (preg_split('/\s*·\s*/u', (string) $item['warranty_number']) ?: [] as $serial) {
                    $key = mb_strtolower(trim($serial));
                    if ($key !== '' && isset($bySerial[$key])) {
                        $component = $bySerial[$key];
                        break;
                    }
                }
            }

            $months = $component?->warranty_months;
            if ($months === null && array_key_exists('warranty_months', $item) && $item['warranty_months'] !== null) {
                $months = (int) $item['warranty_months'];
            }
            $receivedAt = $component?->created_at;
            if (! $receivedAt && ! empty($item['received_at'])) {
                try {
                    $receivedAt = Carbon::parse($item['received_at']);
                } catch (\Throwable) {
                    $receivedAt = null;
                }
            }

            // 0 у поставщика = 12 мес. (как при приёмке на склад)
            if ($months !== null && (int) $months === 0) {
                $months = 12;
            }

            $partWarranty = $this->remainingFromReceipt($receivedAt, $months !== null ? (int) $months : null);
            $status = $component?->status;
            $sentAt = $component?->sent_to_repair_at;

            $out[] = [
                'type' => $item['type'] ?? 'other',
                'type_label' => $item['type_label'] ?? ($item['type'] ?? '—'),
                'name' => $item['name'] ?? '',
                'warranty_number' => $item['warranty_number'] ?? null,
                'serials' => is_array($item['serials'] ?? null) ? $item['serials'] : [],
                'store_component_id' => $component?->id ?? ($cid > 0 ? $cid : null),
                'component_status' => $status,
                'sent_to_repair_at' => $sentAt?->toIso8601String(),
                'sent_to_repair_label' => $sentAt
                    ? 'передана в ремонт '.$sentAt->format('d.m.Y H:i')
                    : null,
                'replaces_component_id' => $component?->replaces_component_id,
                'replaced_by_component_id' => $component?->replaced_by_component_id,
                'can_send_to_repair' => $component
                    && ! in_array($status, ['repair', 'written_off', 'in_stock'], true),
                'can_return_from_repair' => $component && $status === 'repair',
                'can_replace' => $component && $status === 'repair',
                'warranty_months' => $months !== null ? (int) $months : null,
                'received_at' => $receivedAt?->toIso8601String(),
                'warranty_days_left' => $partWarranty['days_left'],
                'warranty_state' => $partWarranty['state'],
                'warranty_label' => $partWarranty['label'],
                'warranty_badge' => $partWarranty['badge'],
            ];
        }

        return $out;
    }

    /**
     * Остаток гарантии комплектующей от даты поступления + warranty_months.
     *
     * @return array{state:string,label:?string,days_left:?int,badge:?int}
     */
    public function remainingFromReceipt(mixed $receivedAt, ?int $months): array
    {
        if (! $receivedAt || ! $months || $months <= 0) {
            return ['state' => 'none', 'label' => null, 'days_left' => null, 'badge' => null];
        }

        $start = $receivedAt instanceof Carbon
            ? $receivedAt->copy()->startOfDay()
            : Carbon::parse($receivedAt)->startOfDay();
        $ends = $start->copy()->addMonthsNoOverflow($months)->startOfDay();
        $base = $this->remainingWarranty($ends);
        $today = now()->startOfDay();
        $daysLeft = $ends->lt($today)
            ? -((int) $ends->diffInDays($today))
            : (int) $today->diffInDays($ends);

        return [
            'state' => $base['state'],
            'label' => $base['label'],
            'days_left' => $daysLeft,
            'badge' => max(0, $daysLeft),
        ];
    }

    /**
     * @return array{state:string,label:?string}
     */
    public function remainingWarranty(mixed $endsAt): array
    {
        if (! $endsAt) {
            return ['state' => 'none', 'label' => null];
        }

        $ends = $endsAt instanceof Carbon
            ? $endsAt->copy()->startOfDay()
            : Carbon::parse($endsAt)->startOfDay();
        $today = now()->startOfDay();

        if ($ends->lt($today)) {
            $ago = (int) $ends->diffInDays($today);

            return [
                'state' => 'expired',
                'label' => $ago === 0
                    ? 'Гарантия истекла сегодня'
                    : 'Гарантия истекла '.$this->daysRu($ago).' назад',
            ];
        }

        $days = (int) $today->diffInDays($ends);
        if ($days === 0) {
            return ['state' => 'expiring', 'label' => 'Гарантия истекает сегодня'];
        }

        return [
            'state' => $days <= 30 ? 'expiring' : 'active',
            'label' => 'Гарантия истекает через '.$this->daysRu($days),
        ];
    }

    public function daysRu(int $n): string
    {
        $n = abs($n);
        $mod10 = $n % 10;
        $mod100 = $n % 100;
        if ($mod10 === 1 && $mod100 !== 11) {
            return $n.' день';
        }
        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            return $n.' дня';
        }

        return $n.' дней';
    }
}
