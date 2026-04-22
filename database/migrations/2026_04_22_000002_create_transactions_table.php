<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->decimal('amount', 12, 2);
            $table->string('type'); // deposit, withdraw, refund
            $table->string('source'); // card, cash, bonus, booking
            $table->string('description')->nullable();
            $table->json('payload')->nullable(); // Сюда пишем детали (ID пакета, ID транзакции банка)
            $table->string('fiscal_status')->default('none'); // none, pending, success, error
            $table->string('fiscal_receipt_url')->nullable(); // Ссылка на электронный чек (ОФД)
            $table->text('fiscal_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('transactions');
    }
};
