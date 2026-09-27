<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StaffDisciplinaryIncident extends Model
{
    public const TYPE_ABSENCE = 'shift_absence';

    public const TYPE_ABANDONMENT = 'abandonment';

    public const STATUS_DEMAND = 'demand_sent';

    public const STATUS_EXPLAINED = 'explanation_submitted';

    public const STATUS_EXPIRED = 'expired_no_response';

    public const STATUS_EXCUSED = 'resolved_excused';

    public const STATUS_PUNISHED = 'punished';

    /** Докладная подготовлена кнопкой старшего администратора и ждёт его подписи. */
    public const STATUS_MEMO = 'memo_for_signature';

    /**
     * Открытые статусы для журнала и бейджа. Кабинет по ним не закрывается:
     * данные камеры и автоматический акт не блокируют доступ.
     */
    public const OPEN = [
        self::STATUS_DEMAND,
        self::STATUS_EXPLAINED,
        self::STATUS_EXPIRED,
        self::STATUS_MEMO,
    ];

    protected $fillable = [
        'admin_id',
        'club_id',
        'shift_id',
        'shift_slot_booking_id',
        'incident_type',
        'detected_at',
        'deadline_at',
        'status',
        'demand_delivered_at',
        'explanation_text',
        'explanation_files',
        'explanation_signed_at',
        'evidence_meta',
        'resolution',
        'resolved_by',
        'resolved_at',
        'xp_forfeited',
    ];

    protected $casts = [
        'detected_at' => 'datetime',
        'deadline_at' => 'datetime',
        'demand_delivered_at' => 'datetime',
        'explanation_files' => 'array',
        'explanation_signed_at' => 'datetime',
        'evidence_meta' => 'array',
        'resolved_at' => 'datetime',
        'xp_forfeited' => 'decimal:2',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(StaffEdoDocument::class, 'incident_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(ShiftSlotBooking::class, 'shift_slot_booking_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            self::TYPE_ABSENCE => 'Невыход на слот',
            self::TYPE_ABANDONMENT => 'Оставление смены',
            default => $type,
        };
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_DEMAND => 'Ждём объяснительную',
            self::STATUS_EXPLAINED => 'Объяснение получено',
            self::STATUS_EXPIRED => 'Срок объяснений истёк',
            self::STATUS_EXCUSED => 'Причина уважительная',
            self::STATUS_PUNISHED => 'На взыскание',
            self::STATUS_MEMO => 'Докладная на подписи',
            default => $status,
        };
    }
}
