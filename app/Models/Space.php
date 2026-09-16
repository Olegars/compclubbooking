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
        'rotate',
        'points',
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
        'rotate' => 'float',
        'points' => 'array',
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

    public function light(): HasOne
    {
        return $this->hasOne(SpaceLight::class);
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
        $points = is_array($this->points) ? $this->points : [];
        $poly = [];
        foreach ($points as $p) {
            if (! is_array($p)) {
                continue;
            }
            $px = (float) ($p['x'] ?? 0);
            $py = (float) ($p['y'] ?? 0);
            $poly[] = [$px, $py];
        }
        if (count($poly) >= 3) {
            return self::pointInPolygon($poly, $x, $y);
        }

        if ($this->w <= 0 || $this->h <= 0) {
            return false;
        }

        $px = $x;
        $py = $y;
        $rotate = (float) ($this->rotate ?? 0);
        if (abs($rotate) > 0.001) {
            $cx = $this->x + $this->w / 2;
            $cy = $this->y + $this->h / 2;
            $a = deg2rad(-$rotate);
            $dx = $x - $cx;
            $dy = $y - $cy;
            $px = $cx + $dx * cos($a) - $dy * sin($a);
            $py = $cy + $dx * sin($a) + $dy * cos($a);
        }

        return $px >= $this->x
            && $px <= $this->x + $this->w
            && $py >= $this->y
            && $py <= $this->y + $this->h;
    }

    /**
     * @param  list<array{0:float,1:float}>  $poly
     */
    private static function pointInPolygon(array $poly, float $x, float $y): bool
    {
        $inside = false;
        $n = count($poly);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$xi, $yi] = $poly[$i];
            [$xj, $yj] = $poly[$j];
            $dy = ($yj - $yi) ?: 1e-12;
            $intersect = (($yi > $y) !== ($yj > $y))
                && ($x < ($xj - $xi) * ($y - $yi) / $dy + $xi);
            if ($intersect) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }
}
