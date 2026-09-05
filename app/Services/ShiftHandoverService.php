<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Shift;
use App\Models\ShiftIntern;
use App\Models\ShiftInventory;
use App\Support\AdminShift;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ShiftHandoverService
{
    public function __construct(
        private readonly ProductStockService $stock,
        private readonly StaffPayrollService $payroll,
    ) {
    }

    /**
     * @param  array{verified?:bool, camera?:string, face_detected?:bool}  $presence
     */
    public function begin(Admin $incoming, array $presence = []): Shift
    {
        $this->assertIncomingAdmin($incoming);

        if (! ($presence['verified'] ?? false)) {
            throw new RuntimeException('Посмотрите в камеру на ресепшене — система должна увидеть, что вы пришли.');
        }

        return DB::transaction(function () use ($incoming, $presence) {
            $open = AdminShift::openShift();

            if ($open && $open->status === 'transferring') {
                if ((int) $open->incoming_admin_id === (int) $incoming->id) {
                    $this->seedInventory($open);

                    return $open->fresh(['admin', 'incomingAdmin', 'inventoryItems']);
                }

                throw new RuntimeException('Смену уже принимает '.$open->incomingAdmin?->name.'.');
            }

            if ($open && (int) $open->admin_id === (int) $incoming->id) {
                throw new RuntimeException('Эта смена уже ваша.');
            }

            $meta = [
                'stub' => true,
                'camera' => (string) ($presence['camera'] ?? 'reception'),
                'face_detected' => (bool) ($presence['face_detected'] ?? false),
                'note' => 'Присутствие на ресепшене подтверждено камерой.',
            ];

            if (! $open) {
                $open = Shift::create([
                    'admin_id' => null,
                    'incoming_admin_id' => $incoming->id,
                    'status' => 'transferring',
                    'started_at' => now(),
                    'transfer_started_at' => now(),
                    'presence_verified_at' => now(),
                    'presence_meta' => $meta,
                    'cash_start' => 0,
                ]);
            } else {
                $open->update([
                    'status' => 'transferring',
                    'incoming_admin_id' => $incoming->id,
                    'transfer_started_at' => now(),
                    'presence_verified_at' => now(),
                    'presence_meta' => $meta,
                ]);
            }

            $this->seedInventory($open);

            return $open->fresh(['admin', 'incomingAdmin', 'inventoryItems']);
        });
    }

    public function findProductByCode(string $raw): Product
    {
        $code = ProductUnit::normalizeCode($raw);
        if ($code === '') {
            throw new RuntimeException('Пустой код.');
        }

        $gtin = ProductUnit::extractGtin($code);

        $product = Product::query()->where('barcode', $code)->first();
        if (! $product && $gtin) {
            $product = Product::query()
                ->where(function ($q) use ($gtin) {
                    $q->where('barcode', $gtin)
                        ->orWhere('barcode', ltrim($gtin, '0'));
                })
                ->first();
        }

        if (! $product) {
            $unit = ProductUnit::query()->where('marking_code', $code)->first();
            $product = $unit?->product;
        }

        if (! $product) {
            throw new RuntimeException('Товар не найден. Код отсутствует в базе.');
        }

        return $product;
    }

    /**
     * @return array<string, mixed>
     */
    public function lookup(Admin $incoming, string $code): array
    {
        $this->requireIncomingTransfer($incoming);
        $product = $this->findProductByCode($code);
        $payload = $this->pagePayload($incoming);
        $item = collect($payload['products'])->firstWhere('id', (int) $product->id);

        if (! $item) {
            $this->seedInventory($this->requireIncomingTransfer($incoming));
            $payload = $this->pagePayload($incoming);
            $item = collect($payload['products'])->firstWhere('id', (int) $product->id);
        }

        if (! $item) {
            throw new RuntimeException('Товар не входит в пересчёт смены.');
        }

        return $item;
    }

    public function recordCount(Admin $incoming, int $productId, int $qty): ShiftInventory
    {
        if ($qty < 0) {
            throw new RuntimeException('Количество не может быть отрицательным.');
        }

        $shift = $this->requireIncomingTransfer($incoming);
        $product = Product::query()->find($productId);
        if (! $product) {
            throw new RuntimeException('Товар не найден.');
        }

        $row = ShiftInventory::query()->firstOrCreate(
            [
                'shift_id' => $shift->id,
                'product_id' => $product->id,
            ],
            [
                'expected_stock' => (int) $product->stock,
                'actual_stock' => null,
            ]
        );

        $row->update(['actual_stock' => $qty]);

        return $row->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function complete(Admin $incoming, float $cashCounted): Shift
    {
        $this->assertIncomingAdmin($incoming);

        return DB::transaction(function () use ($incoming, $cashCounted) {
            $current = $this->requireIncomingTransfer($incoming);
            $payload = $this->pagePayload($incoming);

            if (! ($payload['can_complete'] ?? false)) {
                throw new RuntimeException('Сначала отсканируйте и посчитайте все товары с остатком.');
            }

            $outgoing = $current->admin;
            $isFirstShift = $outgoing === null;

            if ($isFirstShift) {
                $current->update([
                    'admin_id' => $incoming->id,
                    'status' => 'open',
                    'started_at' => now(),
                    'cash_start' => $cashCounted,
                    'incoming_admin_id' => null,
                    'transfer_started_at' => null,
                ]);
                $newShift = $current->fresh();
            } else {
                $closingIds = Shift::query()->where('status', '!=', 'closed')->pluck('id');

                Shift::query()->where('status', '!=', 'closed')->update([
                    'status' => 'closed',
                    'closed_by' => $incoming->id,
                    'ended_at' => now(),
                    'cash_end' => $cashCounted,
                ]);

                ShiftIntern::query()
                    ->whereIn('shift_id', $closingIds)
                    ->whereNull('left_at')
                    ->update(['left_at' => now()]);

                Shift::query()
                    ->whereIn('id', $closingIds)
                    ->with(['admin', 'internSlots.admin'])
                    ->get()
                    ->each(function (Shift $closed) {
                        $this->payroll->accrueClosedShift($closed);
                        $this->payroll->accrueClosedShiftInterns($closed);
                    });

                $newShift = Shift::create([
                    'admin_id' => $incoming->id,
                    'status' => 'open',
                    'started_at' => now(),
                    'cash_start' => $cashCounted,
                ]);
            }

            $shortageLines = [];

            foreach ($payload['products'] as $item) {
                $expected = (int) $item['stock'];
                $actual = $item['counted'] ? (int) $item['actual'] : 0;

                if ($isFirstShift) {
                    ShiftInventory::query()->updateOrCreate(
                        [
                            'shift_id' => $newShift->id,
                            'product_id' => $item['id'],
                        ],
                        [
                            'expected_stock' => $expected,
                            'actual_stock' => $actual,
                        ]
                    );
                } else {
                    DB::table('shift_inventory')->insert([
                        'shift_id' => $newShift->id,
                        'product_id' => $item['id'],
                        'expected_stock' => $expected,
                        'actual_stock' => $actual,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                if ($actual === $expected) {
                    continue;
                }

                $product = Product::query()->find($item['id']);
                if (! $product) {
                    continue;
                }

                if (! $product->requires_marking) {
                    $this->stock->applyShiftAdjustment(
                        $product,
                        $expected,
                        $actual,
                        (int) $incoming->id,
                        (int) $newShift->id,
                        'Пересчёт при приёме смены'
                    );
                }

                if ($actual < $expected && $outgoing) {
                    $shortageLines[] = [
                        'name' => (string) $item['name'],
                        'expected' => $expected,
                        'actual' => $actual,
                        'cost' => (float) ($item['cost_price'] ?? 0),
                        'price' => (float) ($item['price'] ?? 0),
                    ];
                }

                DB::table('incidents')->insert([
                    'type' => 'inventory_discrepancy',
                    'severity' => 'medium',
                    'description' => "Пересменка «{$item['name']}»: ожидалось {$expected}, факт {$actual}. Принял: {$incoming->name}",
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if ($outgoing) {
                $this->payroll->chargeHandoverShortage($outgoing, $incoming, $current->fresh(), $shortageLines);
                $outgoing->forceFill(['shift_handed_over_at' => now()])->save();
            }

            return $newShift;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function pagePayload(?Admin $viewer): array
    {
        $shift = AdminShift::openShift();
        $products = $this->productRows($shift);

        $required = array_values(array_filter($products, fn ($row) => (int) $row['stock'] > 0));
        $allRequiredCounted = ! array_filter($required, fn ($row) => ! $row['counted']);
        $discrepancies = array_values(array_filter(
            $products,
            fn ($row) => $row['counted'] && (int) $row['actual'] !== (int) $row['stock']
        ));

        $isIncoming = $shift && $viewer && (int) $shift->incoming_admin_id === (int) $viewer->id;
        $phase = ($shift && $shift->status === 'transferring' && $isIncoming) ? 'counting' : 'presence';

        return [
            'phase' => $phase,
            'shift_id' => $shift?->id,
            'status' => $shift?->status,
            'outgoing_name' => $shift?->admin?->name,
            'incoming_name' => $shift?->incomingAdmin?->name,
            'expected_cash' => (float) ($shift?->cash_start ?? 0),
            'products' => $products,
            'required_total' => count($required),
            'counted_required' => count($required) - count(array_filter($required, fn ($row) => ! $row['counted'])),
            'all_required_counted' => $allRequiredCounted,
            'can_complete' => $phase === 'counting' && $allRequiredCounted,
            'discrepancies' => $discrepancies,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function statusPayload(?Admin $viewer): array
    {
        $shift = AdminShift::current($viewer?->id);

        return [
            'admin_shift' => $shift,
            'handover' => $this->pagePayload($viewer),
        ];
    }

    private function seedInventory(Shift $shift): void
    {
        $products = Product::query()->get(['id', 'stock']);
        foreach ($products as $product) {
            ShiftInventory::query()->firstOrCreate(
                [
                    'shift_id' => $shift->id,
                    'product_id' => $product->id,
                ],
                [
                    'expected_stock' => (int) $product->stock,
                    'actual_stock' => null,
                ]
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function productRows(?Shift $shift): array
    {
        $counts = $shift
            ? $shift->inventoryItems->keyBy('product_id')
            : collect();

        return Product::query()
            ->orderBy('name')
            ->get(['id', 'name', 'category', 'barcode', 'stock', 'requires_marking', 'cost_price', 'price'])
            ->map(function (Product $product) use ($counts, $shift) {
                $row = $counts->get($product->id);
                $expected = $row ? (int) $row->expected_stock : (int) $product->stock;
                $counted = $row && $row->actual_stock !== null;

                return [
                    'id' => (int) $product->id,
                    'name' => $product->name,
                    'category' => $product->category,
                    'barcode' => $product->barcode,
                    'stock' => $shift && $shift->status === 'transferring' ? $expected : (int) $product->stock,
                    'actual' => $counted ? (int) $row->actual_stock : null,
                    'counted' => $counted,
                    'requires_marking' => (bool) $product->requires_marking,
                    'cost_price' => (float) ($product->cost_price ?? 0),
                    'price' => (float) ($product->price ?? 0),
                ];
            })
            ->values()
            ->all();
    }

    private function requireIncomingTransfer(Admin $incoming): Shift
    {
        $shift = AdminShift::openShift();
        if (! $shift || $shift->status !== 'transferring') {
            throw new RuntimeException('Сначала подтвердите присутствие у камеры на ресепшене.');
        }
        if ((int) $shift->incoming_admin_id !== (int) $incoming->id) {
            throw new RuntimeException('Приём смены уже ведёт другой админ.');
        }

        $shift->loadMissing(['admin', 'incomingAdmin', 'inventoryItems']);

        return $shift;
    }

    private function assertIncomingAdmin(Admin $incoming): void
    {
        if ($incoming->isIntern()) {
            throw new RuntimeException('Стажёр не принимает смену. Выйдите в смену вместе с активным админом.');
        }

        if ($incoming->role !== Admin::ROLE_ADMIN) {
            throw new RuntimeException('Смену принимает админ зала.');
        }

        if ($incoming->needsEmployment()) {
            throw new RuntimeException('Сначала завершите устройство на работу.');
        }
    }
}
