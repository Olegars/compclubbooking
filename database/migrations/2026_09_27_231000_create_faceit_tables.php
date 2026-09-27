<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faceit_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('faceit_player_id')->unique();
            $table->string('nickname')->nullable();
            $table->string('avatar_url')->nullable();
            $table->string('steam_id_64', 32)->nullable();
            $table->unsignedTinyInteger('skill_level')->nullable();
            $table->unsignedInteger('elo')->nullable();
            $table->string('game_id', 16)->default('cs2');
            $table->decimal('kd', 6, 2)->nullable();
            $table->decimal('win_rate', 5, 2)->nullable();
            $table->timestampTz('banned_until')->nullable();
            $table->timestampTz('synced_at')->nullable();
            $table->timestampTz('rate_limited_at')->nullable();
            $table->timestamps();
        });

        Schema::create('faceit_matches', function (Blueprint $table) {
            $table->id();
            $table->string('match_id')->unique();
            $table->string('hub_id')->nullable();
            $table->string('championship_id')->nullable();
            $table->string('status', 32);
            $table->string('map')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->json('payload')->nullable();
            $table->string('demo_url')->nullable();
            $table->timestamps();
        });

        Schema::create('faceit_match_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faceit_match_id')->constrained('faceit_matches')->cascadeOnDelete();
            $table->string('faceit_player_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('faction', 32)->nullable();
            $table->unsignedInteger('elo_before')->nullable();
            $table->unsignedInteger('elo_after')->nullable();
            $table->timestamps();
            $table->unique(['faceit_match_id', 'faceit_player_id']);
            $table->index('faceit_player_id');
        });

        Schema::create('faceit_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('event_name', 64);
            $table->string('match_id')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });

        Schema::table('tournaments', function (Blueprint $table) {
            $table->string('faceit_championship_id', 64)->nullable();
        });

        if (Schema::hasTable('quick_apps')) {
            \Illuminate\Support\Facades\DB::table('quick_apps')
                ->where('title', 'FACEIT')
                ->update(['exe_path' => 'C:\\Program Files\\FACEIT\\FACEIT.exe']);
        }
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn('faceit_championship_id');
        });
        Schema::dropIfExists('faceit_webhook_events');
        Schema::dropIfExists('faceit_match_players');
        Schema::dropIfExists('faceit_matches');
        Schema::dropIfExists('faceit_identities');
    }
};
