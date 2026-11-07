<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_assistant_settings', function (Blueprint $table) {
            $table->string('avatar_provider', 16)->default('qwen');
        });
    }

    public function down(): void
    {
        Schema::table('ai_assistant_settings', function (Blueprint $table) {
            $table->dropColumn('avatar_provider');
        });
    }
};
