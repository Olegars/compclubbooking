<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('title_normalized', 191);
            $table->string('comment', 500)->nullable();
            /** cabinet | shell */
            $table->string('source', 16)->default('cabinet');
            /** open | done | rejected */
            $table->string('status', 16)->default('open');
            $table->timestamps();

            $table->index(['title_normalized', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'title_normalized', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_requests');
    }
};
