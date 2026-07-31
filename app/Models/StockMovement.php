<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    public const TYPE_WRITE_OFF = 'write_off';
    public const TYPE_COMP = 'comp';
    public const TYPE_SHIFT_ADJUST = 'shift_adjust';
    public const TYPE_RECEIVE = 'receive';
    public const TYPE_SALE = 'sale';
    public const TYPE_SALE_RESTORE = 'sale_restore';

    public const REASON_SPOILAGE = 'spoilage';
    public const REASON_EXPIRED = 'expired';
    public const REASON_BROKEN = 'broken';
    public const REASON_COMP = 'comp';
    public const REASON_OTHER = 'other';
    public const REASON_SHIFT = 'shift';
    public const REASON_CANCEL = 'cancel_restore';

    public const REASON_LABELS = [
        self::REASON_SPOILAGE => 'Брак / порча',
        self::REASON_EXPIRED => 'Просрочка',
        self::REASON_BROKEN => 'Разбито / бой',
        self::REASON_COMP => 'Угощение / бесплатно',
        self::REASON_OTHER => 'Иное',
        self::REASON_SHIFT => 'Пересменка',
        self::REASON_CANCEL => 'Отмена заказа',
    ];

    protected $fillable = [
        'product_id',
        'product_unit_id',
        'admin_id',
        'shift_id',
        'order_id',
        'type',
        'reason_code',
        'reason',
        'qty',
        'stock_before',
        'stock_after',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'qty' => 'integer',
        'stock_before' => 'integer',
        'stock_after' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class, 'product_unit_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }

    public static function reasonLabel(?string $code): string
    {
        if ($code === null || $code === '') {
            return '';
        }

        return self::REASON_LABELS[$code] ?? $code;
    }

    public static function formatReason(?string $code, ?string $note = null): string
    {
        $label = self::reasonLabel($code);
        $note = $note !== null ? trim($note) : '';
        if ($label !== '' && $note !== '') {
            return $label.': '.$note;
        }

        return $note !== '' ? $note : ($label !== '' ? $label : 'Без причины');
    }
}
