<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->string('light_color', 16)->nullable()->after('tts_voice');
            $table->unsignedTinyInteger('light_brightness')->nullable()->after('light_color');
            $table->string('light_effect', 16)->nullable()->after('light_brightness');
        });
    }

    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropColumn(['light_color', 'light_brightness', 'light_effect']);
        });
    }
};
