<?php

namespace App\Services\AiAssistant;

use App\Models\AiAssistantSetting;
use Illuminate\Support\Facades\Http;

class YandexBillingBalanceClient
{
    /**
     * Баланс платёжного аккаунта Yandex Cloud. Ключ SpeechKit сюда не подходит: нужен IAM-токен.
     *
     * @return array{
     *   ok: bool,
     *   supported: bool,
     *   provider: string,
     *   is_available?: bool,
     *   balances?: list<array{currency: string, total: string, granted: string, topped_up: string, cash: string, credit: string, name: string, active: bool}>,
     *   message?: string
     * }
     */
    public function balance(?int $clubId = null): array
    {
        $settings = AiAssistantSetting::forClub($clubId);
        $token = $settings->resolvedYandexBillingIamToken();
        if ($token === '') {
            return [
                'ok' => false,
                'supported' => true,
                'provider' => 'yandex',
                'message' => 'Нет IAM-токена биллинга Яндекса. Ключ SpeechKit баланс не отдаёт.',
            ];
        }

        $accountId = $settings->resolvedYandexBillingAccountId();
        if ($accountId !== '' && ! preg_match('/^[A-Za-z0-9_-]{1,50}$/', $accountId)) {
            return [
                'ok' => false,
                'supported' => true,
                'provider' => 'yandex',
                'message' => 'Некорректный ID платёжного аккаунта Яндекса.',
            ];
        }

        $url = $accountId !== ''
            ? 'https://billing.api.cloud.yandex.net/billing/v1/billingAccounts/'.$accountId
            : 'https://billing.api.cloud.yandex.net/billing/v1/billingAccounts';

        try {
            $response = Http::timeout(8)
                ->withToken($token)
                ->acceptJson()
                ->get($url);
        } catch (\Throwable $e) {
            report($e);

            return [
                'ok' => false,
                'supported' => true,
                'provider' => 'yandex',
                'message' => 'Не удалось получить баланс Яндекса.',
            ];
        }

        if ($response->status() === 401) {
            return [
                'ok' => false,
                'supported' => true,
                'provider' => 'yandex',
                'message' => 'IAM-токен Яндекса не принят. Он живёт около 12 часов.',
            ];
        }

        if (! $response->successful()) {
            return [
                'ok' => false,
                'supported' => true,
                'provider' => 'yandex',
                'message' => 'Яндекс не отдал баланс.',
            ];
        }

        $accounts = $accountId !== ''
            ? [$response->json()]
            : $response->json('billingAccounts');
        if (! is_array($accounts)) {
            return [
                'ok' => false,
                'supported' => true,
                'provider' => 'yandex',
                'message' => 'Яндекс вернул пустой баланс.',
            ];
        }

        $balances = [];
        foreach ($accounts as $account) {
            if (! is_array($account)) {
                continue;
            }
            $total = trim((string) ($account['balance'] ?? ''));
            $currency = trim((string) ($account['currency'] ?? ''));
            if ($total === '' || $currency === '') {
                continue;
            }
            $balances[] = [
                'currency' => $currency,
                'total' => $total,
                'granted' => '',
                'topped_up' => '',
                'cash' => '',
                'credit' => '',
                'name' => trim((string) ($account['name'] ?? '')),
                'active' => (bool) ($account['active'] ?? true),
            ];
        }

        if ($balances === []) {
            return [
                'ok' => false,
                'supported' => true,
                'provider' => 'yandex',
                'message' => 'Яндекс вернул пустой баланс.',
            ];
        }

        $anyActive = false;
        foreach ($balances as $row) {
            if ($row['active'] && (float) $row['total'] > 0) {
                $anyActive = true;
                break;
            }
        }

        return [
            'ok' => true,
            'supported' => true,
            'provider' => 'yandex',
            'is_available' => $anyActive,
            'balances' => $balances,
        ];
    }
}
