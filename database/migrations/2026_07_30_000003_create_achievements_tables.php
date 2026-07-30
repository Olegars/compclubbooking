<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achievements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            /** play_hours | night_visits | visit_count */
            $table->string('type', 32);
            /** Target value: hours or visit count depending on type */
            $table->decimal('target_value', 10, 2);
            /** once | weekly | monthly */
            $table->string('period', 16)->default('once');
            /** deposit_balance | bonus_balance */
            $table->string('reward_type', 32)->default('deposit_balance');
            $table->decimal('reward_value', 10, 2);
            /** Night window for night_visits (hours 0–23) */
            $table->unsignedTinyInteger('night_start')->default(22);
            $table->unsignedTinyInteger('night_end')->default(6);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('user_achievements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('achievement_id')->constrained()->cascadeOnDelete();
            /** e.g. once / 2026-W31 / 2026-07 */
            $table->string('period_key', 32);
            $table->decimal('progress', 10, 2)->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('rewarded_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'achievement_id', 'period_key']);
            $table->index(['achievement_id', 'period_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_achievements');
        Schema::dropIfExists('achievements');
    }
};
