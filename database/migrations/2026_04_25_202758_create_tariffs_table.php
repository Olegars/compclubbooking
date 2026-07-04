<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tariffs', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Например: "Пакет 3 часа", "Ночной марафон"
            $table->string('category')->default('standard'); // standard, vip, boot_camp

            // Порог срабатывания (в часах)
            $table->integer('threshold_hours');

            // Стоимость за весь пакет
            $table->decimal('price_per_package', 10, 2);

            // Дополнительные настройки
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Индекс для быстрого поиска по часам и категориям
            $table->index(['category', 'threshold_hours']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tariffs');
    }
};
