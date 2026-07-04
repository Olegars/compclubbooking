<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // Например: 'late_order', 'manual_balance_edit'
            $table->text('description'); // Подробности: "Заказ #12 висит 8 минут"
            $table->string('severity')->default('medium'); // low, medium, high, critical
            $table->integer('order_id')->nullable(); // Ссылка на заказ, если есть
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
