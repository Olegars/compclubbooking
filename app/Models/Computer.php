<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Computer extends Model
{
    protected $fillable = [
        'club_id', 'name', 'x', 'y', 'type', 'seat_class_id', 'space_id',
        'kind', 'booth_id', 'status', 'hwid',
        'mac_address', 'power_desired', 'power_state',
        'power_state_updated_at', 'last_seen_at', 'wol_sent_at',
    ];

    protected $casts = [
        'power_state_updated_at' => 'immutable_datetime',
        'last_seen_at' => 'immutable_datetime',
        'wol_sent_at' => 'immutable_datetime',
    ];

    public const KIND_PC = 'pc';
    public const KIND_TV = 'tv';
    public const KIND_PS5 = 'ps5';

    public function isTvBoothSeat(): bool
    {
        return in_array($this->kind, [self::KIND_TV, self::KIND_PS5], true);
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function seatClass(): BelongsTo
    {
        return $this->belongsTo(SeatClass::class);
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function gameAccountCaches(): HasMany
    {
        return $this->hasMany(GameAccountMachineCache::class, 'computer_id');
    }

    public function installedGames(): HasMany
    {
        return $this->hasMany(ComputerGame::class);
    }

    public function inputDevice()
    {
        return $this->hasOne(ComputerInputDevice::class);
    }

    public function inputAlerts(): HasMany
    {
        return $this->hasMany(ComputerInputAlert::class);
    }

    public function sosAlerts(): HasMany
    {
        return $this->hasMany(ComputerSosAlert::class);
    }

    public function thermal()
    {
        return $this->hasOne(ComputerThermal::class);
    }
}
