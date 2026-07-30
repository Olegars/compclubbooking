<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            $table->string('cpu')->nullable()->after('surcharge_per_hour');
            $table->string('gpu')->nullable()->after('cpu');
            $table->string('monitor')->nullable()->after('gpu');
            $table->string('screen_diagonal')->nullable()->after('monitor');
            $table->string('ps_model')->nullable()->after('screen_diagonal');
            $table->string('info_edge', 16)->nullable()->after('ps_model'); // left|right|top|bottom|null=auto
        });
    }

    public function down(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            $table->dropColumn([
                'cpu',
                'gpu',
                'monitor',
                'screen_diagonal',
                'ps_model',
                'info_edge',
            ]);
        });
    }
};
