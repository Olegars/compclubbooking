<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arena_duels', function (Blueprint $table) {
            $table->dropForeign(['creator_computer_id']);
        });

        Schema::table('arena_duels', function (Blueprint $table) {
            $table->unsignedBigInteger('creator_computer_id')->nullable()->change();
            $table->foreign('creator_computer_id')->references('id')->on('computers')->nullOnDelete();
            $table->string('kind', 16)->default('duel')->after('mode');
            $table->unsignedTinyInteger('min_players')->default(2)->after('kind');
            $table->unsignedTinyInteger('max_players')->default(2)->after('min_players');
            $table->timestamp('scheduled_at')->nullable()->after('expires_at');
            $table->decimal('raise_to', 10, 2)->nullable()->after('winner_prize');
            $table->foreignId('raise_by_user_id')->nullable()->after('raise_to')->constrained('users')->nullOnDelete();
            $table->json('raise_votes')->nullable()->after('raise_by_user_id');
            $table->index(['club_id', 'kind', 'status']);
        });

        Schema::table('arena_duel_participants', function (Blueprint $table) {
            $table->dropForeign(['computer_id']);
        });

        Schema::table('arena_duel_participants', function (Blueprint $table) {
            $table->unsignedBigInteger('computer_id')->nullable()->change();
            $table->foreign('computer_id')->references('id')->on('computers')->nullOnDelete();
            $table->decimal('held_amount', 10, 2)->nullable()->after('escrow_status');
        });
    }

    public function down(): void
    {
        Schema::table('arena_duels', function (Blueprint $table) {
            $table->dropForeign(['raise_by_user_id']);
            $table->dropIndex(['club_id', 'kind', 'status']);
            $table->dropColumn(['kind', 'min_players', 'max_players', 'scheduled_at', 'raise_to', 'raise_by_user_id', 'raise_votes']);
        });

        Schema::table('arena_duel_participants', function (Blueprint $table) {
            $table->dropColumn('held_amount');
        });
    }
};
