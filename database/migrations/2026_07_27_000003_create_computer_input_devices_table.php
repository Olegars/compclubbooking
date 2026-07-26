<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('computer_input_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('computer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            // Expected fingerprint: mouse/keyboard VID, PID, serial, instance_id, etc.
            $table->json('fingerprint')->nullable();
            $table->timestamp('bound_at')->nullable();
            $table->timestamps();

            $table->unique('computer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('computer_input_devices');
    }
};
