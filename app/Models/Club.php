<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Club extends Model
{
    public const SOURCE_LOCATION = 'location';

    public const SOURCE_OPEN = 'open';

    protected $fillable = [
        'name',
        'slug',
        'type',
        'source',
        'address',
        'city',
        'network_name',
        'contact',
        'website',
        'tournament_open',
        'map_config',
        'viewbox',
    ];

    protected $casts = [
        'map_config' => 'array',
        'tournament_open' => 'boolean',
    ];

    public function hasStore(): bool
    {
        return in_array(strtolower(trim((string) $this->type)), ['store', 'both'], true);
    }

    public function hasClub(): bool
    {
        $type = strtolower(trim((string) $this->type));

        return $type === '' || in_array($type, ['club', 'both'], true);
    }

    public function isOperationalLocation(): bool
    {
        $source = strtolower(trim((string) ($this->source ?? '')));

        return $source === '' || $source === self::SOURCE_LOCATION;
    }

    public function isOpenPartner(): bool
    {
        return strtolower(trim((string) ($this->source ?? ''))) === self::SOURCE_OPEN;
    }

    public function isTournamentClub(): bool
    {
        if ($this->tournament_open === false) {
            return false;
        }

        return $this->hasClub();
    }

    public function scopeOperational($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('source')->orWhere('source', self::SOURCE_LOCATION);
        });
    }

    public function scopeVisibleToAdmin($query, ?int $currentClubId = null)
    {
        return $query->where(function ($q) use ($currentClubId) {
            $q->where(function ($inner) {
                $inner->whereNull('source')->orWhere('source', self::SOURCE_LOCATION);
            });
            if ($currentClubId) {
                $q->orWhereKey($currentClubId);
            }
        });
    }

    public function scopeTournamentRoster($query)
    {
        return $query
            ->where(function ($q) {
                $q->whereNull('tournament_open')->orWhere('tournament_open', true);
            })
            ->where(function ($q) {
                $q->whereNull('type')->orWhereIn('type', ['club', 'both']);
            });
    }

    public function rosterLabel(): string
    {
        $parts = [trim((string) $this->name)];
        $city = trim((string) ($this->city ?? ''));
        $network = trim((string) ($this->network_name ?? ''));
        if ($city !== '') {
            $parts[] = $city;
        }
        if ($network !== '') {
            $parts[] = $network;
        }

        return implode(' · ', array_filter($parts));
    }

    /**
     * @return array{id:int, name:string, slug:?string, city:?string, network_name:?string, label:string}
     */
    public function circuitCard(): array
    {
        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            'slug' => $this->slug,
            'city' => $this->city,
            'network_name' => $this->network_name,
            'label' => $this->rosterLabel(),
        ];
    }

    public function computers(): HasMany
    {
        return $this->hasMany(Computer::class);
    }

    public function spaces(): HasMany
    {
        return $this->hasMany(Space::class);
    }

    public function tariffPrices(): HasMany
    {
        return $this->hasMany(TariffPrice::class);
    }

    public function gameOffers(): HasMany
    {
        return $this->hasMany(ClubGame::class);
    }

    public function bookingGroups(): HasMany
    {
        return $this->hasMany(BookingGroup::class);
    }

    public function storeClients(): HasMany
    {
        return $this->hasMany(StoreClient::class);
    }

    public function storeProducts(): HasMany
    {
        return $this->hasMany(StoreProduct::class);
    }

    public function storeComponents(): HasMany
    {
        return $this->hasMany(StoreComponent::class);
    }

    public function storeBuiltPcs(): HasMany
    {
        return $this->hasMany(StoreBuiltPc::class);
    }

    public function storeSuppliers(): HasMany
    {
        return $this->hasMany(StoreSupplier::class);
    }

    public function storeOrders(): HasMany
    {
        return $this->hasMany(StoreOrder::class);
    }

    public function storeWarranties(): HasMany
    {
        return $this->hasMany(StoreWarranty::class);
    }

    public function admins(): HasMany
    {
        return $this->hasMany(Admin::class);
    }
}
