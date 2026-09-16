<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['inertia.testing.ensure_pages_exist' => false]);
    }

    protected function setUpTraits()
    {
        $this->guardAgainstLiveDatabase();

        return parent::setUpTraits();
    }

    protected function fakeImageUpload(string $name = 'photo.jpg'): UploadedFile
    {
        if (function_exists('imagecreatetruecolor')) {
            return UploadedFile::fake()->image($name);
        }

        return UploadedFile::fake()->create($name, 12, 'image/jpeg');
    }

    /**
     * Laravel 13 мержит Http::fake(), а не заменяет. Первый колбэк всегда выигрывает.
     */
    protected function fakeHttp(callable $callback): void
    {
        $factory = new HttpFactory(app('events'));
        Http::swap($factory);
        $this->app->instance(HttpFactory::class, $factory);
        Http::fake($callback);
    }

    private function guardAgainstLiveDatabase(): void
    {
        $name = (string) config('database.default');
        $database = (string) config("database.connections.{$name}.database");
        $allowed = $name === 'sqlite'
            || $database === ':memory:'
            || str_contains(strtolower($database), 'test');

        if ($allowed) {
            return;
        }

        throw new RuntimeException(
            "Отказ: автотесты не должны идти в боевую БД {$name}:{$database}. Нужны sqlite/:memory: или имя с «test»."
        );
    }
}
