<?php

namespace App\Services\AiAssistant;

use App\Models\AiAssistantSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AlibabaBalanceClient
{
    /**
     * Остаток счёта Alibaba Cloud. Это не QWEN_API_KEY, а пара AccessKey.
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
        $keyId = $settings->resolvedAlibabaAccessKeyId();
        $secret = $settings->resolvedAlibabaAccessKeySecret();
        if ($keyId === '' || $secret === '') {
            return [
                'ok' => false,
                'supported' => true,
                'provider' => 'qwen',
                'message' => 'Нет AccessKey Alibaba. Ключ Qwen для картинок баланс не отдаёт.',
            ];
        }

        $endpoint = $this->endpoint();
        $query = $this->signedQuery($keyId, $secret);

        try {
            $response = Http::timeout(8)
                ->acceptJson()
                ->get($endpoint.'/?'.$query);
        } catch (\Throwable $e) {
            report($e);

            return [
                'ok' => false,
                'supported' => true,
                'provider' => 'qwen',
                'message' => 'Не удалось получить баланс Qwen.',
            ];
        }

        $success = $response->json('Success');
        $code = (string) $response->json('Code');
        if (! $response->successful() || $success === false || ($code !== '' && $code !== '200')) {
            return [
                'ok' => false,
                'supported' => true,
                'provider' => 'qwen',
                'message' => 'Alibaba не отдала баланс Qwen.',
            ];
        }

        $data = $response->json('Data');
        if (! is_array($data)) {
            return [
                'ok' => false,
                'supported' => true,
                'provider' => 'qwen',
                'message' => 'Alibaba вернула пустой баланс.',
            ];
        }

        $total = trim((string) ($data['AvailableAmount'] ?? ''));
        $currency = trim((string) ($data['Currency'] ?? ''));
        if ($total === '' || $currency === '') {
            return [
                'ok' => false,
                'supported' => true,
                'provider' => 'qwen',
                'message' => 'Alibaba вернула пустой баланс.',
            ];
        }

        $cash = trim((string) ($data['AvailableCashAmount'] ?? ''));
        $credit = trim((string) ($data['CreditAmount'] ?? ''));

        return [
            'ok' => true,
            'supported' => true,
            'provider' => 'qwen',
            'is_available' => (float) $total > 0,
            'balances' => [[
                'currency' => $currency,
                'total' => $total,
                'granted' => '',
                'topped_up' => '',
                'cash' => $cash,
                'credit' => $credit,
                'name' => '',
                'active' => true,
            ]],
        ];
    }

    private function endpoint(): string
    {
        $configured = rtrim(trim((string) config('ai_assistant.alibaba_billing.endpoint', '')), '/');
        if ($configured !== '') {
            return $configured;
        }

        $image = (string) config('ai_assistant.avatar.qwen.base_url', '');
        if (str_contains($image, 'ap-southeast') || str_contains($image, 'dashscope-intl')) {
            return 'https://business.ap-southeast-1.aliyuncs.com';
        }

        return 'https://business.aliyuncs.com';
    }

    private function signedQuery(string $keyId, string $secret): string
    {
        $params = [
            'AccessKeyId' => $keyId,
            'Action' => 'QueryAccountBalance',
            'Format' => 'JSON',
            'SignatureMethod' => 'HMAC-SHA1',
            'SignatureNonce' => (string) Str::uuid(),
            'SignatureVersion' => '1.0',
            'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'Version' => '2017-12-14',
        ];
        ksort($params);

        $canonical = [];
        foreach ($params as $name => $value) {
            $canonical[] = $this->percentEncode((string) $name).'='.$this->percentEncode((string) $value);
        }
        $stringToSign = 'GET&'.$this->percentEncode('/').'&'.$this->percentEncode(implode('&', $canonical));
        $params['Signature'] = base64_encode(hash_hmac('sha1', $stringToSign, $secret.'&', true));

        $query = [];
        foreach ($params as $name => $value) {
            $query[] = $this->percentEncode((string) $name).'='.$this->percentEncode((string) $value);
        }

        return implode('&', $query);
    }

    private function percentEncode(string $value): string
    {
        return str_replace(['+', '*', '%7E'], ['%20', '%2A', '~'], rawurlencode($value));
    }
}
