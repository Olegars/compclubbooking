<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'category',
        'price',
        'cost_price',
        'stock',
        'min_stock',
        'image',
        'is_active',
        'barcode',
        'requires_marking',
        'supplier_id',
    ];

    protected $casts = [
        'price' => 'float',
        'cost_price' => 'float',
        'stock' => 'integer',
        'min_stock' => 'integer',
        'is_active' => 'boolean',
        'requires_marking' => 'boolean',
    ];

    public const DEFAULT_IMAGE = 'images/shop/default.png';

    public function units(): HasMany
    {
        return $this->hasMany(ProductUnit::class);
    }

    public function supplier(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function availableUnits(): HasMany
    {
        return $this->units()->where('status', ProductUnit::STATUS_AVAILABLE);
    }

    public function publicImageUrl(): string
    {
        $image = trim((string) ($this->image ?? ''));
        if ($image === '') {
            $image = self::DEFAULT_IMAGE;
        }
        if (preg_match('#^https?://#i', $image) === 1) {
            return $image;
        }

        return url('/'.ltrim($image, '/'));
    }
}
