<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->string('source', 16)->default('location')->after('type');
            $table->string('city', 80)->nullable()->after('address');
            $table->string('network_name', 120)->nullable()->after('city');
            $table->string('contact', 120)->nullable()->after('network_name');
            $table->string('website', 180)->nullable()->after('contact');
            $table->boolean('tournament_open')->default(true)->after('website');
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn([
                'source',
                'city',
                'network_name',
                'contact',
                'website',
                'tournament_open',
            ]);
        });
    }
};
