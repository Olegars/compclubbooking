<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('transactions', 'fiscal_mode')) {
                $table->string('fiscal_mode', 32)->nullable()->after('description');
            }
            if (! Schema::hasColumn('transactions', 'fiscal_status')) {
                $table->string('fiscal_status', 32)->nullable()->after('fiscal_mode');
            }
            if (! Schema::hasColumn('transactions', 'fiscal_receipt_url')) {
                $table->string('fiscal_receipt_url')->nullable()->after('fiscal_status');
            }
            if (! Schema::hasColumn('transactions', 'fiscal_error')) {
                $table->text('fiscal_error')->nullable()->after('fiscal_receipt_url');
            }
            if (! Schema::hasColumn('transactions', 'fiscal_at')) {
                $table->timestamp('fiscal_at')->nullable()->after('fiscal_error');
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE transactions DROP CONSTRAINT IF EXISTS transactions_type_check');
            DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_type_check CHECK (type IN (
                'deposit', 'withdraw', 'booking', 'booking_upgrade', 'refund', 'purchase'
            ))");
        }
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            foreach (['fiscal_mode', 'fiscal_status', 'fiscal_receipt_url', 'fiscal_error', 'fiscal_at'] as $col) {
                if (Schema::hasColumn('transactions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
