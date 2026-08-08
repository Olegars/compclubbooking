<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_assistant_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->nullable()->constrained('clubs')->nullOnDelete();
            $table->boolean('is_enabled')->default(true);
            $table->string('tts_voice', 32)->default('nova');
            $table->text('companion_prompt')->nullable();
            $table->text('greeting_prompt')->nullable();
            $table->timestamps();

            $table->unique('club_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_assistant_settings');
    }
};
