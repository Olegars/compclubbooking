<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lan_bounties', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('club_id')->index();
            $table->foreignId('poster_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('poster_computer_id')->constrained('computers')->cascadeOnDelete();
            $table->foreignId('poster_booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->foreignId('target_computer_id')->constrained('computers')->cascadeOnDelete();
            $table->foreignId('hunter_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('hunter_computer_id')->nullable()->constrained('computers')->nullOnDelete();
            $table->string('kind', 16)->default('frag');
            $table->string('game', 16)->default('cs2');
            $table->string('weapon', 32)->default('any');
            $table->string('title', 180);
            $table->string('stake_type', 16)->default('deposit');
            $table->decimal('stake_amount', 10, 2)->default(0);
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('product_name', 180)->nullable();
            $table->string('status', 16)->default('open')->index();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['club_id', 'status']);
            $table->index(['target_computer_id', 'status']);
        });

        Schema::create('lan_bounty_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('club_id')->index();
            $table->foreignId('computer_id')->constrained('computers')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->string('steam_id', 32)->nullable();
            $table->string('event', 24);
            $table->string('game', 16)->default('cs2');
            $table->string('weapon', 64)->nullable();
            $table->string('map', 64)->nullable();
            $table->string('match_id', 48)->nullable();
            $table->unsignedInteger('round')->nullable();
            $table->timestamp('occurred_at');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['club_id', 'event', 'occurred_at']);
            $table->index(['computer_id', 'occurred_at']);
        });

        Schema::create('party_energy_pools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_group_id')->unique()->constrained('booking_groups')->cascadeOnDelete();
            $table->unsignedBigInteger('club_id')->index();
            $table->foreignId('captain_user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('minutes_remaining')->default(0);
            $table->boolean('auto_fuel')->default(false);
            $table->timestamp('last_siphon_at')->nullable();
            $table->timestamps();
        });

        Schema::table('user_settings', function (Blueprint $table) {
            $table->boolean('ghost_coach_enabled')->default(true)->after('light_interactive');
        });
    }

    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropColumn('ghost_coach_enabled');
        });
        Schema::dropIfExists('party_energy_pools');
        Schema::dropIfExists('lan_bounty_events');
        Schema::dropIfExists('lan_bounties');
    }
};
