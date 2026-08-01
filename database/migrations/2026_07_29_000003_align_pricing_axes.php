<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Догоняет схему до целевой структуры.
 *
 * Первая редакция осей успела примениться до того, как выяснилось, что зоны
 * описывают формат посадки, а не железо. Пересоздать ту миграцию нельзя —
 * Laravel считает её выполненной, поэтому недостающее добавляется здесь.
 *
 * Написана идемпотентно: отрабатывает и на схеме первой редакции, и на чистой.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->alignZones();
        $this->alignSpaces();
        $this->alignTariffPrices();

        // Классы первой редакции были сняты с прежних зон (standard, vip,
        // bootkamp, ps5) и потеряли смысл. Ссылки на них обнулятся каскадом.
        if (Schema::hasTable('seat_classes')) {
            DB::table('seat_classes')->delete();
        }
    }

    public function down(): void
    {
        // Обратного хода нет: структура первой редакции была ошибочной.
    }

    private function alignZones(): void
    {
        $addBuyout = ! Schema::hasColumn('zones', 'buyout_discount_percent');
        $addSort = ! Schema::hasColumn('zones', 'sort');

        if (! $addBuyout && ! $addSort) {
            return;
        }

        Schema::table('zones', function (Blueprint $table) use ($addBuyout, $addSort) {
            if ($addBuyout) {
                $table->decimal('buyout_discount_percent', 5, 2)->nullable();
            }
            if ($addSort) {
                $table->integer('sort')->default(0);
            }
        });
    }

    private function alignSpaces(): void
    {
        if (! Schema::hasTable('spaces')) {
            return;
        }

        // Комнаты первой редакции опирались на slug зоны. Они пересоздадутся
        // при сохранении карты, поэтому таблицу проще очистить, чем чинить.
        DB::table('spaces')->delete();

        if (! Schema::hasColumn('spaces', 'zone_id')) {
            Schema::table('spaces', function (Blueprint $table) {
                $table->foreignId('zone_id')->constrained()->cascadeOnDelete();
            });
        }

        $obsolete = array_values(array_filter(
            ['slug', 'color', 'buyout_discount_percent'],
            fn (string $column) => Schema::hasColumn('spaces', $column)
        ));

        if ($obsolete !== []) {
            Schema::table('spaces', function (Blueprint $table) use ($obsolete) {
                $table->dropColumn($obsolete);
            });
        }
    }

    private function alignTariffPrices(): void
    {
        if (! Schema::hasTable('tariff_prices')) {
            return;
        }

        DB::table('tariff_prices')->delete();

        if (! Schema::hasColumn('tariff_prices', 'zone_id')) {
            Schema::table('tariff_prices', function (Blueprint $table) {
                $table->foreignId('zone_id')->nullable()->constrained()->cascadeOnDelete();
            });
        }

        // Сплошной unique не подходит: Postgres считает NULL-ы различными,
        // и он не помешал бы завести две базовые цены на одну пару.
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE tariff_prices DROP CONSTRAINT IF EXISTS tariff_prices_unique');
        }
        DB::statement('DROP INDEX IF EXISTS tariff_prices_unique');

        // Partial unique indexes: supported by Postgres and SQLite.
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS tariff_prices_base_unique
            ON tariff_prices (tariff_id, club_id, seat_class_id)
            WHERE zone_id IS NULL');

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS tariff_prices_zone_unique
            ON tariff_prices (tariff_id, club_id, seat_class_id, zone_id)
            WHERE zone_id IS NOT NULL');
    }
};
