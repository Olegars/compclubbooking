<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffEmploymentProfile extends Model
{
    protected $fillable = [
        'admin_id',
        'full_name',
        'passport_series',
        'passport_number',
        'issued_by',
        'issued_at',
        'department_code',
        'birth_date',
        'passport_scan_path',
        'accepted_rule_ids',
    ];

    protected $casts = [
        'issued_at' => 'date',
        'birth_date' => 'date',
        'accepted_rule_ids' => 'array',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * @return list<int>
     */
    public function acceptedRuleIds(): array
    {
        return array_values(array_map('intval', $this->accepted_rule_ids ?? []));
    }
}
