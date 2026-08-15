<?php
// app/Models/Admin.php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Admin extends Authenticatable
{
    use Notifiable;

    public const CLUB_ROLES = ['admin', 'supervisor', 'owner'];

    public const STORE_ROLES = ['admin', 'supervisor', 'owner', 'store_manager', 'assembler', 'senior_manager'];

    public const STORE_ONLY_ROLES = ['store_manager', 'assembler', 'senior_manager'];

    protected $guard = 'admin';

    protected $fillable = [
        'name', 'email', 'password', 'role', 'club_id',
        'is_official_employee', 'base_rate', 'pay_type',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected $casts = [
        'password' => 'hashed',
    ];

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function payrolls(): HasMany
    {
        return $this->hasMany(Payroll::class, 'admin_id');
    }

    public function isStoreRole(): bool
    {
        return in_array($this->role, self::STORE_ONLY_ROLES, true);
    }

    public function isClubRole(): bool
    {
        return in_array($this->role, ['admin', 'supervisor'], true);
    }

    public function canAccessClub(): bool
    {
        return in_array($this->role, self::CLUB_ROLES, true);
    }

    public function canAccessStore(): bool
    {
        return in_array($this->role, self::STORE_ROLES, true);
    }

    public function canManageStoreCatalog(): bool
    {
        return in_array($this->role, ['store_manager', 'senior_manager', 'owner', 'supervisor', 'admin'], true);
    }

    public function canManageStoreInventory(): bool
    {
        return in_array($this->role, ['store_manager', 'senior_manager', 'owner', 'supervisor', 'admin'], true);
    }

    public function canCancelStoreOrders(): bool
    {
        return in_array($this->role, ['senior_manager', 'owner'], true);
    }

    public function canCloseWarranties(): bool
    {
        return in_array($this->role, ['senior_manager', 'owner'], true);
    }

    public function homeRoute(): string
    {
        if ($this->canAccessClub()) {
            return 'admin.dashboard';
        }

        return 'admin.store.warehouse';
    }
}
