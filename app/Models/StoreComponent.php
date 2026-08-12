<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class StoreComponent extends Model
{
    /** Основные типы комплектующих (хардкод для селекта). */
    public const TYPES = [
        'cpu' => 'Процессор',
        'motherboard' => 'Материнская плата',
        'ram' => 'ОЗУ (RAM)',
        'gpu' => 'Видеокарта',
        'storage_ssd' => 'SSD',
        'storage_hdd' => 'HDD',
        'psu' => 'Блок питания',
        'case' => 'Корпус',
        'cooler' => 'Охлаждение CPU',
        'fan' => 'Вентилятор',
        'network' => 'Сеть / Wi‑Fi',
        'os' => 'ОС / лицензия',
        'other' => 'Прочее',
    ];

    public const STATUSES = [
        'in_stock' => 'На складе',
        'reserved' => 'Резерв',
        'used' => 'В сборке / использовано',
        'sold' => 'Продано',
        'written_off' => 'Списано',
    ];

    protected $fillable = [
        'club_id', 'store_supplier_id', 'received_by', 'name', 'barcode', 'type', 'specs',
        'purchase_price', 'warranty_number', 'serials', 'warranty_months', 'qty',
        'status', 'notes',
    ];

    protected $casts = [
        'purchase_price' => 'decimal:2',
        'specs' => 'array',
        'serials' => 'array',
    ];

    /** Все серийники комплекта (или один из warranty_number). */
    public function allSerials(): array
    {
        $fromJson = collect(is_array($this->serials) ? $this->serials : [])
            ->map(fn ($s) => trim((string) $s))
            ->filter()
            ->values()
            ->all();

        if ($fromJson !== []) {
            return $fromJson;
        }

        $single = trim((string) ($this->warranty_number ?: $this->barcode ?: ''));

        return $single !== '' ? [$single] : [];
    }

    public function serialsLabel(): string
    {
        $list = $this->allSerials();

        return $list === [] ? '' : implode(' · ', $list);
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(StoreSupplier::class, 'store_supplier_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'received_by');
    }

    public function builtPcs(): BelongsToMany
    {
        return $this->belongsToMany(StoreBuiltPc::class, 'store_built_pc_components')
            ->withPivot(['type', 'name'])
            ->withTimestamps();
    }

    public static function typeLabel(string $type): string
    {
        return self::TYPES[$type] ?? $type;
    }
}
