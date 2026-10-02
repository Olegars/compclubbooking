<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wled_controllers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('host');
            $table->unsignedInteger('http_port')->default(80);
            $table->boolean('is_active')->default(true);
            $table->boolean('idle_on')->default(false);
            $table->string('idle_color', 16)->default('white');
            $table->unsignedTinyInteger('idle_brightness')->default(15);
            $table->json('bindings');
            $table->text('last_error')->nullable();
            $table->timestamp('last_played_at')->nullable();
            $table->timestamps();

            $table->index(['club_id', 'is_active']);
        });

        Schema::create('wled_cues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wled_controller_id')->constrained('wled_controllers')->cascadeOnDelete();
            $table->string('event_id', 64);
            $table->unsignedTinyInteger('priority')->default(50);
            $table->json('payload');
            $table->timestamp('expires_at');
            $table->unsignedBigInteger('claimed_by_computer_id')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('played_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['club_id', 'played_at', 'expires_at']);
            $table->index(['wled_controller_id', 'played_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wled_cues');
        Schema::dropIfExists('wled_controllers');
    }
};
