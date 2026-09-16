<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lucky_seat_drops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('computer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('club_id')->nullable()->index();
            $table->string('trigger', 24);
            $table->string('status', 16)->default('pending');
            $table->string('reward_type', 24)->nullable();
            $table->json('reward')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index(['booking_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('store_promo_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 24)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('percent')->default(10);
            $table->string('scope', 32)->default('peripheral');
            $table->string('status', 16)->default('active');
            $table->foreignId('lucky_seat_drop_id')->nullable()->constrained('lucky_seat_drops')->nullOnDelete();
            $table->unsignedBigInteger('store_order_id')->nullable()->index();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::table('store_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('store_orders', 'promo_code_id')) {
                $table->unsignedBigInteger('promo_code_id')->nullable()->after('total');
            }
            if (! Schema::hasColumn('store_orders', 'discount_amount')) {
                $table->decimal('discount_amount', 12, 2)->default(0)->after('promo_code_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            if (Schema::hasColumn('store_orders', 'discount_amount')) {
                $table->dropColumn('discount_amount');
            }
            if (Schema::hasColumn('store_orders', 'promo_code_id')) {
                $table->dropColumn('promo_code_id');
            }
        });
        Schema::dropIfExists('store_promo_codes');
        Schema::dropIfExists('lucky_seat_drops');
    }
};
