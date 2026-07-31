<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use YooKassa\Client;
use YooKassa\Model\Notification\NotificationFactory;
use YooKassa\Model\Payment\PaymentInterface;

class YooKassaService
{
    protected Client $client;

    public function __construct()
    {
        $this->client = new Client();
        $this->client->setAuth(
            (string) config('services.yookassa.shop_id'),
            (string) config('services.yookassa.secret_key'),
        );
    }

    public function isConfigured(): bool
    {
        return filled(config('services.yookassa.shop_id'))
            && filled(config('services.yookassa.secret_key'));
    }

    /**
     * Create a YooKassa payment and persist a local Payment row.
     *
     * @param  string  $confirmation  redirect|embedded
     */
    public function createTopUp(
        User $user,
        float $amount,
        string $method = 'card',
        ?string $returnTo = null,
        string $confirmation = 'redirect',
    ): Payment {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('ЮKassa не настроена: укажите YOOKASSA_SHOP_ID и YOOKASSA_SECRET_KEY.');
        }

        $amount = round($amount, 2);
        $method = in_array($method, ['card', 'sbp'], true) ? $method : 'card';
        $confirmation = in_array($confirmation, ['redirect', 'embedded'], true) ? $confirmation : 'redirect';

        $payment = Payment::create([
            'user_id' => $user->id,
            'amount' => $amount,
            'currency' => 'RUB',
            'status' => Payment::STATUS_PENDING,
            'provider' => 'yookassa',
            'method' => $method,
            'payload' => [
                'return_to' => $this->sanitizeReturnTo($returnTo),
                'confirmation_type' => $confirmation,
            ],
        ]);

        $returnUrl = route('billing.yookassa.return', ['payment' => $payment->uuid], absolute: true);

        $request = [
            'amount' => [
                'value' => number_format($amount, 2, '.', ''),
                'currency' => 'RUB',
            ],
            'capture' => true,
            'description' => 'Пополнение депозита REACTOR #' . $payment->id,
            'metadata' => [
                'payment_uuid' => $payment->uuid,
                'user_id' => (string) $user->id,
                'purpose' => 'wallet_topup',
            ],
        ];

        if ($confirmation === 'embedded') {
            // Widget mode: no payment_method_data — restrict methods in the widget UI.
            $request['confirmation'] = [
                'type' => 'embedded',
                'locale' => 'ru_RU',
            ];
        } else {
            $request['confirmation'] = [
                'type' => 'redirect',
                'return_url' => $returnUrl,
            ];
            $request['payment_method_data'] = [
                'type' => $method === 'sbp' ? 'sbp' : 'bank_card',
            ];
        }

        try {
            $response = $this->client->createPayment($request, $payment->idempotency_key);
        } catch (\Throwable $e) {
            $payment->update([
                'status' => Payment::STATUS_CANCELED,
                'payload' => array_merge($payment->payload ?? [], ['error' => $e->getMessage()]),
            ]);
            throw $e;
        }

        $confirmationObj = $response->getConfirmation();
        $confirmationUrl = null;
        $confirmationToken = null;
        if ($confirmationObj) {
            if (method_exists($confirmationObj, 'getConfirmationUrl')) {
                $confirmationUrl = $confirmationObj->getConfirmationUrl();
            }
            if (method_exists($confirmationObj, 'getConfirmationToken')) {
                $confirmationToken = $confirmationObj->getConfirmationToken();
            }
        }

        $asArray = json_decode(json_encode($response), true) ?: [];
        if (!$confirmationUrl) {
            $confirmationUrl = $asArray['confirmation']['confirmation_url'] ?? null;
        }
        if (!$confirmationToken) {
            $confirmationToken = $asArray['confirmation']['confirmation_token'] ?? null;
        }

        $payment->update([
            'provider_payment_id' => $response->getId(),
            'confirmation_url' => $confirmationUrl,
            'status' => $this->mapStatus((string) $response->getStatus()),
            'payload' => array_merge($payment->payload ?? [], [
                'created' => $asArray,
                'confirmation_token' => $confirmationToken,
                'confirmation_type' => $confirmation,
            ]),
        ]);

