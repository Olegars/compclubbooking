<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_surveillance_marker_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->nullable()->constrained('clubs')->nullOnDelete();
            /** pending | claimed | sent | failed */
            $table->string('status', 24)->default('pending');
            $table->string('title', 191);
            $table->string('event', 64)->nullable();
            $table->string('channel', 128)->nullable();
            $table->unsignedInteger('track_id')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('duration_sec')->default(30);
            $table->unsignedInteger('pre_sec')->default(0);
            $table->json('meta')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
            $table->index(['club_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_surveillance_marker_jobs');
    }
};
