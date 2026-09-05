<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->timestamp('fired_at')->nullable()->after('hired_at');
            $table->foreignId('fired_by')->nullable()->after('fired_at')->constrained('admins')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fired_by');
            $table->dropColumn('fired_at');
        });
    }
};
