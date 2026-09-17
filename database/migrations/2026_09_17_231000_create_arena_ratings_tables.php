<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arena_ratings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('club_id')->index();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('rating')->default(1000);
            $table->unsignedInteger('wins')->default(0);
            $table->unsignedInteger('losses')->default(0);
            $table->unsignedInteger('streak')->default(0);
            $table->unsignedInteger('best_streak')->default(0);
            $table->unsignedInteger('week_wins')->default(0);
            $table->date('week_start')->nullable();
            $table->date('evening_date')->nullable();
            $table->unsignedInteger('evening_streak')->default(0);
            $table->timestamps();
            $table->unique(['club_id', 'user_id']);
            $table->index(['club_id', 'rating']);
        });

        Schema::create('arena_koth_evenings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('club_id')->index();
            $table->date('recorded_on');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('streak')->default(0);
            $table->string('perk_kind', 16)->nullable();
            $table->string('perk_label', 180)->nullable();
            $table->timestamp('perk_awarded_at')->nullable();
            $table->timestamps();
            $table->unique(['club_id', 'recorded_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arena_koth_evenings');
        Schema::dropIfExists('arena_ratings');
    }
};
