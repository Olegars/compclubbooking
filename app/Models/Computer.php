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
        'mac_address', 'lan_ip', 'patch_seed_port', 'patch_seed_role', 'power_desired', 'power_state',
        'power_state_updated_at', 'last_seen_at', 'wol_sent_at',
        'maintenance', 'maintenance_until',
        'cache_ok', 'cache_free_gb', 'data_root', 'volume_letter', 'cache_media', 'ssd_temp_c',
        'nic_link_mbps', 'nic_flap_count', 'nic_flap_shift_id', 'nic_flap_last_at',
        'ssd_wear_pct', 'ssd_read_errors', 'ssd_write_errors', 'ssd_health',
        'super_client', 'games_steam_count', 'games_epic_count',
        'games_inventory_hash', 'games_inventory',
        'diskless_command', 'diskless_disk_mode', 'diskless_command_id',
        'diskless_command_at', 'diskless_result', 'diskless_message',
        'integrity_status', 'integrity_hash', 'integrity_message', 'integrity_drift',
        'gpu_power_limit_w', 'gpu_mode',
        'resync_command', 'resync_command_id', 'resync_command_at',
        'resync_result', 'resync_message',
        'golden_revision_id',
        'rollback_command', 'rollback_command_id', 'rollback_revision_id',
        'rollback_command_at', 'rollback_result', 'rollback_message',
        'last_crash_at', 'last_crash_reason', 'last_crash_detail',
        'patch_pull_command_id', 'patch_pull_command_at', 'patch_pull_payload',
        'patch_pull_result', 'patch_pull_message',
        'patch_ingest_at', 'patch_ingest_result', 'patch_ingest_message',
    ];

    protected $casts = [
        'power_state_updated_at' => 'immutable_datetime',
        'last_seen_at' => 'immutable_datetime',
        'wol_sent_at' => 'immutable_datetime',
        'maintenance_until' => 'immutable_datetime',
        'maintenance' => 'boolean',
        'cache_ok' => 'boolean',
        'cache_free_gb' => 'float',
        'ssd_temp_c' => 'float',
        'nic_link_mbps' => 'integer',
        'nic_flap_count' => 'integer',
        'nic_flap_shift_id' => 'integer',
        'nic_flap_last_at' => 'immutable_datetime',
        'patch_seed_port' => 'integer',
        'patch_ingest_at' => 'immutable_datetime',
        'ssd_wear_pct' => 'integer',
        'ssd_read_errors' => 'integer',
        'ssd_write_errors' => 'integer',
        'super_client' => 'boolean',
        'games_steam_count' => 'integer',
        'games_epic_count' => 'integer',
        'games_inventory' => 'array',
        'diskless_command_id' => 'integer',
        'diskless_command_at' => 'immutable_datetime',
        'integrity_drift' => 'array',
        'gpu_power_limit_w' => 'integer',
        'resync_command_id' => 'integer',
        'resync_command_at' => 'immutable_datetime',
        'golden_revision_id' => 'integer',
        'rollback_command_id' => 'integer',
        'rollback_revision_id' => 'integer',
        'rollback_command_at' => 'immutable_datetime',
        'last_crash_at' => 'immutable_datetime',
        'patch_pull_command_id' => 'integer',
        'patch_pull_command_at' => 'immutable_datetime',
        'patch_pull_payload' => 'array',
    ];

    public const KIND_PC = 'pc';
    public const KIND_TV = 'tv';
    public const KIND_PS5 = 'ps5';

    public function isInMaintenance(?\Carbon\CarbonImmutable $now = null): bool
    {
        if ((string) $this->status === 'maintenance') {
            return true;
        }
        if (! $this->maintenance) {
            return false;
        }
        if ($this->maintenance_until === null) {
            return true;
        }
        $now = $now ?? \Carbon\CarbonImmutable::now();

        return $this->maintenance_until->greaterThan($now);
    }

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

    public function goldenRevisions(): HasMany
    {
        return $this->hasMany(GoldenImageRevision::class);
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
