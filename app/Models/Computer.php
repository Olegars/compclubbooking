<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Computer extends Model
{
    protected $fillable = ['club_id', 'name', 'x', 'y', 'type', 'status', 'hwid'];

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function gameAccountCaches(): HasMany
    {
        return $this->hasMany(GameAccountMachineCache::class, 'computer_id');
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
}
