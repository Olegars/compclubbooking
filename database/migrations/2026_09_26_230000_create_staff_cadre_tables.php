<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->widenPersonalColumns();

        Schema::table('staff_employment_profiles', function (Blueprint $table) {
            $table->text('snils')->nullable();
            $table->text('inn')->nullable();
            $table->string('gender', 8)->nullable();
            $table->string('okz_code', 10)->nullable();
            $table->string('work_function_title')->nullable();
            $table->string('part_time_code', 8)->nullable();
        });

        Schema::create('cadre_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_type', 50);
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month')->nullable();
            $table->string('file_path');
            $table->unsignedInteger('records_count');
            $table->string('xml_hash', 64);
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('status', 20)->default('exported');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('staff_cadre_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->foreignId('club_id')->nullable()->constrained('clubs')->nullOnDelete();
            $table->string('event_type', 20);
            $table->date('event_date');
            $table->string('order_number', 50);
            $table->date('order_date');
            $table->string('okz_code', 10);
            $table->string('work_function_title');
            $table->string('part_time_code', 8)->nullable();
            $table->string('fire_reason_code', 50)->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('deadline_at')->nullable();
            $table->timestamp('exported_at')->nullable();
            $table->unsignedBigInteger('report_id')->nullable();
            $table->timestamps();

            $table->index(['status', 'deadline_at']);
            $table->index(['admin_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_cadre_events');
        Schema::dropIfExists('cadre_reports');

        Schema::table('staff_employment_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'snils',
                'inn',
                'gender',
                'okz_code',
                'work_function_title',
                'part_time_code',
            ]);
        });
    }

    private function widenPersonalColumns(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $columns = ['passport_series', 'passport_number', 'issued_by', 'department_code'];

        if ($driver === 'pgsql') {
            foreach ($columns as $column) {
                DB::statement('ALTER TABLE staff_employment_profiles ALTER COLUMN '.$column.' TYPE TEXT');
            }

            return;
        }

        if ($driver === 'mysql') {
            foreach ($columns as $column) {
                DB::statement('ALTER TABLE staff_employment_profiles MODIFY '.$column.' TEXT NULL');
            }
        }
    }
};
