<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shared_fans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16); // supply | exhaust
            $table->string('name', 120);
            $table->foreignId('relay_board_id')->constrained('relay_boards')->cascadeOnDelete();
            $table->unsignedSmallInteger('channel');
            $table->unsignedSmallInteger('channel2');
            $table->unsignedTinyInteger('desired_power')->default(1);
            $table->unsignedTinyInteger('applied_power')->default(1);
            $table->string('last_error')->nullable();
            $table->timestamp('last_applied_at')->nullable();
            $table->timestamps();

            $table->index(['club_id', 'kind']);
            $table->index(['relay_board_id', 'channel']);
        });

        Schema::create('shared_fan_maps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shared_fan_id')->constrained('shared_fans')->cascadeOnDelete();
            $table->unsignedTinyInteger('load_pct'); // 50..100 step 10
            $table->unsignedTinyInteger('output_pct'); // 50 | 100
            $table->timestamps();

            $table->unique(['shared_fan_id', 'load_pct']);
        });

        Schema::create('shared_fan_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shared_fan_id')->constrained('shared_fans')->cascadeOnDelete();
            $table->foreignId('space_fan_id')->constrained('space_fans')->cascadeOnDelete();
            $table->timestamps();

            $table->unique('space_fan_id');
            $table->index('shared_fan_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_fan_links');
        Schema::dropIfExists('shared_fan_maps');
        Schema::dropIfExists('shared_fans');
    }
};
