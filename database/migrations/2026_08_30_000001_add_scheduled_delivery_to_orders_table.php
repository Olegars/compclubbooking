<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('booking_id')->nullable()->after('user_id');
            $table->timestamp('fulfill_at')->nullable()->index();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('session_starts_at')->nullable();

            $table->index('booking_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['booking_id']);
            $table->dropIndex(['fulfill_at']);
            $table->dropColumn(['booking_id', 'fulfill_at', 'released_at', 'session_starts_at']);
        });
    }
};
