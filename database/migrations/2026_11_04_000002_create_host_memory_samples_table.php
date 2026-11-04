<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('host_memory_samples')) {
            return;
        }

        Schema::create('host_memory_samples', function (Blueprint $table) {
            $table->id();
            $table->string('hostname')->nullable();
            $table->unsignedTinyInteger('load_pct');
            $table->unsignedInteger('total_mb');
            $table->unsignedInteger('used_mb');
            $table->unsignedInteger('available_mb');
            $table->timestamp('reported_at');
            $table->timestamps();

            $table->index('reported_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('host_memory_samples');
    }
};
