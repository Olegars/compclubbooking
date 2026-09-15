<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->foreignId('club_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('format', 24)->default('single_elim')->after('status');
            $table->boolean('lock_games')->default(true)->after('format');
            $table->unsignedInteger('prize_first_minor')->default(0)->after('prize_pool');
            $table->unsignedInteger('prize_second_minor')->default(0)->after('prize_first_minor');
            $table->unsignedInteger('prize_third_minor')->default(0)->after('prize_second_minor');
            $table->timestampTz('prizes_paid_at')->nullable()->after('prize_third_minor');
        });

        Schema::create('tournament_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('seed')->default(0);
            $table->foreignId('computer_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('placement')->nullable();
            $table->unsignedInteger('prize_paid_minor')->default(0);
            $table->timestampsTz();
            $table->unique(['tournament_id', 'user_id']);
        });

        Schema::create('tournament_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('round');
            $table->unsignedSmallInteger('slot');
            $table->foreignId('player1_id')->nullable()->constrained('tournament_players')->nullOnDelete();
            $table->foreignId('player2_id')->nullable()->constrained('tournament_players')->nullOnDelete();
            $table->foreignId('winner_id')->nullable()->constrained('tournament_players')->nullOnDelete();
            $table->unsignedSmallInteger('score1')->nullable();
            $table->unsignedSmallInteger('score2')->nullable();
            $table->string('status', 16)->default('pending');
            $table->timestampsTz();
            $table->unique(['tournament_id', 'round', 'slot']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_matches');
        Schema::dropIfExists('tournament_players');
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('club_id');
            $table->dropColumn([
                'format',
                'lock_games',
                'prize_first_minor',
                'prize_second_minor',
                'prize_third_minor',
                'prizes_paid_at',
            ]);
        });
    }
};
