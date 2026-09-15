<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_clips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('computer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('path');
            $table->unsignedInteger('bytes')->default(0);
            $table->unsignedSmallInteger('duration_sec')->default(60);
            $table->string('share_token', 48)->unique();
            $table->timestampTz('telegram_sent_at')->nullable();
            $table->string('telegram_error', 255)->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_clips');
    }
};
