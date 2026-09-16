<?php

namespace App\Services;

use App\Models\StoreClient;
use App\Models\StoreOrder;
use App\Models\StorePromoCode;
use App\Models\User;
use RuntimeException;

class StorePromoCodeService
{
    public function findActive(string $code): ?StorePromoCode
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return null;
        }

        $promo = StorePromoCode::query()->where('code', $code)->first();
        if (! $promo || ! $promo->isRedeemable()) {
            return null;
        }

        return $promo;
    }

    /**
     * 10% off a REACTOR Store order. Code is bound to a club player.
     *
     * @throws RuntimeException
     */
    public function applyToOrder(StoreOrder $order, string $code, ?StoreClient $client = null): StorePromoCode
    {
        $promo = $this->findActive($code);
        if (! $promo) {
            throw new RuntimeException('Промокод не найден или уже использован');
        }

        $owner = User::query()->find($promo->user_id);
        if ($client && $owner && ! $this->phonesMatch((string) $client->phone, (string) $owner->phone)) {
            throw new RuntimeException('Промокод привязан к другому гостю');
        }

        $gross = (float) $order->total;
        $percent = max(1, min(30, (int) $promo->percent));
        $discount = round($gross * ($percent / 100), 2);
        if ($discount <= 0) {
            throw new RuntimeException('Нечего списывать по промокоду');
        }

        $note = trim((string) $order->notes);
        $line = 'Lucky Seat '.$promo->code.': −'.$percent.'% на периферию (−'.((int) $discount).' ₽)';
        $order->update([
            'total' => max(0, round($gross - $discount, 2)),
            'promo_code_id' => $promo->id,
            'discount_amount' => $discount,
            'notes' => $note === '' ? $line : $note."\n".$line,
        ]);

        $promo->update([
            'status' => StorePromoCode::STATUS_USED,
            'store_order_id' => $order->id,
            'used_at' => now(),
        ]);

        return $promo->fresh() ?? $promo;
    }

    private function phonesMatch(string $a, string $b): bool
    {
        $da = preg_replace('/\D+/', '', $a) ?? '';
        $db = preg_replace('/\D+/', '', $b) ?? '';
        if ($da === '' || $db === '') {
            return true;
        }

        return substr($da, -10) === substr($db, -10);
    }
}
