<?php

namespace Tests;

final class TestingEnv
{
    /**
     * Обход php artisan config:cache: иначе PHPUnit поднимает боевой pgsql из bootstrap/cache/config.php.
     *
     * @return array<string, string>
     */
    public static function values(?string $testingDir = null): array
    {
        $testingDir ??= dirname(__DIR__).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'testing';

        return [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
            'DATABASE_URL' => '',
            'DB_HOST' => '',
            'DB_PORT' => '',
            'DB_USERNAME' => '',
            'DB_PASSWORD' => '',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'APP_CONFIG_CACHE' => $testingDir.DIRECTORY_SEPARATOR.'no-config.php',
            'APP_ROUTES_CACHE' => $testingDir.DIRECTORY_SEPARATOR.'no-routes.php',
            'APP_EVENTS_CACHE' => $testingDir.DIRECTORY_SEPARATOR.'no-events.php',
            'APP_PACKAGES_CACHE' => $testingDir.DIRECTORY_SEPARATOR.'no-packages.php',
            'APP_SERVICES_CACHE' => $testingDir.DIRECTORY_SEPARATOR.'no-services.php',
        ];
    }

    public static function apply(): void
    {
        foreach (self::values() as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}
