<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('computer_sos_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('computer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            // peripherals | auth_help | other
            $table->string('reason_code', 64);
            $table->string('reason_label', 255);
            $table->json('payload')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['computer_id', 'resolved_at']);
            $table->index(['reason_code', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('computer_sos_alerts');
    }
};
