<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('space_fans', function (Blueprint $table) {
            $table->dropUnique(['space_id']);
            $table->index(['club_id', 'space_id']);
        });
    }

    public function down(): void
    {
        Schema::table('space_fans', function (Blueprint $table) {
            $table->dropIndex(['club_id', 'space_id']);
            $table->unique('space_id');
        });
    }
};
