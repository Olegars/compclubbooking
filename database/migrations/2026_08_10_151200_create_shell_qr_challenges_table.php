<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shell_qr_challenges', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->unique();
            $table->foreignId('computer_id')->constrained('computers')->cascadeOnDelete();
            $table->string('status', 20)->default('pending'); // pending|consumed|expired
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->timestampTz('expires_at');
            $table->timestamps();

            $table->index(['computer_id', 'status']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shell_qr_challenges');
    }
};
