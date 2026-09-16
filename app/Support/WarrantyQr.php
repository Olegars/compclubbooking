<?php

namespace App\Support;

use App\Models\StoreWarranty;
use App\Services\StoreWarrantyService;
use DateTimeInterface;

/**
 * Payload for warranty stickers: public passport URL (phone cameras open it).
 */
final class WarrantyQr
{
    public static function payload(StoreWarranty $warranty): string
    {
        $url = $warranty->passportUrl();
        if ($url) {
            return $url;
        }

        $token = app(StoreWarrantyService::class)->ensurePublicToken($warranty);

        return url('/pc/'.$token);
    }

    public static function fromSerialAndEnds(string $serial, DateTimeInterface|string|null $endsAt): string
    {
        $serial = trim($serial);
        $warranty = $serial !== ''
            ? StoreWarranty::query()->where('serial', $serial)->latest('id')->first()
            : null;

        if ($warranty) {
            return self::payload($warranty);
        }

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
