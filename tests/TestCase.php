<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUpTraits()
    {
        $this->guardAgainstLiveDatabase();

        return parent::setUpTraits();
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
