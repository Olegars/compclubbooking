<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductUnit extends Model
{
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_SOLD = 'sold';
    public const STATUS_WRITTEN_OFF = 'written_off';

    protected $fillable = [
        'product_id',
        'marking_code',
        'status',
        'received_by',
        'received_at',
        'sold_order_id',
        'sold_at',
        'written_off_by',
        'write_off_reason',
        'written_off_at',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'sold_at' => 'datetime',
        'written_off_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Normalize scanner input: trim, strip GS, map RU layout typos → Latin QWERTY.
     */
    public static function normalizeCode(?string $raw): string
    {
        $code = trim((string) $raw);
        // Scanners sometimes suffix Group Separator (ASCII 29)
        $code = str_replace(["\x1d", "\x1D"], '', $code);
        $code = preg_replace('/\s+/', '', $code) ?? $code;

        // HID + русская раскладка: те же физические клавиши дают кириллицу
        static $ruToEn = [
            'й' => 'q', 'ц' => 'w', 'у' => 'e', 'к' => 'r', 'е' => 't', 'н' => 'y', 'г' => 'u', 'ш' => 'i', 'щ' => 'o', 'з' => 'p', 'х' => '[', 'ъ' => ']',
            'ф' => 'a', 'ы' => 's', 'в' => 'd', 'а' => 'f', 'п' => 'g', 'р' => 'h', 'о' => 'j', 'л' => 'k', 'д' => 'l', 'ж' => ';', 'э' => "'",
            'я' => 'z', 'ч' => 'x', 'с' => 'c', 'м' => 'v', 'и' => 'b', 'т' => 'n', 'ь' => 'm', 'б' => ',', 'ю' => '.', 'ё' => '`',
            'Й' => 'Q', 'Ц' => 'W', 'У' => 'E', 'К' => 'R', 'Е' => 'T', 'Н' => 'Y', 'Г' => 'U', 'Ш' => 'I', 'Щ' => 'O', 'З' => 'P', 'Х' => '{', 'Ъ' => '}',
            'Ф' => 'A', 'Ы' => 'S', 'В' => 'D', 'А' => 'F', 'П' => 'G', 'Р' => 'H', 'О' => 'J', 'Л' => 'K', 'Д' => 'L', 'Ж' => ':', 'Э' => '"',
            'Я' => 'Z', 'Ч' => 'X', 'С' => 'C', 'М' => 'V', 'И' => 'B', 'Т' => 'N', 'Ь' => 'M', 'Б' => '<', 'Ю' => '>', 'Ё' => '~',
        ];
        $code = strtr($code, $ruToEn);

        return $code;
    }

    /**
     * Extract GTIN from Chestny Znak / GS1 DataMatrix (AI 01).
     */
    public static function extractGtin(?string $raw): ?string
    {
        $code = self::normalizeCode($raw);
        if ($code === '') {
            return null;
        }

        if (preg_match('/^01(\d{14})/', $code, $m)) {
            return $m[1];
        }

        // Plain EAN-13 / GTIN-14 without AI prefix
        if (preg_match('/^\d{8}$|^\d{12,14}$/', $code)) {
            return str_pad($code, 14, '0', STR_PAD_LEFT);
        }

        return null;
    }
}
