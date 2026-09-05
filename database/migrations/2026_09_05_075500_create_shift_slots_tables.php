<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_slot_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->nullable()->constrained('clubs')->nullOnDelete();
            $table->string('name');
            $table->time('starts_time');
            $table->unsignedTinyInteger('duration_hours')->default(12);
            $table->unsignedTinyInteger('intern_capacity')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['club_id', 'name']);
        });

        Schema::create('shift_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->nullable()->constrained('clubs')->nullOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('shift_slot_templates')->nullOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unsignedTinyInteger('intern_capacity')->default(1);
            $table->timestamps();

            $table->unique(['club_id', 'starts_at', 'template_id']);
            $table->index(['starts_at', 'ends_at']);
        });

        Schema::create('shift_slot_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_slot_id')->constrained('shift_slots')->cascadeOnDelete();
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('status', 16)->default('booked');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['shift_slot_id', 'status', 'kind']);
            $table->index(['admin_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_slot_bookings');
        Schema::dropIfExists('shift_slots');
        Schema::dropIfExists('shift_slot_templates');
    }
};
