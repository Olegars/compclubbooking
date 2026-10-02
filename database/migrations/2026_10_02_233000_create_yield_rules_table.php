<?php

use App\Models\YieldRule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('yield_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('kind', 16);
            $table->boolean('is_active')->default(true);
            $table->json('weekdays');
            $table->unsignedSmallInteger('time_start');
            $table->unsignedSmallInteger('time_end');
            $table->string('utilization_op', 8);
            $table->unsignedTinyInteger('utilization_percent');
            $table->decimal('adjust_percent', 6, 2);
            $table->unsignedSmallInteger('priority')->default(0);
            $table->timestamps();

            $table->index(['club_id', 'is_active']);
        });

        $now = now();
        foreach (DB::table('clubs')->pluck('id') as $clubId) {
            foreach (YieldRule::presets() as $preset) {
                DB::table('yield_rules')->insert([
                    'club_id' => (int) $clubId,
                    'zone_id' => null,
                    'name' => $preset['name'],
                    'kind' => $preset['kind'],
                    'is_active' => true,
                    'weekdays' => json_encode($preset['weekdays']),
                    'time_start' => $preset['time_start'],
                    'time_end' => $preset['time_end'],
                    'utilization_op' => $preset['utilization_op'],
                    'utilization_percent' => $preset['utilization_percent'],
                    'adjust_percent' => $preset['adjust_percent'],
                    'priority' => $preset['priority'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('yield_rules');
    }
};
