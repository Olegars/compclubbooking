<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
        'club_id', 'store_order_id', 'store_client_id', 'assembled_by', 'accepted_by', 'issued_by',
        'title', 'build_spec', 'serial_number', 'sale_price', 'sale_tax_mode',
        'sold_at', 'status', 'notes', 'verified_at', 'verified_ok', 'verified_hostname',
        'assembly_started_at', 'assembly_finished_at', 'nvr_channel',
        'assembly_clip_path', 'assembly_clip_bytes', 'assembly_clip_uploaded_at',
    ];

    protected $casts = [
        'build_spec' => 'array',
        'sale_price' => 'decimal:2',
        'sold_at' => 'datetime',
        'verified_at' => 'datetime',
        'verified_ok' => 'boolean',
        'assembly_started_at' => 'datetime',
        'assembly_finished_at' => 'datetime',
        'assembly_clip_uploaded_at' => 'datetime',
        'assembly_clip_bytes' => 'integer',
    ];

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(StoreOrder::class, 'store_order_id');
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

    public function warranty(): HasOne
    {
        return $this->hasOne(StoreWarranty::class, 'store_built_pc_id');
    }

    public function components(): BelongsToMany
    {
        return $this->belongsToMany(StoreComponent::class, 'store_built_pc_components')
            ->withPivot(['type', 'name'])
            ->withTimestamps();
    }

    public function hasAssemblyClip(): bool
    {
        return filled($this->assembly_clip_path);
    }
}
