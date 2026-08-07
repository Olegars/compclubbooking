<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('transactions', 'send_receipt')) {
                $table->boolean('send_receipt')->default(false)->after('fiscal_at');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'offer_accepted_at')) {
                $table->timestamp('offer_accepted_at')->nullable()->after('updated_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'send_receipt')) {
                $table->dropColumn('send_receipt');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'offer_accepted_at')) {
                $table->dropColumn('offer_accepted_at');
            }
        });
    }
};
