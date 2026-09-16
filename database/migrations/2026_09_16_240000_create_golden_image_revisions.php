<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rollback Markers золотого образа: снимок хэшей Steam/Epic/конфигов
 * при сохранении Super Client и очередь отката на проверенную ревизию.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('golden_image_revisions')) {
            Schema::create('golden_image_revisions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('club_id')->nullable()->index();
                $table->unsignedBigInteger('computer_id')->nullable()->index();
                $table->string('status', 16)->default('pending')->index();
                $table->string('disk_mode', 16)->nullable();
                $table->string('aggregate_hash', 64)->index();
                $table->string('inventory_hash', 64)->nullable();
                $table->unsignedSmallInteger('steam_count')->default(0);
                $table->unsignedSmallInteger('epic_count')->default(0);
                $table->unsignedSmallInteger('file_count')->default(0);
                $table->unsignedSmallInteger('changed_count')->default(0);
                $table->json('files')->nullable();
                $table->json('changed_rels')->nullable();
                $table->string('note', 240)->nullable();
                $table->unsignedBigInteger('verified_by')->nullable();
                $table->timestampTz('verified_at')->nullable();
                $table->timestampTz('rolled_back_at')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('computers', function (Blueprint $table) {
            if (! Schema::hasColumn('computers', 'golden_revision_id')) {
                $table->unsignedBigInteger('golden_revision_id')->nullable()->after('resync_message');
            }
            if (! Schema::hasColumn('computers', 'rollback_command')) {
                $table->string('rollback_command', 32)->nullable()->after('golden_revision_id');
            }
            if (! Schema::hasColumn('computers', 'rollback_command_id')) {
                $table->unsignedBigInteger('rollback_command_id')->nullable()->after('rollback_command');
            }
            if (! Schema::hasColumn('computers', 'rollback_revision_id')) {
                $table->unsignedBigInteger('rollback_revision_id')->nullable()->after('rollback_command_id');
            }
            if (! Schema::hasColumn('computers', 'rollback_command_at')) {
                $table->timestampTz('rollback_command_at')->nullable()->after('rollback_revision_id');
            }
            if (! Schema::hasColumn('computers', 'rollback_result')) {
                $table->string('rollback_result', 32)->nullable()->after('rollback_command_at');
            }
            if (! Schema::hasColumn('computers', 'rollback_message')) {
                $table->string('rollback_message', 240)->nullable()->after('rollback_result');
            }
            if (! Schema::hasColumn('computers', 'last_crash_at')) {
                $table->timestampTz('last_crash_at')->nullable()->after('rollback_message');
            }
            if (! Schema::hasColumn('computers', 'last_crash_reason')) {
                $table->string('last_crash_reason', 32)->nullable()->after('last_crash_at');
            }
            if (! Schema::hasColumn('computers', 'last_crash_detail')) {
                $table->string('last_crash_detail', 240)->nullable()->after('last_crash_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('computers', function (Blueprint $table) {
            $cols = [
                'golden_revision_id',
                'rollback_command',
                'rollback_command_id',
                'rollback_revision_id',
                'rollback_command_at',
                'rollback_result',
                'rollback_message',
                'last_crash_at',
                'last_crash_reason',
                'last_crash_detail',
            ];
            $drop = array_values(array_filter($cols, fn ($c) => Schema::hasColumn('computers', $c)));
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });

        Schema::dropIfExists('golden_image_revisions');
    }
};
