<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_employment_profiles', function (Blueprint $table) {
            $table->string('status', 20)->default('draft')->after('accepted_rule_ids');
            $table->timestamp('submitted_at')->nullable()->after('status');
            $table->timestamp('reviewed_at')->nullable()->after('submitted_at');
            $table->foreignId('reviewed_by')->nullable()->after('reviewed_at')->constrained('admins')->nullOnDelete();
            $table->text('rejection_reason')->nullable()->after('reviewed_by');
        });

        $hiredIds = DB::table('admins')
            ->where('employment_pending', false)
            ->pluck('id');

        if ($hiredIds->isNotEmpty()) {
            DB::table('staff_employment_profiles')
                ->whereIn('admin_id', $hiredIds)
                ->update([
                    'status' => 'approved',
                    'reviewed_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('staff_employment_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['status', 'submitted_at', 'reviewed_at', 'rejection_reason']);
        });
    }
};
