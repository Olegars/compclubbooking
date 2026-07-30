<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_claims', function (Blueprint $table) {
            try {
                $table->dropUnique(['review_link']);
            } catch (\Throwable $e) {
                // already dropped on some environments
            }
        });
    }

    public function down(): void
    {
        Schema::table('review_claims', function (Blueprint $table) {
            $table->unique('review_link');
        });
    }
};
