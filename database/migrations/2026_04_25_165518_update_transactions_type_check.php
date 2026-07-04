<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        // 1. Удаляем старое ограничение (имя мы знаем из ошибки: transactions_type_check)
        DB::statement('ALTER TABLE transactions DROP CONSTRAINT transactions_type_check');

        // 2. Создаем новое, куда добавляем 'booking'
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_type_check CHECK (type IN ('deposit', 'withdraw', 'booking', 'refund'))");
    }

    public function down()
    {
        DB::statement('ALTER TABLE transactions DROP CONSTRAINT transactions_type_check');
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_type_check CHECK (type IN ('deposit', 'withdraw'))");
    }
};
