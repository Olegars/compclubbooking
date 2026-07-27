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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained(); // Null если гость/покупка на кассе
            $table->decimal('amount', 10, 2); // Положительная (доход) или отрицательная (возврат/расход)
            // String + explicit PostgreSQL CHECK in later migrations keeps SQLite tests portable.
            $table->string('type')->default('deposit');
            $table->string('payment_method')->default('card');
            $table->string('description');
            $table->string('receipt_id')->nullable(); // ID фискального чека (ОФД)
            $table->boolean('is_taxable')->default(true); // Флаг: облагается ли налогом (бонусы - нет)
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
