<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreBuiltPcComponent extends Model
{
    protected $fillable = [
        'store_built_pc_id', 'store_component_id', 'type', 'name',
    ];

    public function builtPc(): BelongsTo
    {
        return $this->belongsTo(StoreBuiltPc::class, 'store_built_pc_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(StoreComponent::class, 'store_component_id');
    }
}
