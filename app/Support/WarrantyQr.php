<?php

namespace App\Support;

use App\Models\StoreWarranty;
use DateTimeInterface;

/**
 * Payload for warranty stickers: serial + warranty end date.
 */
final class WarrantyQr
{
    public static function payload(StoreWarranty $warranty): string
    {
        return self::fromSerialAndEnds(
            (string) $warranty->serial,
            $warranty->ends_at
        );
    }

    public static function fromSerialAndEnds(string $serial, DateTimeInterface|string|null $endsAt): string
    {
        $serial = trim($serial);
        $ends = '—';
        if ($endsAt instanceof DateTimeInterface) {
            $ends = $endsAt->format('d.m.Y');
        } elseif (is_string($endsAt) && $endsAt !== '') {
            $ends = date('d.m.Y', strtotime($endsAt)) ?: $endsAt;
        }

        return "S/N: {$serial}\nГарантия до: {$ends}";
    }

    /** External QR PNG (same as fiscal receipts — no PHP QR package). */
    public static function imageUrl(string $payload, int $size = 240): string
    {
        return 'https://api.qrserver.com/v1/create-qr-code/?size='.$size.'x'.$size
            .'&ecc=M&margin=1&data='.rawurlencode($payload);
    }
}
