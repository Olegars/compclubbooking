<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wled_controllers', function (Blueprint $table) {
            $table->json('effects')->nullable();
            $table->text('effects_error')->nullable();
            $table->timestamp('effects_synced_at')->nullable();
            $table->timestamp('effects_sync_requested_at')->nullable();
            $table->timestamp('effects_sync_claimed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('wled_controllers', function (Blueprint $table) {
            $table->dropColumn([
                'effects',
                'effects_error',
                'effects_synced_at',
                'effects_sync_requested_at',
                'effects_sync_claimed_at',
            ]);
        });
    }
};
