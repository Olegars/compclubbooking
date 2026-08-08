<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_assistant_settings', function (Blueprint $table) {
            $table->string('llm_provider', 32)->default('deepseek')->after('is_enabled');
            $table->text('llm_api_key')->nullable()->after('llm_provider');
            $table->string('llm_base_url', 512)->nullable()->after('llm_api_key');
            $table->string('llm_model', 128)->nullable()->after('llm_base_url');
            $table->text('openai_api_key')->nullable()->after('llm_model');
            $table->string('openai_base_url', 512)->nullable()->after('openai_api_key');
            $table->string('stt_model', 64)->nullable()->after('openai_base_url');
            $table->string('tts_model', 64)->nullable()->after('stt_model');
            $table->unsignedInteger('max_reply_chars')->nullable()->after('tts_voice');
        });
    }

    public function down(): void
    {
        Schema::table('ai_assistant_settings', function (Blueprint $table) {
            $table->dropColumn([
                'llm_provider',
                'llm_api_key',
                'llm_base_url',
                'llm_model',
                'openai_api_key',
                'openai_base_url',
                'stt_model',
                'tts_model',
                'max_reply_chars',
            ]);
        });
    }
};
