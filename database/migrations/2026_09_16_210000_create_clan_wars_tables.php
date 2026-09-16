<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clan_ratings', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16);
            $table->unsignedBigInteger('club_id')->nullable()->index();
            $table->string('faction_key', 48);
            $table->string('name', 120);
            $table->unsignedInteger('rating')->default(1000);
            $table->unsignedInteger('wars_played')->default(0);
            $table->unsignedInteger('wars_won')->default(0);
            $table->unsignedInteger('wars_drawn')->default(0);
            $table->unsignedInteger('points_total')->default(0);
            $table->timestamps();

            $table->unique(['kind', 'club_id', 'faction_key'], 'clan_ratings_faction_unique');
        });

        Schema::create('clan_wars', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('host_club_id')->nullable()->index();
            $table->string('name', 120);
            $table->string('mode', 16);
            $table->string('game', 16)->default('any');
            $table->string('status', 16)->default('planned')->index();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->default(60);
            $table->string('side_a_type', 16);
            $table->string('side_a_key', 48);
            $table->string('side_a_label', 120);
            $table->unsignedBigInteger('side_a_club_id')->nullable()->index();
            $table->string('side_b_type', 16);
            $table->string('side_b_key', 48);
            $table->string('side_b_label', 120);
            $table->unsignedBigInteger('side_b_club_id')->nullable()->index();
            $table->unsignedInteger('score_a')->default(0);
            $table->unsignedInteger('score_b')->default(0);
            $table->unsignedInteger('wins_a')->default(0);
            $table->unsignedInteger('wins_b')->default(0);
            $table->unsignedInteger('rounds_a')->default(0);
            $table->unsignedInteger('rounds_b')->default(0);
            $table->string('winner_side', 8)->nullable();
            $table->timestamp('rating_applied_at')->nullable();
            $table->timestamps();
        });

        Schema::create('clan_war_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clan_war_id')->constrained('clan_wars')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('computer_id')->nullable()->index();
            $table->unsignedBigInteger('club_id')->nullable()->index();
            $table->string('side', 8);
            $table->string('event', 24);
            $table->string('game', 16)->default('cs2');
            $table->string('dedupe_key', 80);
            $table->unsignedInteger('points')->default(0);
            $table->timestamp('created_at')->nullable();

            $table->unique(['clan_war_id', 'dedupe_key'], 'clan_war_events_dedupe');
            $table->index(['clan_war_id', 'side']);
        });

        Schema::create('clan_player_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('clan_rating_id')->constrained('clan_ratings')->cascadeOnDelete();
            $table->unsignedInteger('rating')->default(1000);
            $table->unsignedInteger('wars_played')->default(0);
            $table->unsignedInteger('points_contributed')->default(0);
            $table->unsignedBigInteger('last_war_id')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'clan_rating_id'], 'clan_player_ratings_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clan_player_ratings');
        Schema::dropIfExists('clan_war_events');
        Schema::dropIfExists('clan_wars');
        Schema::dropIfExists('clan_ratings');
    }
};
