<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_PENDING = 'pending';
    public const STATUS_COOKING = 'cooking';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_CANCELLED = 'cancelled';

    // Разрешаем запись этих полей
    protected $fillable = [
        'user_id',
        'booking_id',
        'product_name',
        'items',
        'price',
        'pc_name',
        'channel',
        'status',
        'fulfill_at',
        'released_at',
        'session_starts_at',
    ];

    protected $casts = [
        'items' => 'array',
        'price' => 'float',
        'fulfill_at' => 'datetime',
        'released_at' => 'datetime',
        'session_starts_at' => 'datetime',
    ];

    // Связь с пользователем (чтобы админ видел, кто заказал)
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Normalize items JSON (or legacy single product_name) for UI / shell.
     *
     * @return array<int, array{product_id:?int, name:string, qty:int, unit_price:float, line_total:float}>
     */
    public function lineItems(): array
    {
        return self::normalizeItems($this->items, $this->product_name, (float) $this->price);
    }

    /**
     * @param  mixed  $items
     * @return array<int, array{product_id:?int, name:string, qty:int, unit_price:float, line_total:float}>
     */
    public static function normalizeItems($items, ?string $productNameFallback = null, float $priceFallback = 0.0): array
    {
        if (is_string($items)) {
            $decoded = json_decode($items, true);
            $items = is_array($decoded) ? $decoded : null;
        }

        if (is_array($items) && count($items) > 0) {
            $out = [];
            foreach ($items as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $name = (string) ($row['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $qty = max(1, (int) ($row['qty'] ?? 1));
                $unit = (float) ($row['unit_price'] ?? $row['price'] ?? 0);
                $line = (float) ($row['line_total'] ?? ($unit * $qty));
                $out[] = [
                    'product_id' => isset($row['product_id']) ? (int) $row['product_id'] : null,
                    'name' => $name,
                    'qty' => $qty,
                    'unit_price' => $unit,
                    'line_total' => $line,
                ];
            }
            if (count($out) > 0) {
                return $out;
            }
        }

        if ($productNameFallback) {
            return [[
                'product_id' => null,
                'name' => $productNameFallback,
                'qty' => 1,
                'unit_price' => $priceFallback,
                'line_total' => $priceFallback,
            ]];
        }

        return [];
    }

    /** Human-readable summary: "Adrenaline ×2, Lays ×1" */
    public static function summaryFromItems(array $items): string
    {
        return collect($items)
            ->map(function ($row) {
                $name = $row['name'] ?? '';
                $qty = (int) ($row['qty'] ?? 1);
                return $qty > 1 ? "{$name} ×{$qty}" : $name;
            })
            ->filter()
            ->implode(', ');
    }
}
