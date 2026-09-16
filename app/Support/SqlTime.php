<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

class SqlTime
{
    public static function isSqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }

    /**
     * Текущий момент в SQL.
     * sqlite не знает NOW(); datetime('now') — UTC, а Eloquent читает datetime
     * в таймзоне приложения → last_seen_at кажется старше на несколько часов.
     * Берём Carbon::now() (уважает setTestNow и app timezone).
     */
    public static function now(): Expression
    {
        return DB::raw(self::nowSql());
    }

    public static function nowSql(): string
    {
        if (self::isSqlite()) {
            return "'".CarbonImmutable::now()->format('Y-m-d H:i:s')."'";
        }

        return 'NOW()';
    }

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

    public static function binding(DateTimeInterface $time): string
    {
        $c = CarbonImmutable::parse($time);
        if (self::isSqlite()) {
            return $c->utc()->format('Y-m-d H:i:s');
        }

        return $c->utc()->toIso8601String();
    }

    /**
     * Сравнение timestamptz-колонки: PG через ::timestamptz, sqlite — через Carbon.
     */
    public static function applyWhere(
        EloquentBuilder|QueryBuilder $query,
        string $column,
        string $operator,
        DateTimeInterface $time
    ): void {
        if (self::isSqlite()) {
            $query->where($column, $operator, $time);

            return;
        }

        $query->whereRaw($column.' '.$operator.' '.self::instant(), [self::binding($time)]);
    }
}
