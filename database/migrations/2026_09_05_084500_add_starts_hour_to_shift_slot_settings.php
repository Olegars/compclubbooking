<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_slot_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('starts_hour')->default(10)->after('hours');
        });
    }

    public function down(): void
    {
        Schema::table('shift_slot_settings', function (Blueprint $table) {
            $table->dropColumn('starts_hour');
        });
    }
};
