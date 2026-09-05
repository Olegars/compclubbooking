<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_employment_profiles', function (Blueprint $table) {
            $table->timestamp('appointment_at')->nullable()->after('rejection_reason');
            $table->timestamp('biometrics_captured_at')->nullable()->after('appointment_at');
            $table->json('biometrics_payload')->nullable()->after('biometrics_captured_at');
            $table->json('accepted_fire_rule_ids')->nullable()->after('biometrics_payload');
        });
    }

    public function down(): void
    {
        Schema::table('staff_employment_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'appointment_at',
                'biometrics_captured_at',
                'biometrics_payload',
                'accepted_fire_rule_ids',
            ]);
        });
    }
};
