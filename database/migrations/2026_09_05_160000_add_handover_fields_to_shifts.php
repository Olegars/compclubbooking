<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->foreignId('incoming_admin_id')->nullable()->after('closed_by')->constrained('admins')->nullOnDelete();
            $table->timestamp('transfer_started_at')->nullable()->after('ended_at');
            $table->timestamp('presence_verified_at')->nullable()->after('transfer_started_at');
            $table->json('presence_meta')->nullable()->after('presence_verified_at');
        });

        Schema::table('admins', function (Blueprint $table) {
            $table->timestamp('shift_handed_over_at')->nullable()->after('fired_by');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('incoming_admin_id');
            $table->dropColumn(['transfer_started_at', 'presence_verified_at', 'presence_meta']);
        });

        Schema::table('admins', function (Blueprint $table) {
            $table->dropColumn('shift_handed_over_at');
        });
    }
};
