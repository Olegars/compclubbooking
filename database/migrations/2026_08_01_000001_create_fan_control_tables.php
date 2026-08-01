<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relay_boards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // kincony_http | dingtian_http | …
            $table->string('driver', 64)->default('kincony_http');
            $table->string('host');
            $table->unsignedInteger('port')->default(80);
            $table->json('meta')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['club_id', 'is_active']);
        });

        Schema::create('space_fans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('relay_board_id')->constrained('relay_boards')->cascadeOnDelete();
            $table->unsignedSmallInteger('channel');
            // auto | force_on | force_off
            $table->string('manual_mode', 16)->default('auto');
            $table->unsignedTinyInteger('desired_power')->default(0);
            $table->unsignedTinyInteger('applied_power')->default(0);
            $table->unsignedTinyInteger('default_on_power')->default(100);
            $table->unsignedSmallInteger('thermal_on_c')->default(75);
            $table->unsignedSmallInteger('thermal_off_c')->default(65);
            $table->text('last_error')->nullable();
            $table->timestamp('last_applied_at')->nullable();
            $table->timestamps();

            $table->unique('space_id');
            $table->unique(['relay_board_id', 'channel']);
            $table->index(['club_id', 'space_id']);
        });

        Schema::create('computer_thermals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('computer_id')->constrained()->cascadeOnDelete();
            $table->decimal('cpu_c', 5, 1)->nullable();
            $table->boolean('is_hot')->default(false);
            $table->timestamp('reported_at')->nullable();
            $table->timestamps();

            $table->unique('computer_id');
            $table->index(['club_id', 'reported_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('computer_thermals');
        Schema::dropIfExists('space_fans');
        Schema::dropIfExists('relay_boards');
    }
};
