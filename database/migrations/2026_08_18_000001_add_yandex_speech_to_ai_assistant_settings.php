<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_assistant_settings', function (Blueprint $table) {
            $table->string('speech_provider', 32)->default('yandex')->after('llm_model');
            $table->text('yandex_api_key')->nullable()->after('speech_provider');
            $table->string('yandex_folder_id', 64)->nullable()->after('yandex_api_key');
        });
    }

    public function down(): void
    {
        Schema::table('ai_assistant_settings', function (Blueprint $table) {
            $table->dropColumn(['speech_provider', 'yandex_api_key', 'yandex_folder_id']);
        });
    }
};
