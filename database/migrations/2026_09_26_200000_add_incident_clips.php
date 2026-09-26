<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Эпизод с камеры зала: 30 с до инцидента и 15 с после.
 * Очередь забирает LAN-агент (NVR), файл лежит в storage, ссылка — в ленте.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('computers', function (Blueprint $table) {
            if (! Schema::hasColumn('computers', 'nvr_channel')) {
                $table->string('nvr_channel', 8)->nullable()->after('name');
            }
        });

        Schema::table('incidents', function (Blueprint $table) {
            if (! Schema::hasColumn('incidents', 'clip_status')) {
                $table->string('clip_status', 16)->default('none')->after('severity');
            }
            if (! Schema::hasColumn('incidents', 'clip_file_name')) {
                $table->string('clip_file_name')->nullable()->after('clip_status');
            }
            if (! Schema::hasColumn('incidents', 'clip_url')) {
                $table->string('clip_url')->nullable()->after('clip_file_name');
            }
            if (! Schema::hasColumn('incidents', 'clip_path')) {
                $table->string('clip_path')->nullable()->after('clip_url');
            }
        });

        if (! Schema::hasTable('incident_clip_jobs')) {
            Schema::create('incident_clip_jobs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('club_id')->nullable()->constrained('clubs')->nullOnDelete();
                $table->string('subject_type', 16);
                $table->unsignedBigInteger('subject_id');
                $table->unsignedBigInteger('computer_id')->nullable();
                $table->string('status', 24)->default('pending');
                $table->string('channel', 32)->nullable();
                $table->unsignedInteger('track_id')->nullable();
                $table->timestamp('event_at')->nullable();
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->string('file_name')->nullable();
                $table->string('file_path')->nullable();
                $table->unsignedInteger('bytes')->nullable();
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->timestamp('claimed_at')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->string('last_error', 500)->nullable();
                $table->timestamps();

                $table->index(['status', 'id']);
                $table->index(['subject_type', 'subject_id']);
                $table->index(['club_id', 'status']);
                $table->index(['computer_id', 'event_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_clip_jobs');

        Schema::table('incidents', function (Blueprint $table) {
            foreach (['clip_path', 'clip_url', 'clip_file_name', 'clip_status'] as $col) {
                if (Schema::hasColumn('incidents', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('computers', function (Blueprint $table) {
            if (Schema::hasColumn('computers', 'nvr_channel')) {
                $table->dropColumn('nvr_channel');
            }
        });
    }
};
