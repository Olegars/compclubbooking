<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffEdoAgreement extends Model
{
    protected $fillable = [
        'admin_id',
        'agreement_version',
        'phone_number',
        'telegram_id',
        'signed_at',
        'ip_address',
        'user_agent',
        'otp_code_hash',
        'document_hash',
    ];

    protected $casts = [
        'signed_at' => 'datetime',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
