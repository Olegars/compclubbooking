<?php

namespace App\Services;

use App\Models\StoreBuiltPc;
use App\Models\StoreComponent;
use App\Models\StoreWarranty;
use Illuminate\Support\Facades\DB;

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
     * @return list<array{type:string,type_label:string,name:string,warranty_number:?string,serials:list<string>}>
     */
    public function buildSnapshot(StoreBuiltPc $pc): array
    {
        $pc->loadMissing('componentLinks.component');

        if ($pc->componentLinks->isNotEmpty()) {
            return $pc->componentLinks->map(function ($link) {
                $type = (string) ($link->type ?: 'other');
                $serials = $link->component?->allSerials() ?? [];
                $label = $serials !== [] ? implode(' · ', $serials) : null;

                return [
                    'type' => $type,
                    'type_label' => StoreComponent::TYPES[$type] ?? $type,
                    'name' => (string) $link->name,
                    'warranty_number' => $label,
                    'serials' => $serials,
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
            ];
        })->filter(fn ($r) => $r['name'] !== '')->values()->all();
    }
}
