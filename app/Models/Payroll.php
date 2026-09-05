<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payroll extends Model
{
    protected $fillable = [
        'admin_id',
        'period_start',
        'period_end',
        'gross_pay',
        'ndfl_tax',
        'net_pay',
        'employer_taxes',
        'status',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'gross_pay' => 'decimal:2',
        'ndfl_tax' => 'decimal:2',
        'net_pay' => 'decimal:2',
        'employer_taxes' => 'decimal:2',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
