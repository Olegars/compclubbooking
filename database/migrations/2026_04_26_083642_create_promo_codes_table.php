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
        Schema::create('promo_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // 'SUMMER26'
            $table->enum('type', ['bonus_money', 'discount']); // Тип
            $table->decimal('value', 10, 2); // 300 рублей или 10%
            $table->integer('max_uses')->default(1); // Лимит активаций
            $table->integer('used_count')->default(0);
            $table->timestamps();
        });

        // Таблица для фиксации, кто уже вводил промокод (чтобы не абузили)
        Schema::create('promo_code_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promo_code_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promo_codes');
    }
};
