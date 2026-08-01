<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Цена живёт на типе помещения (сингл / дуо / …).
 * Особенная комната получает свою доплату «+» в ₽/час — без сингл++.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::table('spaces', function (Blueprint $table) use ($driver) {
            if (! Schema::hasColumn('spaces', 'surcharge_per_hour')) {
                $column = $table->decimal('surcharge_per_hour', 10, 2)->default(0);
                if ($driver === 'pgsql') {
                    $column->after('h');
                }
            }
        });

        // Старая матрица «класс × зона» больше не нужна — цены задаются по зоне.
        DB::table('tariff_prices')->delete();

        if (Schema::hasColumn('tariff_prices', 'seat_class_id')) {
            Schema::table('tariff_prices', function (Blueprint $table) {
                $table->dropConstrainedForeignId('seat_class_id');
            });
        }

        DB::statement('DROP INDEX IF EXISTS tariff_prices_base_unique');
        DB::statement('DROP INDEX IF EXISTS tariff_prices_zone_unique');

        // zone_id становится обязательным: нет цены без типа помещения.
        if (Schema::hasColumn('tariff_prices', 'zone_id') && $driver === 'pgsql') {
            DB::statement('ALTER TABLE tariff_prices ALTER COLUMN zone_id SET NOT NULL');
        }

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS tariff_prices_club_zone_unique
            ON tariff_prices (tariff_id, club_id, zone_id)');
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        DB::statement('DROP INDEX IF EXISTS tariff_prices_club_zone_unique');

        Schema::table('tariff_prices', function (Blueprint $table) {
            if (! Schema::hasColumn('tariff_prices', 'seat_class_id')) {
                $table->foreignId('seat_class_id')->nullable()->constrained()->nullOnDelete();
            }
        });

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE tariff_prices ALTER COLUMN zone_id DROP NOT NULL');
        }

        Schema::table('spaces', function (Blueprint $table) {
            if (Schema::hasColumn('spaces', 'surcharge_per_hour')) {
                $table->dropColumn('surcharge_per_hour');
            }
        });
    }
};
