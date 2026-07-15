<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    use HasFactory;

    // Разрешаем массовое заполнение для этих колонок
    protected $fillable = [
        'user_id',
        'pc_ids',
        'computer_id',
        'date',
        'start_time',
        'duration',
        'price',
        'status',
        'pin_code'
    ];

    // (Опционально) Связь с юзером, чтобы потом удобно выводить инфу
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    protected $casts = [
        'start_time' => 'float',
        'duration'   => 'float',
        'date'       => 'date',
        'pc_ids' => 'array'
    ];

}
