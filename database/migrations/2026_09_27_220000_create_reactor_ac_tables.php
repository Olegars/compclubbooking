<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ac_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('club_id')->nullable()->index();
            $table->unsignedBigInteger('computer_id')->nullable()->index();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->string('steam_id', 32)->nullable()->index();
            $table->string('hwid_digest', 64)->nullable();
            $table->string('kind', 16)->default('home');
            $table->string('status', 16)->default('online')->index();
            $table->string('match_id', 64)->nullable()->index();
            $table->boolean('integrity_ok')->default(false);
            $table->json('integrity_flags')->nullable();
            $table->string('client_version', 32)->nullable();
            $table->string('os', 64)->nullable();
            $table->timestamp('last_heartbeat_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('ac_hwids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('digest', 64);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'digest']);
        });

        Schema::create('ac_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('ac_sessions')->nullOnDelete();
            $table->unsignedBigInteger('club_id')->nullable()->index();
            $table->string('match_id', 64)->index();
            $table->string('steam_id', 32)->index();
            $table->string('token_hash', 64)->unique();
            $table->string('scope', 24)->default('match_making');
            $table->timestamp('connect_expires_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ac_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('ac_sessions')->nullOnDelete();
            $table->unsignedBigInteger('club_id')->nullable()->index();
            $table->string('kind', 32)->index();
            $table->string('steam_id', 32)->nullable();
            $table->string('message', 255)->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });

        Schema::create('ac_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('ac_sessions')->nullOnDelete();
            $table->unsignedBigInteger('club_id')->nullable()->index();
            $table->string('kind', 24);
            $table->string('path', 255);
            $table->unsignedInteger('bytes')->default(0);
            $table->string('sha256', 64)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ac_bans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('hwid_digest', 64)->nullable()->index();
            $table->string('scope', 24)->index();
            $table->string('reason', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('pardoned_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ac_bans');
        Schema::dropIfExists('ac_evidence');
        Schema::dropIfExists('ac_events');
        Schema::dropIfExists('ac_tickets');
        Schema::dropIfExists('ac_hwids');
        Schema::dropIfExists('ac_sessions');
    }
};
