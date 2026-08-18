<?php

namespace App\Services\AiAssistant;

use Illuminate\Http\Client\Response;

class YandexCloudAuth
{
    public static function normalizeKey(string $key): string
    {
        $key = trim($key);
        $key = trim($key, "\"'");
        if (stripos($key, 'Api-Key ') === 0) {
            $key = trim(substr($key, 8));
        } elseif (stripos($key, 'Bearer ') === 0) {
            $key = trim(substr($key, 7));
        }

        return $key;
    }

    public static function authorizationHeader(string $key): string
    {
        $key = self::normalizeKey($key);
        if (str_starts_with($key, 't1.') || str_starts_with($key, 'y0_') || str_starts_with($key, 'y3_')) {
            return 'Bearer '.$key;
        }

        return 'Api-Key '.$key;
    }

    public static function usesApiKey(string $key): bool
    {
        return str_starts_with(self::authorizationHeader($key), 'Api-Key ');
    }

    /**
     * Folder ID only for IAM/OAuth. Service-account API keys already live in a folder;
     * sending x-folder-id / folderId makes SpeechKit check resource-manager.folder and 401.
     */
    public static function folderIdForRequest(string $key, string $folder): string
    {
        $folder = trim($folder);
        if ($folder === '' || self::usesApiKey($key)) {
            return '';
        }

        return $folder;
    }

    public static function httpError(string $label, Response $response): string
    {
        $body = $response->body();
        $lower = mb_strtolower($body);
        if (str_contains($lower, 'resource-manager.folder')
            || str_contains($lower, 'permissiondenied')
            || str_contains($lower, 'permission to')) {
            return $label.': нет доступа к каталогу Folder ID. Для ключа сервисного аккаунта оставьте Folder ID в админке пустым — Яндекс берёт каталог ключа.';
        }
        if ($response->status() === 401 || str_contains($lower, 'unauthor') || str_contains($lower, 'не авторизован')) {
            return $label.': Яндекс не принял ключ. Проверьте API-ключ SpeechKit и роли ai.speechkit-stt.user / ai.speechkit-tts.user.';
        }

        return $label.': HTTP '.$response->status().' '.$body;
    }
}
