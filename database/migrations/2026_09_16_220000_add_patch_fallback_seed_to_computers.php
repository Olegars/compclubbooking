<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LAN P2P fallback-сид: тип накопителя D: и роль временного зеркала.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('computers', function (Blueprint $table) {
            if (! Schema::hasColumn('computers', 'cache_media')) {
                $table->string('cache_media', 16)->nullable()->after('volume_letter');
            }
            if (! Schema::hasColumn('computers', 'patch_seed_role')) {
                $table->string('patch_seed_role', 16)->nullable()->after('patch_seed_port');
            }
            if (! Schema::hasColumn('computers', 'patch_ingest_at')) {
                $table->timestamp('patch_ingest_at')->nullable()->after('patch_pull_message');
            }
            if (! Schema::hasColumn('computers', 'patch_ingest_result')) {
                $table->string('patch_ingest_result', 32)->nullable()->after('patch_ingest_at');
            }
            if (! Schema::hasColumn('computers', 'patch_ingest_message')) {
                $table->string('patch_ingest_message', 240)->nullable()->after('patch_ingest_result');
            }
        });
    }

    public function down(): void
    {
        Schema::table('computers', function (Blueprint $table) {
            $cols = [
                'cache_media',
                'patch_seed_role',
                'patch_ingest_at',
                'patch_ingest_result',
                'patch_ingest_message',
            ];
            $drop = array_values(array_filter($cols, fn ($c) => Schema::hasColumn('computers', $c)));
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};
