<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->foreignId('opponent_club_id')->nullable()->after('club_id')->constrained('clubs')->nullOnDelete();
            $table->unsignedSmallInteger('roster_size')->nullable()->after('lock_games');
            $table->string('venue', 16)->nullable()->after('roster_size');
            $table->string('prize_funding', 16)->nullable()->after('venue');
            $table->text('rules')->nullable()->after('prize_funding');
        });

        Schema::table('tournament_players', function (Blueprint $table) {
            $table->foreignId('club_id')->nullable()->after('user_id')->constrained('clubs')->nullOnDelete();
        });

        Schema::create('tournament_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('host_club_id')->constrained('clubs')->cascadeOnDelete();
            $table->foreignId('guest_club_id')->constrained('clubs')->cascadeOnDelete();
            $table->foreignId('waiting_club_id')->constrained('clubs')->cascadeOnDelete();
            $table->foreignId('proposer_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('tournament_id')->nullable()->constrained('tournaments')->nullOnDelete();
            $table->string('status', 16)->default('pending');
            $table->string('name');
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('format', 24)->default('single_elim');
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->unsignedSmallInteger('roster_size')->default(5);
            $table->decimal('entry_fee', 10, 2)->default(0);
            $table->text('prize_pool')->nullable();
            $table->unsignedInteger('prize_first_minor')->default(0);
            $table->unsignedInteger('prize_second_minor')->default(0);
            $table->unsignedInteger('prize_third_minor')->default(0);
            $table->string('prize_funding', 16)->default('host');
            $table->string('venue', 16)->default('host');
            $table->boolean('lock_games')->default(true);
            $table->text('rules')->nullable();
            $table->text('decline_reason')->nullable();
            $table->timestampTz('agreed_at')->nullable();
            $table->timestampsTz();

            $table->index(['waiting_club_id', 'status']);
            $table->index(['host_club_id', 'status']);
            $table->index(['guest_club_id', 'status']);
        });

        Schema::table('tournaments', function (Blueprint $table) {
            $table->foreignId('challenge_id')->nullable()->after('opponent_club_id')->constrained('tournament_challenges')->nullOnDelete();
        });

        Schema::create('tournament_challenge_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('challenge_id')->constrained('tournament_challenges')->cascadeOnDelete();
            $table->foreignId('club_id')->constrained('clubs')->cascadeOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('action', 16);
            $table->json('terms');
            $table->text('comment')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_challenge_revisions');
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('challenge_id');
        });
        Schema::dropIfExists('tournament_challenges');
        Schema::table('tournament_players', function (Blueprint $table) {
            $table->dropConstrainedForeignId('club_id');
        });
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('opponent_club_id');
            $table->dropColumn(['roster_size', 'venue', 'prize_funding', 'rules']);
        });
    }
};
