<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fire_panels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->string('host')->nullable();
            $table->unsignedInteger('port')->default(40000);
            $table->unsignedTinyInteger('panel_address')->default(1);
            $table->boolean('enabled')->default(false);
            $table->boolean('reset_pending')->default(false);
            $table->unsignedSmallInteger('fail_count')->default(0);
            $table->timestamp('last_ok_at')->nullable();
            $table->timestamp('last_reset_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();

            $table->unique('club_id');
        });

        Schema::create('fire_detectors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('space_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('device_address');
            $table->unsignedTinyInteger('loop_address');
            $table->boolean('smoke')->default(false);
            $table->unsignedSmallInteger('last_code')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['club_id', 'device_address', 'loop_address']);
            $table->index(['space_id', 'smoke']);
        });

        Schema::create('fire_alarms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('detector_id')->nullable()->constrained('fire_detectors')->nullOnDelete();
            $table->string('status', 16)->default('active');
            $table->timestamp('started_at');
            $table->timestamp('cleared_at')->nullable();
            $table->unsignedSmallInteger('last_code')->nullable();
            $table->timestamps();

            $table->index(['space_id', 'status']);
            $table->index(['club_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fire_alarms');
        Schema::dropIfExists('fire_detectors');
        Schema::dropIfExists('fire_panels');
    }
};
