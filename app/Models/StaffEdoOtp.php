<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffEdoOtp extends Model
{
    public const PURPOSE_AGREEMENT = 'agreement';

    public const PURPOSE_EXPLANATION = 'explanation';

    protected $fillable = [
        'admin_id',
        'purpose',
        'code_hash',
        'phone',
        'expires_at',
        'consumed_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
