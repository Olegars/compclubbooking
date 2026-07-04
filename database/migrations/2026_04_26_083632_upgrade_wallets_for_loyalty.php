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
        // 1. Проверяем, существует ли старая колонка balance, прежде чем переименовывать
        if (Schema::hasColumn('wallets', 'balance')) {
            Schema::table('wallets', function (Blueprint $table) {
                $table->renameColumn('balance', 'deposit_balance');
            });
        }

        // 2. Проверяем наличие новых колонок перед их созданием
        Schema::table('wallets', function (Blueprint $table) {
            if (!Schema::hasColumn('wallets', 'bonus_balance')) {
                $table->decimal('bonus_balance', 10, 2)->default(0)->after('deposit_balance');
            }

            if (!Schema::hasColumn('wallets', 'total_spent')) {
                $table->decimal('total_spent', 10, 2)->default(0)->after('bonus_balance');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
