<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class SqlTime
{
    /**
     * Плейсхолдер ISO-8601 для сравнения timestamptz.
     * В Postgres нужен ::timestamptz, sqlite это не понимает.
     */
    public static function instant(string $placeholder = '?'): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? $placeholder.'::timestamptz'
            : $placeholder;
    }
}
