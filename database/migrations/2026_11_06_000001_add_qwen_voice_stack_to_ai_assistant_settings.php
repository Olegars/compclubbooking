<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_assistant_settings', function (Blueprint $table) {
            $table->string('voice_stack', 32)->default('deepseek_yandex');
            $table->text('qwen_api_key')->nullable();
            $table->string('qwen_tts_voice', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_assistant_settings', function (Blueprint $table) {
            $table->dropColumn(['voice_stack', 'qwen_api_key', 'qwen_tts_voice']);
        });
    }
};
