<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('referral_code', 16)->nullable()->unique();
            $table->foreignId('referred_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('wallets', function (Blueprint $table) {
            $table->unsignedInteger('bonus_minutes')->default(0);
        });

        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referee_user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('promo_code', 16)->unique();
            $table->unsignedTinyInteger('promo_percent')->default(0);
            $table->timestamp('promo_expires_at')->nullable();
            $table->timestamp('promo_used_at')->nullable();
            $table->foreignId('promo_booking_group_id')->nullable()->constrained('booking_groups')->nullOnDelete();
            $table->timestamp('first_paid_at')->nullable();
            $table->foreignId('first_booking_group_id')->nullable()->constrained('booking_groups')->nullOnDelete();
            $table->foreignId('reward_booking_group_id')->nullable()->constrained('booking_groups')->nullOnDelete();
            $table->timestamp('rewarded_at')->nullable();
            $table->json('reward_snapshot')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');

        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn('bonus_minutes');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referred_by_user_id');
            $table->dropColumn('referral_code');
        });
    }
};
