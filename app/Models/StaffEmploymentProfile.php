<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffEmploymentProfile extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_REVIEW = 'review';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_APPROVED = 'approved';

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
        'status',
        'submitted_at',
        'reviewed_at',
        'reviewed_by',
        'rejection_reason',
    ];

    protected $casts = [
        'issued_at' => 'date',
        'birth_date' => 'date',
        'accepted_rule_ids' => 'array',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by');
    }

    /**
     * @return list<int>
     */
    public function acceptedRuleIds(): array
    {
        return array_values(array_map('intval', $this->accepted_rule_ids ?? []));
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isOnReview(): bool
    {
        return $this->status === self::STATUS_REVIEW;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function canEdit(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_REJECTED], true);
    }
}
