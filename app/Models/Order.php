<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    // Разрешаем запись этих полей
    protected $fillable = [
        'user_id',
        'product_name',
        'price',
        'pc_name',
        'status'
    ];

    // Связь с пользователем (чтобы админ видел, кто заказал)
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
