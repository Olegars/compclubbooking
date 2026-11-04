<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('computer_memory_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('computer_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('load_pct');
            $table->unsignedInteger('total_mb');
            $table->unsignedInteger('used_mb');
            $table->unsignedInteger('available_mb');
            $table->timestamp('reported_at');
            $table->timestamps();

            $table->index(['computer_id', 'reported_at']);
            $table->index(['club_id', 'reported_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('computer_memory_samples');
    }
};
