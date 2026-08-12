<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreBuiltPc extends Model
{
    public const TAX_MODES = [
        'with_tax' => 'С налогами',
        'without_tax' => 'Без налогов',
    ];

    public const STATUSES = [
        'assembling' => 'Сборка',
        'ready' => 'Готов',
        'sold' => 'Продан',
        'cancelled' => 'Отменён',
    ];

    protected $fillable = [
        'club_id', 'store_client_id', 'assembled_by', 'accepted_by', 'issued_by',
        'title', 'build_spec', 'serial_number', 'sale_price', 'sale_tax_mode',
        'sold_at', 'status', 'notes',
    ];

    protected $casts = [
        'build_spec' => 'array',
        'sale_price' => 'decimal:2',
        'sold_at' => 'datetime',
    ];

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(StoreClient::class, 'store_client_id');
    }

    public function assembler(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'assembled_by');
    }

    public function acceptor(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'accepted_by');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'issued_by');
    }

    public function componentLinks(): HasMany
    {
        return $this->hasMany(StoreBuiltPcComponent::class);
    }

    public function components(): BelongsToMany
    {
        return $this->belongsToMany(StoreComponent::class, 'store_built_pc_components')
            ->withPivot(['type', 'name'])
            ->withTimestamps();
    }
}
