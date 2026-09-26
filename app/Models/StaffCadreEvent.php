<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffCadreEvent extends Model
{
    public const HIRE = 'hire';

    public const FIRE = 'fire';

    public const TRANSFER = 'transfer';

    public const PENDING = 'pending';

    public const EXPORTED = 'exported';

    public const SUBMITTED = 'submitted';

    protected $fillable = [
        'admin_id',
        'club_id',
        'event_type',
        'event_date',
        'order_number',
        'order_date',
        'okz_code',
        'work_function_title',
        'part_time_code',
        'fire_reason_code',
        'status',
        'deadline_at',
        'exported_at',
        'report_id',
    ];

    protected $casts = [
        'event_date' => 'date',
        'order_date' => 'date',
        'deadline_at' => 'datetime',
        'exported_at' => 'datetime',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(CadreReport::class, 'report_id');
    }
}
