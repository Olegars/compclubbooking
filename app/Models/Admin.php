<?php
// app/Models/Admin.php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Admin extends Authenticatable
{
    use Notifiable;

    protected $guard = 'admin'; // Явно указываем гуард

    protected $fillable = [
        'name', 'email', 'password', 'role',
        'is_official_employee', 'base_rate', 'pay_type'
    ];

    // Связь: У одного админа может быть много зарплатных выплат
    public function payrolls()
    {
        return $this->hasMany(Payroll::class, 'admin_id');
    }

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected $casts = [
        'password' => 'hashed',
    ];
}
