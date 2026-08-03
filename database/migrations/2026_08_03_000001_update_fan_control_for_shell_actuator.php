<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('space_fans', function (Blueprint $table) {
            $table->timestamp('last_manual_at')->nullable()->after('last_applied_at');
            $table->foreignId('last_manual_by_computer_id')
                ->nullable()
                ->after('last_manual_at')
                ->constrained('computers')
                ->nullOnDelete();
            $table->foreignId('last_applied_by_computer_id')
                ->nullable()
                ->after('last_manual_by_computer_id')
                ->constrained('computers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('space_fans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_applied_by_computer_id');
            $table->dropConstrainedForeignId('last_manual_by_computer_id');
            $table->dropColumn('last_manual_at');
        });
    }
};
