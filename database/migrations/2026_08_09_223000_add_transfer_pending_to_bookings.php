<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedBigInteger('transfer_from_computer_id')->nullable()->after('computer_id');
            $table->timestampTz('transfer_pending_at')->nullable()->after('pin_code');
            $table->index('transfer_pending_at');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['transfer_pending_at']);
            $table->dropColumn(['transfer_from_computer_id', 'transfer_pending_at']);
        });
    }
};
