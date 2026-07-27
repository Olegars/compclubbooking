<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('computers', function (Blueprint $table) {
            $table->string('kind', 16)->default('pc')->after('type');
            $table->string('booth_id')->nullable()->after('kind');
            $table->index(['club_id', 'booth_id']);
            $table->index(['club_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::table('computers', function (Blueprint $table) {
            $table->dropIndex(['club_id', 'booth_id']);
            $table->dropIndex(['club_id', 'kind']);
            $table->dropColumn(['kind', 'booth_id']);
        });
    }
};
