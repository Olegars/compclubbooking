<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arena_duels', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('club_id')->index();
            $table->foreignId('creator_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('creator_computer_id')->constrained('computers')->cascadeOnDelete();
            $table->foreignId('creator_booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->foreignId('target_computer_id')->nullable()->constrained('computers')->nullOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('scope', 16)->default('hall');
            $table->string('zone_group', 24)->nullable();
            $table->string('game', 16)->default('cs2');
            $table->string('mode', 32)->default('1v1_aim');
            $table->decimal('entry_fee', 10, 2);
            $table->decimal('total_pot', 10, 2)->default(0);
            $table->decimal('rake_percent', 5, 2)->default(10);
            $table->decimal('rake_amount', 10, 2)->default(0);
            $table->decimal('winner_prize', 10, 2)->default(0);
            $table->unsignedTinyInteger('first_to')->default(8);
            $table->string('status', 24)->default('pending')->index();
            $table->foreignId('winner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('match_data_snapshot')->nullable();
            $table->string('server_connect_uri', 180)->nullable();
            $table->string('server_password', 32)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['club_id', 'status']);
            $table->index(['target_computer_id', 'status']);
            $table->index(['creator_user_id', 'status']);
        });

        Schema::create('arena_duel_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('duel_id')->constrained('arena_duels')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('computer_id')->constrained('computers')->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->unsignedTinyInteger('team_slot')->default(1);
            $table->string('escrow_status', 24)->default('held');
            $table->unsignedInteger('rounds_won')->default(0);
            $table->timestamp('last_gsi_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['duel_id', 'user_id']);
            $table->index(['computer_id', 'escrow_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arena_duel_participants');
        Schema::dropIfExists('arena_duels');
    }
};
