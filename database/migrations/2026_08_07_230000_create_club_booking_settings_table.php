<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('club_booking_settings', function (Blueprint $table) {
            $table->id();
            // За сколько минут до starts_at гость ещё может отменить бронь с возвратом.
            // 120 = за 2 часа. 0 = можно до момента старта.
            $table->unsignedInteger('cancel_before_minutes')->default(120);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_booking_settings');
    }
};
