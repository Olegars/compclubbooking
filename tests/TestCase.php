<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        TestingEnv::apply();

        return parent::createApplication();
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['inertia.testing.ensure_pages_exist' => false]);
        if ($this->app->bound(\App\Services\ClubFeatureService::class)) {
            $this->app->make(\App\Services\ClubFeatureService::class)->flush();
        }
    }

    protected function setUpTraits()
    {
        $this->guardAgainstLiveDatabase();

        return parent::setUpTraits();
    }

    protected function fakeImageUpload(string $name = 'photo.jpg'): UploadedFile
    {
        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        $bytes = $ext === 'png' ? $this->tinyPngBytes() : $this->tinyJpegBytes();

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    protected function tinyJpegBytes(): string
    {
        $decoded = base64_decode(
            '/9j/4AAQSkZJRgABAQAAAQABAAD/2wAAAAD/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAj/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAGf/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPwB//9k=',
            true
        );

        return $decoded !== false && $decoded !== '' ? $decoded : "\xff\xd8\xff\xd9";
    }

    protected function tinyPngBytes(): string
    {
        $decoded = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true
        );

        return $decoded !== false ? $decoded : '';
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
