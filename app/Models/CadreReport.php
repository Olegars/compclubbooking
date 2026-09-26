<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CadreReport extends Model
{
    public const EFS1 = 'efs1_sub1_1';

    public const PERS = 'pers_records_fns';

    public const EXPORTED = 'exported';

    public const SUBMITTED = 'submitted';

    protected $fillable = [
        'report_type',
        'period_year',
        'period_month',
        'file_path',
        'records_count',
        'xml_hash',
        'created_by',
        'status',
        'submitted_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(StaffCadreEvent::class, 'report_id');
    }
}
