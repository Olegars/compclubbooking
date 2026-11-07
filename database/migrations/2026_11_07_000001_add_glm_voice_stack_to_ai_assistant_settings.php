<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_assistant_settings', function (Blueprint $table) {
            $table->text('glm_api_key')->nullable();
            $table->string('glm_tts_voice', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_assistant_settings', function (Blueprint $table) {
            $table->dropColumn(['glm_api_key', 'glm_tts_voice']);
        });
    }
};
