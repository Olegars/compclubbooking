<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreAvitoConfig extends Model
{
    protected $fillable = [
        'name', 'cpu_part_id', 'ram_part_id', 'ssd_part_id', 'psu_part_id',
        'socket', 'ddr', 'sort_order', 'use_count', 'enabled', 'last_used_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'last_used_at' => 'datetime',
    ];

    public function cpu(): BelongsTo
    {
        return $this->belongsTo(StoreAvitoPart::class, 'cpu_part_id');
    }

    public function ram(): BelongsTo
    {
        return $this->belongsTo(StoreAvitoPart::class, 'ram_part_id');
    }

    public function ssd(): BelongsTo
    {
        return $this->belongsTo(StoreAvitoPart::class, 'ssd_part_id');
    }

    public function psu(): BelongsTo
    {
        return $this->belongsTo(StoreAvitoPart::class, 'psu_part_id');
    }

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    public static function makeName(StoreAvitoPart $cpu, StoreAvitoPart $ram, StoreAvitoPart $ssd, StoreAvitoPart $psu): string
    {
        return $cpu->avito_code ?: $cpu->label
            .' · '.$ram->label
            .' · '.$ssd->label
            .' · '.$psu->label;
    }
}
