<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Сетка день/время поверх цены зоны.
 *
 * Группы дней (будни / выходные) + календарные переопределения дат.
 * У строки tariff_prices появляются день и интервал времени.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('day_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('color')->default('#22c55e');
            // ISO: 1=пн … 7=вс
            $table->json('weekdays');
            $table->integer('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('calendar_day_overrides', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->foreignId('day_group_id')->constrained()->cascadeOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();
        });

        $now = now();
        $weekdaysId = DB::table('day_groups')->insertGetId([
            'name' => 'Будни',
            'color' => '#38bdf8',
            'weekdays' => json_encode([1, 2, 3, 4, 5]),
            'sort' => 10,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $weekendId = DB::table('day_groups')->insertGetId([
            'name' => 'Выходные',
            'color' => '#fbbf24',
            'weekdays' => json_encode([6, 7]),
            'sort' => 20,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $allDaysId = DB::table('day_groups')->insertGetId([
            'name' => 'Все дни',
            'color' => '#22c55e',
            'weekdays' => json_encode([1, 2, 3, 4, 5, 6, 7]),
            'sort' => 5,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Schema::table('tariff_prices', function (Blueprint $table) {
            $table->foreignId('day_group_id')->nullable()->after('zone_id')
                ->constrained('day_groups')->cascadeOnDelete();
            // Минуты от полуночи: start включительно, end исключительно (1440 = конец суток).
            $table->unsignedSmallInteger('time_start')->default(0)->after('day_group_id');
            $table->unsignedSmallInteger('time_end')->default(1440)->after('time_start');
        });

        // Старые плоские цены → правило «все дни, весь день».
        DB::table('tariff_prices')->whereNull('day_group_id')->update([
            'day_group_id' => $allDaysId,
            'time_start' => 0,
            'time_end' => 1440,
        ]);

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE tariff_prices ALTER COLUMN day_group_id SET NOT NULL');
        }

        DB::statement('DROP INDEX IF EXISTS tariff_prices_club_zone_unique');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS tariff_prices_schedule_unique
            ON tariff_prices (tariff_id, club_id, zone_id, day_group_id, time_start, time_end)');

        // weekdays/weekend ids reserved for future UI defaults — silence unused if needed
        unset($weekdaysId, $weekendId);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS tariff_prices_schedule_unique');

        Schema::table('tariff_prices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('day_group_id');
            $table->dropColumn(['time_start', 'time_end']);
        });

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS tariff_prices_club_zone_unique
            ON tariff_prices (tariff_id, club_id, zone_id)');

        Schema::dropIfExists('calendar_day_overrides');
        Schema::dropIfExists('day_groups');
    }
};
