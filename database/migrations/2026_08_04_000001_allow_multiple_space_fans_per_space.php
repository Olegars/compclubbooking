<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('space_fans')) {
            return;
        }

        // Index club_id+space_id already exists from create_fan_control_tables.
        // Only drop the one-fan-per-space unique (safe if already dropped on retry).
        $unique = 'space_fans_space_id_unique';
        if (! $this->uniqueExists($unique)) {
            return;
        }

        Schema::table('space_fans', function (Blueprint $table) use ($unique) {
            $table->dropUnique($unique);
        });
    }

    public function down(): void
    {
        Schema::table('space_fans', function (Blueprint $table) {
            $table->unique('space_id');
        });
    }

    private function uniqueExists(string $name): bool
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            return collect(DB::select(
                'SELECT 1 FROM pg_constraint WHERE conname = ?',
                [$name]
            ))->isNotEmpty();
        }
        if ($driver === 'sqlite') {
            foreach (DB::select("PRAGMA index_list('space_fans')") as $index) {
                if (($index->name ?? '') === $name) {
                    return true;
                }
            }

            return false;
        }

        return Schema::hasIndex('space_fans', $name);
    }
};