        return $payment->fresh();
    }

    /**
     * Только относительный путь внутри приложения — чтобы return_url нельзя было увести на чужой домен.
     */
    protected function sanitizeReturnTo(?string $returnTo): string
    {
        if (!$returnTo) {
            return '/account/dashboard';
        }

        $path = parse_url($returnTo, PHP_URL_PATH) ?: '';
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return '/account/dashboard';
        }

        $query = parse_url($returnTo, PHP_URL_QUERY);

        return $query ? $path . '?' . $query : $path;
    }

    /**
     * Sync local payment from YooKassa API and credit wallet if succeeded.
     */
    public function syncAndFulfill(Payment $payment): Payment
    {
        if ($payment->isSucceeded() || !$payment->provider_payment_id) {
            return $payment;
        }

        $remote = $this->client->getPaymentInfo($payment->provider_payment_id);

        return $this->fulfillFromRemote($payment, $remote);
    }

    /**
     * Handle YooKassa webhook notification body (already decoded array).
     */
    public function handleNotification(array $requestBody): void
    {
        try {
            $notification = (new NotificationFactory())->factory($requestBody);
        } catch (\InvalidArgumentException $e) {
            Log::info('YooKassa webhook ignored', [
                'event' => $requestBody['event'] ?? null,
                'reason' => $e->getMessage(),
            ]);
            return;
        }

        $remote = $notification->getObject();
        if (!$remote instanceof PaymentInterface) {
            Log::info('YooKassa webhook: non-payment object', [
                'event' => $requestBody['event'] ?? null,
            ]);
            return;
        }

        $metadata = $remote->getMetadata();
        $uuid = null;
        if (is_array($metadata)) {
            $uuid = $metadata['payment_uuid'] ?? null;
        } elseif (is_object($metadata)) {
            $uuid = $metadata['payment_uuid'] ?? null;
        }

        $payment = $uuid
            ? Payment::query()->where('uuid', $uuid)->first()
            : Payment::query()->where('provider_payment_id', $remote->getId())->first();

        if (!$payment) {
            Log::warning('YooKassa webhook: payment not found', [
                'provider_id' => $remote->getId(),
                'uuid' => $uuid,
            ]);
            return;
        }

        $this->fulfillFromRemote($payment, $remote);
    }

    protected function fulfillFromRemote(Payment $payment, PaymentInterface $remote): Payment
    {
        return DB::transaction(function () use ($payment, $remote) {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            $status = $this->mapStatus((string) $remote->getStatus());
            $payload = array_merge($locked->payload ?? [], [
                'last' => json_decode(json_encode($remote), true),
            ]);

            if ($locked->isSucceeded()) {
                return $locked;
            }

            if ($status === Payment::STATUS_SUCCEEDED) {
                return $this->creditWallet($locked, $remote, $payload);
            }

            $locked->update([
                'status' => $status,
                'provider_payment_id' => $remote->getId() ?: $locked->provider_payment_id,
                'payload' => $payload,
            ]);

            return $locked->fresh();
        });
    }

    protected function creditWallet(Payment $payment, PaymentInterface $remote, array $payload): Payment
    {
        $user = User::query()->findOrFail($payment->user_id);
        $user->syncBalanceToWallet();
        $wallet = $user->wallet()->firstOrCreate(['user_id' => $user->id]);
        $wallet->creditSpendable((float) $payment->amount);

        $remoteMethod = $remote->getPaymentMethod()?->getType();
        $source = match ($remoteMethod) {
            'bank_card' => 'card',
            'sbp' => 'sbp',
            default => $remoteMethod ?: ($payment->method === 'sbp' ? 'sbp' : 'card'),
        };

        $transaction = Transaction::create([
            'user_id' => $user->id,
            'amount' => $payment->amount,
            'type' => 'deposit',
            'source' => $source,
            'description' => 'Пополнение через ЮKassa',
            'idempotency_key' => 'yookassa:' . ($remote->getId() ?: $payment->uuid),
            'payload' => [
                'payment_uuid' => $payment->uuid,
                'provider_payment_id' => $remote->getId(),
            ],
        ]);

        $payment->update([
            'status' => Payment::STATUS_SUCCEEDED,
            'provider_payment_id' => $remote->getId() ?: $payment->provider_payment_id,
            'method' => $source,
            'transaction_id' => $transaction->id,
            'paid_at' => now(),
            'payload' => $payload,
        ]);

        Log::info('YooKassa payment credited', [
            'payment_id' => $payment->id,
            'user_id' => $user->id,
            'amount' => $payment->amount,
        ]);

        return $payment->fresh();
    }

    protected function mapStatus(string $status): string
    {
        return match ($status) {
            'succeeded' => Payment::STATUS_SUCCEEDED,
            'canceled' => Payment::STATUS_CANCELED,
            'waiting_for_capture' => Payment::STATUS_WAITING,
            default => Payment::STATUS_PENDING,
        };
    }
}
