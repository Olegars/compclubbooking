<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dmx_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('host');
            $table->unsignedInteger('port')->default(6454);
            $table->unsignedSmallInteger('universe')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['club_id', 'is_active']);
        });

        Schema::create('space_lights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dmx_node_id')->constrained('dmx_nodes')->cascadeOnDelete();
            $table->unsignedSmallInteger('start_channel')->default(1);
            $table->unsignedSmallInteger('fixture_count')->default(1);
            // rgb | dimmer_rgb | rgbw
            $table->string('layout', 16)->default('rgb');
            $table->string('desired_color', 16)->default('white');
            $table->unsignedTinyInteger('desired_brightness')->default(0);
            $table->string('desired_effect', 16)->default('none');
            $table->string('applied_color', 16)->default('white');
            $table->unsignedTinyInteger('applied_brightness')->default(0);
            $table->string('applied_effect', 16)->default('none');
            $table->string('last_on_color', 16)->default('white');
            $table->unsignedTinyInteger('last_on_brightness')->default(80);
            $table->string('last_on_effect', 16)->default('none');
            $table->boolean('vacant')->default(true);
            $table->text('last_error')->nullable();
            $table->timestamp('last_applied_at')->nullable();
            $table->timestamp('last_manual_at')->nullable();
            $table->unsignedBigInteger('last_manual_by_computer_id')->nullable();
            $table->unsignedBigInteger('last_applied_by_computer_id')->nullable();
            $table->timestamps();

            $table->unique('space_id');
            $table->index(['club_id', 'space_id']);
            $table->index(['dmx_node_id', 'start_channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('space_lights');
        Schema::dropIfExists('dmx_nodes');
    }
};
