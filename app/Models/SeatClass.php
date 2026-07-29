<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeatClass extends Model
{
    public const KIND_PC = 'pc';
    public const KIND_TV = 'tv';

    protected $fillable = [
        'slug',
        'name',
        'monitor',
        'gpu',
        'cpu',
        'highlights',
        'color',
        'kind',
        'sort',
        'is_active',
    ];

    protected $casts = [
        'highlights' => 'array',
        'sort' => 'integer',
        'is_active' => 'boolean',
    ];

    public function computers(): HasMany
    {
        return $this->hasMany(Computer::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(TariffPrice::class);
    }

    /**
     * Характеристики для показа гостю: клуб продаёт железо, а не ярлык.
     *
     * @return list<string>
     */
    public function specLines(): array
    {
        $lines = array_filter([
            $this->monitor,
            $this->gpu,
            $this->cpu,
        ]);

        foreach ((array) $this->highlights as $highlight) {
            if (is_string($highlight) && trim($highlight) !== '') {
                $lines[] = trim($highlight);
            }
        }

        return array_values($lines);
    }
}
