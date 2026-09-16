<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_warranties', function (Blueprint $table) {
            $table->string('public_token', 32)->nullable()->unique();
        });

        Schema::table('store_built_pcs', function (Blueprint $table) {
            $table->timestamp('assembly_started_at')->nullable();
            $table->timestamp('assembly_finished_at')->nullable();
            $table->string('nvr_channel', 32)->nullable();
            $table->string('assembly_clip_path')->nullable();
            $table->unsignedInteger('assembly_clip_bytes')->nullable();
            $table->timestamp('assembly_clip_uploaded_at')->nullable();
        });

        Schema::create('store_assembly_clip_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained('clubs')->cascadeOnDelete();
            $table->foreignId('store_built_pc_id')->constrained('store_built_pcs')->cascadeOnDelete();
            $table->string('status', 24)->default('pending');
            $table->string('channel', 32)->nullable();
            $table->unsignedInteger('track_id')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
            $table->index(['club_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_assembly_clip_jobs');

        Schema::table('store_built_pcs', function (Blueprint $table) {
            $table->dropColumn([
                'assembly_started_at',
                'assembly_finished_at',
                'nvr_channel',
                'assembly_clip_path',
                'assembly_clip_bytes',
                'assembly_clip_uploaded_at',
            ]);
        });

        Schema::table('store_warranties', function (Blueprint $table) {
            $table->dropUnique(['public_token']);
            $table->dropColumn('public_token');
        });
    }
};
