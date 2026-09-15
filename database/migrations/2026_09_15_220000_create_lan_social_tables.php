<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pc_thrones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('club_id')->index();
            $table->foreignId('computer_id')->constrained('computers')->cascadeOnDelete();
            $table->date('recorded_on');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->string('nickname', 48);
            $table->string('avatar', 80)->nullable();
            $table->string('game', 16)->default('cs2');
            $table->string('metric', 16)->default('kills');
            $table->unsignedInteger('kills')->default(0);
            $table->unsignedInteger('deaths')->default(0);
            $table->unsignedInteger('wins')->default(0);
            $table->unsignedInteger('losses')->default(0);
            $table->decimal('kd', 8, 2)->default(0);
            $table->timestamps();

            $table->unique(['computer_id', 'recorded_on']);
            $table->index(['club_id', 'recorded_on']);
        });

        Schema::create('lan_lfg_queues', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('club_id')->index();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('computer_id')->constrained('computers')->cascadeOnDelete();
            $table->string('game', 16);
            $table->string('rank', 32);
            $table->unsignedTinyInteger('rank_tier')->default(5);
            $table->string('status', 16)->default('open')->index();
            $table->foreignId('matched_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('matched_computer_id')->nullable()->constrained('computers')->nullOnDelete();
            $table->foreignId('matched_queue_id')->nullable()->constrained('lan_lfg_queues')->nullOnDelete();
            $table->timestamp('matched_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['club_id', 'game', 'status']);
            $table->index(['user_id', 'status']);
        });

        Schema::table('guest_clips', function (Blueprint $table) {
            $table->string('aspect', 8)->nullable()->after('duration_sec');
            $table->string('source', 16)->default('manual')->after('aspect');
        });
    }

    public function down(): void
    {
        Schema::table('guest_clips', function (Blueprint $table) {
            $table->dropColumn(['aspect', 'source']);
        });
        Schema::dropIfExists('lan_lfg_queues');
        Schema::dropIfExists('pc_thrones');
    }
};
