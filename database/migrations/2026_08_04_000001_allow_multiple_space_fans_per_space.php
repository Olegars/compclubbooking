<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Index club_id+space_id already exists from create_fan_control_tables.
        // Only drop the one-fan-per-space unique (safe if already dropped on retry).
        $unique = 'space_fans_space_id_unique';
        $exists = collect(DB::select(
            "SELECT 1 FROM pg_constraint WHERE conname = ?",
            [$unique]
        ))->isNotEmpty();

        if ($exists) {
            Schema::table('space_fans', function (Blueprint $table) use ($unique) {
                $table->dropUnique($unique);
            });
        }
    }

    public function down(): void
    {
        Schema::table('space_fans', function (Blueprint $table) {
            $table->unique('space_id');
        });
    }
};
