<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achievement_badges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->nullable()->constrained()->nullOnDelete();
            $table->string('slug', 64);
            $table->string('name');
            $table->string('image_path')->nullable();
            $table->string('source_kind', 32)->default('club');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['club_id', 'slug']);
        });

        Schema::create('cosmetic_frames', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->nullable()->constrained()->nullOnDelete();
            $table->string('slug', 64);
            $table->string('name');
            $table->string('image_path')->nullable();
            $table->unsignedSmallInteger('min_level')->default(0);
            $table->string('unlock_achievement_code', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['club_id', 'slug']);
        });

        Schema::create('club_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->nullable()->constrained()->nullOnDelete();
            $table->string('key', 64);
            $table->string('label');
            $table->string('color', 32)->default('white');
            $table->unsignedSmallInteger('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['club_id', 'key']);
        });

        Schema::table('achievements', function (Blueprint $table) {
            $table->string('source_kind', 32)->default('club')->after('description');
            $table->string('code', 64)->nullable()->unique()->after('source_kind');
            $table->string('metric', 64)->nullable()->after('type');
            $table->unsignedSmallInteger('xp')->default(0)->after('reward_value');
            $table->foreignId('badge_id')->nullable()->after('xp')->constrained('achievement_badges')->nullOnDelete();
            $table->string('reward_kind', 32)->nullable()->after('badge_id');
            $table->json('reward_payload')->nullable()->after('reward_kind');
        });

        Schema::table('bonus_logs', function (Blueprint $table) {
            $table->string('source', 32)->nullable()->after('reason');
        });

        Schema::create('battle_pass_seasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 16)->default('live');
            $table->boolean('is_active')->default(false);
            $table->unsignedTinyInteger('claim_grace_days')->default(14);
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['club_id', 'status']);
        });

        Schema::create('battle_pass_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained('battle_pass_seasons')->cascadeOnDelete();
            $table->unsignedSmallInteger('level');
            $table->unsignedInteger('xp_required');
            $table->string('reward_kind', 32);
            $table->json('reward_payload')->nullable();
            $table->timestamps();
            $table->unique(['season_id', 'level']);
        });

        Schema::create('user_battle_passes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('season_id')->constrained('battle_pass_seasons')->cascadeOnDelete();
            $table->unsignedInteger('xp')->default(0);
            $table->unsignedSmallInteger('level')->default(0);
            $table->unsignedSmallInteger('claimed_level')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'season_id']);
        });

        Schema::create('battle_pass_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('level_id')->constrained('battle_pass_levels')->cascadeOnDelete();
            $table->timestamp('claimed_at');
            $table->timestamps();
            $table->unique(['user_id', 'level_id']);
        });

        Schema::create('user_cosmetics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->unsignedBigInteger('ref_id');
            $table->timestamp('equipped_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'kind', 'ref_id']);
        });

        Schema::create('user_badge_showcases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('badge_id')->constrained('achievement_badges')->cascadeOnDelete();
            $table->unsignedTinyInteger('slot');
            $table->timestamps();
            $table->unique(['user_id', 'slot']);
            $table->unique(['user_id', 'badge_id']);
        });

        Schema::create('ladder_reward_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->string('metric', 32);
            $table->unsignedInteger('threshold');
            $table->string('reward_kind', 32);
            $table->json('payload')->nullable();
            $table->string('period', 16)->default('once');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('user_reward_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('season_id')->nullable()->constrained('battle_pass_seasons')->nullOnDelete();
            $table->foreignId('level_id')->nullable()->constrained('battle_pass_levels')->nullOnDelete();
            $table->foreignId('tier_id')->nullable()->constrained('ladder_reward_tiers')->nullOnDelete();
            $table->string('reward_kind', 32);
            $table->json('payload')->nullable();
            $table->string('period_key', 64);
            $table->timestamp('granted_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'tier_id', 'period_key']);
            $table->index(['user_id', 'reward_kind', 'expires_at']);
        });

        Schema::create('gsi_award_dedup', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('achievement_code', 64);
            $table->string('match_round_key', 96);
            $table->timestamp('created_at')->nullable();
            $table->unique(['user_id', 'achievement_code', 'match_round_key']);
        });

        Schema::create('user_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('external_id', 128);
            $table->json('meta')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('unlinked_at')->nullable();
            $table->timestamp('purge_after')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'external_id']);
            $table->unique(['user_id', 'provider']);
        });

        Schema::create('achievement_source_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->boolean('gsi_awards')->default(true);
            $table->boolean('steam')->default(false);
            $table->boolean('opendota')->default(false);
            $table->boolean('riot')->default(false);
            $table->boolean('pubg')->default(false);
            $table->boolean('tracker')->default(false);
            $table->boolean('faceit_skill')->default(false);
            $table->boolean('telegram_ace')->default(false);
            $table->string('tracker_city', 64)->nullable();
            $table->unsignedTinyInteger('showcase_slots')->default(6);
            $table->json('caffeine_categories')->nullable();
            $table->json('food_categories')->nullable();
            $table->timestamps();
            $table->unique('club_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achievement_source_settings');
        Schema::dropIfExists('user_identities');
        Schema::dropIfExists('gsi_award_dedup');
        Schema::dropIfExists('user_reward_grants');
        Schema::dropIfExists('ladder_reward_tiers');
        Schema::dropIfExists('user_badge_showcases');
        Schema::dropIfExists('user_cosmetics');
        Schema::dropIfExists('battle_pass_claims');
        Schema::dropIfExists('user_battle_passes');
        Schema::dropIfExists('battle_pass_levels');
        Schema::dropIfExists('battle_pass_seasons');
        Schema::table('bonus_logs', function (Blueprint $table) {
            $table->dropColumn('source');
        });
        Schema::table('achievements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('badge_id');
            $table->dropColumn(['source_kind', 'code', 'metric', 'xp', 'reward_kind', 'reward_payload']);
        });
        Schema::dropIfExists('club_statuses');
        Schema::dropIfExists('cosmetic_frames');
        Schema::dropIfExists('achievement_badges');
    }
};
