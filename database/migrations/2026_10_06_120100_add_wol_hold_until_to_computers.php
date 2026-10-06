<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('computers', function (Blueprint $table) {
            if (! Schema::hasColumn('computers', 'wol_hold_until')) {
                $table->timestamp('wol_hold_until')->nullable()->after('shift_audit_hold');
            }
        });
    }

    public function down(): void
    {
        Schema::table('computers', function (Blueprint $table) {
            if (Schema::hasColumn('computers', 'wol_hold_until')) {
                $table->dropColumn('wol_hold_until');
            }
        });
    }
};
