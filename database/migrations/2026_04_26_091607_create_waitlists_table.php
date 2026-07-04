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
        Schema::create('waitlists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zone_id')->constrained()->cascadeOnDelete(); // Очередь идет по зонам (VIP, Standard)

            // Статусы: ждет, уведомлен (дал 10 мин на посадку), сел за ПК, отменил сам, пропустил время
            $table->enum('status', ['waiting', 'notified', 'completed', 'cancelled', 'timeout'])->default('waiting');

            $table->timestamp('notified_at')->nullable(); // Когда отправили сигнал, что комп свободен
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('waitlists');
    }
};
