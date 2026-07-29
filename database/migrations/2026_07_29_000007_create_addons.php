<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Допы («+», PS и т.п.): справочник на странице тарифов,
 * привязка к комнате на карте.
 *
 * billing_mode=always — всегда в цене (как «+»).
 * billing_mode=optional — гость включает при брони (как PS к ТВ).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addons', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('color')->default('#22c55e');
            $table->string('billing_mode')->default('always'); // always|optional
            $table->integer('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('addon_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('addon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->decimal('price_per_hour', 10, 2);
            $table->timestamps();

            $table->unique(['addon_id', 'club_id']);
        });

        Schema::create('space_addon', function (Blueprint $table) {
            $table->id();
            $table->foreignId('space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('addon_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['space_id', 'addon_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('space_addon');
        Schema::dropIfExists('addon_prices');
        Schema::dropIfExists('addons');
    }
};
