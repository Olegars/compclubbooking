<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('game_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('games')->cascadeOnDelete();
            $table->string('login');
            $table->string('password');
            $table->enum('status', ['free', 'in_use', 'banned', 'maintenance'])->default('free');

            // Если аккаунт занят, тут будет ID компа, за которым в него играют
            $table->unsignedBigInteger('current_pc_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('game_accounts');
    }
};
