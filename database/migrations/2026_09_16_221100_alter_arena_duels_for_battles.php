<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arena_duels', function (Blueprint $table) {
            $table->dropForeign(['creator_computer_id']);
        });

        $this->nullableUnsigned('arena_duels', 'creator_computer_id');

        Schema::table('arena_duels', function (Blueprint $table) {
            $table->foreign('creator_computer_id')->references('id')->on('computers')->nullOnDelete();
            $table->string('kind', 16)->default('duel');
            $table->unsignedTinyInteger('min_players')->default(2);
            $table->unsignedTinyInteger('max_players')->default(2);
            $table->timestamp('scheduled_at')->nullable();
            $table->decimal('raise_to', 10, 2)->nullable();
            $table->foreignId('raise_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('raise_votes')->nullable();
            $table->index(['club_id', 'kind', 'status']);
        });

        Schema::table('arena_duel_participants', function (Blueprint $table) {
            $table->dropForeign(['computer_id']);
        });

        $this->nullableUnsigned('arena_duel_participants', 'computer_id');

        Schema::table('arena_duel_participants', function (Blueprint $table) {
            $table->foreign('computer_id')->references('id')->on('computers')->nullOnDelete();
            $table->decimal('held_amount', 10, 2)->nullable();
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

    private function nullableUnsigned(string $table, string $column): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE {$table} MODIFY {$column} BIGINT UNSIGNED NULL");

            return;
        }
        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} DROP NOT NULL");

            return;
        }
        Schema::table($table, function (Blueprint $blueprint) use ($column) {
            $blueprint->unsignedBigInteger($column)->nullable()->change();
        });
    }
};
