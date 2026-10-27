<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shell_qr_challenges', function (Blueprint $table) {
            $table->string('poll_secret', 64)->nullable()->after('token');
        });
    }

    public function down(): void
    {
        Schema::table('shell_qr_challenges', function (Blueprint $table) {
            $table->dropColumn('poll_secret');
        });
    }
};
