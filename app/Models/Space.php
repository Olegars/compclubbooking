<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Конкретная комната на карте клуба.
 * Тип (сингл / дуо / …) задаёт Zone и базовую цену.
 * surcharge_per_hour > 0 — комната «+»: своя доплата и метка на карте.
 */
class Space extends Model
{
    protected $fillable = [
        'club_id',
        'zone_id',
        'name',
        'x',
        'y',
        'w',
        'h',
        'surcharge_per_hour',
        'cpu',
        'gpu',
        'monitor',
        'screen_diagonal',
        'ps_model',
        'info_edge',
        'sort',
    ];

    protected $casts = [
        'x' => 'float',
        'y' => 'float',
        'w' => 'float',
        'h' => 'float',
        'surcharge_per_hour' => 'decimal:2',
        'sort' => 'integer',
    ];

    /**
     * @return array{
     *   cpu:?string,gpu:?string,monitor:?string,
     *   screen_diagonal:?string,ps_model:?string,info_edge:?string
     * }
     */
    public function roomInfo(): array
    {
        return [
            'cpu' => $this->cpu,
            'gpu' => $this->gpu,
            'monitor' => $this->monitor,
            'screen_diagonal' => $this->screen_diagonal,
            'ps_model' => $this->ps_model,
            'info_edge' => $this->info_edge,
        ];
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function computers(): HasMany
    {
        return $this->hasMany(Computer::class);
    }

    public function fan(): HasOne
    {
        return $this->hasOne(SpaceFan::class);
    }

    public function addons(): BelongsToMany
    {
        return $this->belongsToMany(Addon::class, 'space_addon')->withTimestamps();
    }

    /**
     * Сумма допов always (₽/ч) для клуба комнаты.
     */
    public function alwaysSurchargePerHour(?int $clubId = null): float
    {
        $clubId ??= (int) $this->club_id;
        $this->loadMissing('addons.prices');

        $total = 0.0;
        foreach ($this->addons as $addon) {
            if (! $addon->is_active || ! $addon->isAlways()) {
                continue;
            }
            $price = $addon->priceForClub($clubId);
            if ($price !== null) {
                $total += $price;
            }
        }

        return $total;
    }

    /**
     * Итоговая доплата always: допы на комнате + legacy-поле.
     */
    public function effectiveAlwaysSurchargePerHour(?int $clubId = null): float
    {
        return max(
            (float) ($this->surcharge_per_hour ?? 0),
            $this->alwaysSurchargePerHour($clubId)
        );
    }

    /**
     * Сумма выбранных optional-допов (₽/ч) для этой комнаты.
     *
     * @param  list<int>  $selectedAddonIds
     */
    public function optionalSurchargePerHour(array $selectedAddonIds, ?int $clubId = null): float
    {
        $clubId ??= (int) $this->club_id;
        $wanted = array_fill_keys(array_map('intval', $selectedAddonIds), true);
        if ($wanted === []) {
            return 0.0;
        }

        $this->loadMissing('addons.prices');

        $total = 0.0;
        foreach ($this->addons as $addon) {
            if (! $addon->is_active || ! $addon->isOptional() || ! isset($wanted[(int) $addon->id])) {
                continue;
            }
            $price = $addon->priceForClub($clubId);
            if ($price !== null) {
                $total += $price;
            }
        }

        return $total;
    }

    public function hasPlus(): bool
    {
        return $this->effectiveAlwaysSurchargePerHour() > 0;
    }

    public function containsPoint(float $x, float $y): bool
    {
        if ($this->w <= 0 || $this->h <= 0) {
            return false;
        }

        return $x >= $this->x
            && $x <= $this->x + $this->w
            && $y >= $this->y
            && $y <= $this->y + $this->h;
    }
}
