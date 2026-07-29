<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Разводит тарификацию на две независимые оси.
 *
 * Класс места отвечает за железо и живёт на уровне сети.
 * Тип помещения (zones) отвечает за формат посадки, его экземпляры на карте —
 * это spaces. Цена собирается из пары клуб + класс, а тип помещения при
 * необходимости её перекрывает.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seat_classes', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('monitor')->nullable();
            $table->string('gpu')->nullable();
            $table->string('cpu')->nullable();
            $table->json('highlights')->nullable();
            $table->string('color')->nullable();
            $table->string('kind')->default('pc');
            $table->integer('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort']);
        });

        // Тип помещения теперь несёт условия выкупа: скидка одинакова
        // для всех комнат одного типа.
        Schema::table('zones', function (Blueprint $table) {
            $table->decimal('buyout_discount_percent', 5, 2)->nullable()->after('color');
            $table->integer('sort')->default(0)->after('buyout_discount_percent');
        });

        // Экземпляр помещения на карте конкретного клуба. Одна строка на
        // прямоугольник, поэтому три комнаты типа duo не сливаются в одну.
        Schema::create('spaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zone_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->double('x')->default(0);
            $table->double('y')->default(0);
            $table->double('w')->default(0);
            $table->double('h')->default(0);
            $table->integer('sort')->default(0);
            $table->timestamps();

            $table->index(['club_id', 'zone_id']);
        });

        Schema::table('computers', function (Blueprint $table) {
            $table->foreignId('seat_class_id')->nullable()->after('type')
                ->constrained('seat_classes')->nullOnDelete();
            $table->foreignId('space_id')->nullable()->after('seat_class_id')
                ->constrained('spaces')->nullOnDelete();
        });

        // Матрица цен. zone_id = NULL — базовая цена класса в клубе,
        // заполненный zone_id перекрывает её для этого типа помещений.
        Schema::create('tariff_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tariff_id')->constrained()->cascadeOnDelete();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seat_class_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained()->cascadeOnDelete();
            $table->decimal('price', 10, 2);
            $table->timestamps();

            $table->index(['club_id', 'seat_class_id']);
        });

        // Postgres считает NULL-ы различными, поэтому обычный unique не защитил бы
        // от дублей базовой цены — нужны два частичных индекса.
        DB::statement('CREATE UNIQUE INDEX tariff_prices_base_unique
            ON tariff_prices (tariff_id, club_id, seat_class_id)
            WHERE zone_id IS NULL');

        DB::statement('CREATE UNIQUE INDEX tariff_prices_zone_unique
            ON tariff_prices (tariff_id, club_id, seat_class_id, zone_id)
            WHERE zone_id IS NOT NULL');

        // Цена уехала в матрицу; колонки остаются до отдельной миграции-контракта.
        Schema::table('tariffs', function (Blueprint $table) {
            $table->string('category')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('computers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('space_id');
            $table->dropConstrainedForeignId('seat_class_id');
        });

        Schema::dropIfExists('tariff_prices');
        Schema::dropIfExists('spaces');
        Schema::dropIfExists('seat_classes');

        Schema::table('zones', function (Blueprint $table) {
            $table->dropColumn(['buyout_discount_percent', 'sort']);
        });

        Schema::table('tariffs', function (Blueprint $table) {
            $table->string('category')->default('standard')->change();
        });
    }
};
