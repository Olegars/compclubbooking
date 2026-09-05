<?php
// app/Models/Admin.php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'employment_pending', 'hired_at', 'fired_at', 'fired_by',
        'shift_handed_over_at',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected $casts = [
        'password' => 'hashed',
        'is_official_employee' => 'boolean',
        'base_rate' => 'decimal:2',
        'employment_pending' => 'boolean',
        'hired_at' => 'datetime',
        'fired_at' => 'datetime',
        'shift_handed_over_at' => 'datetime',
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

    public function employmentProfile(): HasOne
    {
        return $this->hasOne(StaffEmploymentProfile::class, 'admin_id');
    }

    public function needsEmployment(): bool
    {
        return (bool) $this->employment_pending;
    }

    public function isFired(): bool
    {
        return $this->fired_at !== null;
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

        if ($this->role !== self::ROLE_ADMIN || ! $this->isShiftLead()) {
            return false;
        }

        $shift = Shift::query()
            ->where('status', '!=', 'closed')
            ->where('admin_id', $this->id)
            ->orderByDesc('id')
            ->first();

        return $shift !== null && $shift->status !== 'transferring';
    }

    public function canSetShiftModel(): bool
    {
        return in_array($this->role, [self::ROLE_OWNER, self::ROLE_SUPERVISOR], true);
    }

    public function canReviewEmployment(): bool
    {
        return in_array($this->role, [self::ROLE_OWNER, self::ROLE_SUPERVISOR], true);
    }

    /**
     * @return list<string>
     */
    public function hireableRoles(): array
    {
        $roles = ['admin', 'intern', 'store_manager', 'assembler', 'senior_manager'];
        if ($this->role === self::ROLE_OWNER) {
            $roles[] = self::ROLE_SUPERVISOR;
        }

        return $roles;
    }

    /** Неактивный админ и стажёр видят только личный кабинет. */
    public function isSalaryOnly(): bool
    {
        if ($this->isIntern()) {
            return true;
        }

        return $this->role === self::ROLE_ADMIN && ! $this->hasFullClubOps();
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

    public static function defaultRateFor(string $role): ?float
    {
        return match ($role) {
            self::ROLE_INTERN => 1500,
            self::ROLE_ADMIN => 2000,
            self::ROLE_SUPERVISOR => 3000,
            'store_manager' => 2500,
            'assembler' => 2200,
            'senior_manager' => 3500,
            default => null,
        };
    }
}
