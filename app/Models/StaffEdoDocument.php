<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffEdoDocument extends Model
{
    public const TYPE_AGREEMENT = 'pep_agreement';

    public const TYPE_ABSENCE = 'absence_act';

    public const TYPE_DEMAND = 'demand_notice';

    public const TYPE_EXPLANATION = 'explanation_memo';

    public const TYPE_NO_EXPLANATION = 'no_explanation_act';

    public const COMMISSION_TYPES = [
        self::TYPE_ABSENCE,
        self::TYPE_NO_EXPLANATION,
    ];

    protected $fillable = [
        'incident_id',
        'admin_id',
        'doc_type',
        'doc_title',
        'storage_path',
        'doc_hash_sha256',
        'signatures',
    ];

    protected $casts = [
        'signatures' => 'array',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(StaffDisciplinaryIncident::class, 'incident_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
