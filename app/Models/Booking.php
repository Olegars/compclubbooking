<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Booking extends Model
{
    use HasFactory;

    // Разрешаем массовое заполнение для этих колонок
    protected $fillable = [
        'booking_group_id',
        'user_id',
        'pc_ids',
        'computer_id',
        'transfer_from_computer_id',
        'date',
        'start_time',
        'duration',
        'price',
        'price_minor',
        'status',
        'pin_code',
        'transfer_pending_at',
        'starts_at',
        'ends_at',
        'actual_started_at',
        'actual_ended_at',
    ];

    // (Опционально) Связь с юзером, чтобы потом удобно выводить инфу
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(BookingGroup::class, 'booking_group_id');
    }

    public function computer(): BelongsTo
    {
        return $this->belongsTo(Computer::class);
    }

    public function gameReservations(): HasMany
    {
        return $this->hasMany(GameAccountReservation::class);
    }

    protected $casts = [
        'start_time' => 'float',
        'duration'   => 'float',
        'date'       => 'date',
        'pc_ids' => 'array',
        'price_minor' => 'integer',
        'starts_at' => 'immutable_datetime',
        'ends_at' => 'immutable_datetime',
        'actual_started_at' => 'immutable_datetime',
        'actual_ended_at' => 'immutable_datetime',
        'transfer_pending_at' => 'immutable_datetime',
    ];

}
