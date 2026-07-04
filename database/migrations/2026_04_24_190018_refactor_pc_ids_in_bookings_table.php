<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('pc_ids');

            // Добавляем nullable(), чтобы Postgres не ругался на старые записи
            $table->foreignId('computer_id')
                ->after('user_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // Откат изменений: удаляем ключ и возвращаем JSON
            $table->dropForeign(['computer_id']);
            $table->dropColumn('computer_id');
            $table->json('pc_ids')->nullable();
        });
    }
};
