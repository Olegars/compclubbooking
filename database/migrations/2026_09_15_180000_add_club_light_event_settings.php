<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('club_light_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('events')->nullable();
            $table->timestamps();
        });

        Schema::table('space_lights', function (Blueprint $table) {
            $table->string('scene_kind', 16)->default('off')->after('vacant');
        });
    }

    public function down(): void
    {
        Schema::table('space_lights', function (Blueprint $table) {
            $table->dropColumn('scene_kind');
        });
        Schema::dropIfExists('club_light_settings');
    }
};
