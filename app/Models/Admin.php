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

    public const ROLE_ADMIN = 'admin';

    public const ROLE_INTERN = 'intern';

    public const ROLE_SUPERVISOR = 'supervisor';

    public const ROLE_OWNER = 'owner';

    /** Должность смены: обычный админ или стажёр. Активный/неактивный считается по текущей смене. */
    public const FLOOR_ADMIN_ROLES = ['admin', 'intern'];

    public const CLUB_ROLES = ['admin', 'intern', 'supervisor', 'owner'];

    public const STORE_ROLES = ['store_manager', 'assembler', 'senior_manager', 'owner'];

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
        'is_official_employee' => 'boolean',
        'base_rate' => 'decimal:2',
    ];

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function payrolls(): HasMany
    {
        return $this->hasMany(Payroll::class, 'admin_id');
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class, 'admin_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(StaffLedger::class, 'admin_id');
    }

    public function internShifts(): HasMany
    {
        return $this->hasMany(ShiftIntern::class, 'admin_id');
    }

    public function isStoreRole(): bool
    {
        return in_array($this->role, self::STORE_ONLY_ROLES, true);
    }

    public function isIntern(): bool
    {
        return $this->role === self::ROLE_INTERN;
    }

    public function isClubRole(): bool
    {
        return in_array($this->role, ['admin', 'intern', 'supervisor'], true);
    }

    public function isFloorAdminFamily(): bool
    {
        return in_array($this->role, self::FLOOR_ADMIN_ROLES, true);
    }

    public function canAccessClub(): bool
    {
        return in_array($this->role, self::CLUB_ROLES, true);
    }

    public function canAccessStore(): bool
    {
        return in_array($this->role, self::STORE_ROLES, true);
    }

    public function isShiftLead(): bool
    {
        return Shift::query()
            ->where('status', '!=', 'closed')
            ->where('admin_id', $this->id)
            ->exists();
    }

    public function isInternOnOpenShift(): bool
    {
        if (! $this->isIntern()) {
            return false;
        }

        return ShiftIntern::query()
            ->where('admin_id', $this->id)
            ->whereNull('left_at')
            ->whereHas('shift', fn ($q) => $q->where('status', '!=', 'closed'))
            ->exists();
    }

    /** Полный функционал клуба: управляющий/владелец или админ, который принял смену. */
    public function hasFullClubOps(): bool
    {
        if (in_array($this->role, [self::ROLE_OWNER, self::ROLE_SUPERVISOR], true)) {
            return true;
        }

        return $this->role === self::ROLE_ADMIN && $this->isShiftLead();
    }

    /** Неактивный админ и стажёр видят только «Моя зарплата». */
    public function isSalaryOnly(): bool
    {
        if ($this->isIntern()) {
            return true;
        }

        return $this->role === self::ROLE_ADMIN && ! $this->isShiftLead();
    }

    public function canManageStoreCatalog(): bool
    {
        return in_array($this->role, ['store_manager', 'senior_manager', 'owner'], true);
    }

    public function canManageStoreInventory(): bool
    {
        return in_array($this->role, ['store_manager', 'senior_manager', 'owner'], true);
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
        if ($this->isSalaryOnly()) {
            return 'admin.salary';
        }

        if ($this->canAccessClub()) {
            return 'admin.dashboard';
        }

        return 'admin.store.warehouse';
    }

    public function roleLabel(): string
    {
        return self::labelForRole($this->role);
    }

    public static function labelForRole(?string $role): string
    {
        return match ($role) {
            self::ROLE_ADMIN => 'Админ',
            self::ROLE_INTERN => 'Стажёр',
            self::ROLE_SUPERVISOR => 'Управляющий',
            self::ROLE_OWNER => 'Владелец',
            'store_manager' => 'Менеджер магазина',
            'assembler' => 'Сборщик',
            'senior_manager' => 'Старший менеджер',
            default => $role ?: '—',
        };
    }
}
