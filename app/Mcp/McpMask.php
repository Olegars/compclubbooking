<?php

namespace App\Mcp;

final class McpMask
{
    /** @var list<string> */
    private const DROP_KEYS = [
        'password', 'remember_token', 'token', 'token_hash', 'secret', 'api_key',
        'passport', 'snils', 'inn', 'hwid', 'hwid_digest', 'connect_token',
        'authorization', 'files', 'presence_meta', 'payload', 'integrity_flags',
    ];

    public static function phone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        if (strlen($digits) < 4) {
            return '***';
        }

        return '***'.substr($digits, -4);
    }

    public static function email(?string $email): string
    {
        $email = trim((string) $email);
        if ($email === '' || ! str_contains($email, '@')) {
            return '***';
        }
        $parts = explode('@', $email, 2);

        return mb_substr($parts[0], 0, 1).'***@'.$parts[1];
    }

    public static function text(string $text): string
    {
        $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', '***@***', $text) ?? $text;
        $text = preg_replace('/(?:\+?\d[\d\-\s()]{8,}\d)/u', '***', $text) ?? $text;
        $text = preg_replace('/(token|password|secret|bearer|authorization)\s*[:=]\s*\S+/iu', '$1=***', $text) ?? $text;

        return $text;
    }

    public static function scrub(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null) {
            $normalized = strtolower($key);
            if (in_array($normalized, self::DROP_KEYS, true)) {
                return '***';
            }
            if ($normalized === 'phone' || str_ends_with($normalized, '_phone')) {
                return self::phone(is_scalar($value) ? (string) $value : null);
            }
            if ($normalized === 'email' || str_ends_with($normalized, '_email')) {
                return self::email(is_scalar($value) ? (string) $value : null);
            }
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $childKey => $child) {
                $out[$childKey] = self::scrub($child, is_string($childKey) ? $childKey : null);
            }

            return $out;
        }

        return $value;
    }
}
