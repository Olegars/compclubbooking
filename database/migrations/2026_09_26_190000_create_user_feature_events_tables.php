<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_feature_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('computer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('club_id')->nullable()->constrained()->nullOnDelete();
            $table->string('terminal_id', 64)->nullable();
            $table->string('feature_key', 64);
            $table->string('source_client', 32);
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index('feature_key');
            $table->index(['user_id', 'feature_key', 'created_at']);
            $table->index(['club_id', 'created_at']);
        });

        Schema::create('user_feature_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->foreignId('club_id')->nullable()->constrained()->nullOnDelete();
            $table->string('feature_key', 64);
            $table->string('source_client', 32);
            $table->unsignedInteger('total_actions')->default(0);
            $table->unsignedInteger('unique_users')->default(0);
            $table->unsignedInteger('unique_stations')->default(0);

            $table->index('date');
            $table->index('feature_key');
            $table->unique(['date', 'club_id', 'feature_key', 'source_client'], 'user_feature_daily_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_feature_daily_stats');
        Schema::dropIfExists('user_feature_events');
    }
};
