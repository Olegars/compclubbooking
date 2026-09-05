<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->boolean('employment_pending')->default(false)->after('pay_type');
            $table->timestamp('hired_at')->nullable()->after('employment_pending');
        });

        Schema::create('staff_employment_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->unique()->constrained('admins')->cascadeOnDelete();
            $table->string('full_name');
            $table->string('passport_series', 8)->nullable();
            $table->string('passport_number', 12)->nullable();
            $table->string('issued_by')->nullable();
            $table->date('issued_at')->nullable();
            $table->string('department_code', 16)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('passport_scan_path')->nullable();
            $table->json('accepted_rule_ids')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_employment_profiles');
        Schema::table('admins', function (Blueprint $table) {
            $table->dropColumn(['employment_pending', 'hired_at']);
        });
    }
};
