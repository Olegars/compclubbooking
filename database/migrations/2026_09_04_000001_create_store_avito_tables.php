<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_avito_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->unsignedTinyInteger('ads_per_hour')->default(20);
            $table->unsignedInteger('keep_active')->default(200);
            $table->string('address')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('manager_name')->nullable();
            $table->string('pc_type', 32)->default('Игровой');
            $table->decimal('markup_percent', 8, 2)->default(15);
            $table->decimal('extra_rub', 12, 2)->default(4000);
            $table->unsignedInteger('round_to')->default(100);
            $table->decimal('discount_over_60k_pct', 8, 2)->default(2);
            $table->decimal('discount_over_100k_pct', 8, 2)->default(4);
            $table->string('client_id')->nullable();
            $table->text('client_secret')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('access_token_expires_at')->nullable();
            $table->unsignedBigInteger('avito_user_id')->nullable();
            $table->string('feed_token', 64)->nullable()->unique();
            $table->boolean('auto_reply_enabled')->default(true);
            $table->unsignedTinyInteger('auto_reply_from')->default(0);
            $table->unsignedTinyInteger('auto_reply_to')->default(10);
            $table->text('auto_reply_text')->nullable();
            $table->timestamp('last_generated_at')->nullable();
            $table->json('last_generate_result')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('store_avito_product_attrs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sku')->unique();
            $table->string('type', 32);
            $table->string('socket', 32)->nullable();
            $table->string('ddr', 16)->nullable();
            $table->unsignedSmallInteger('ram_gb')->nullable();
            $table->unsignedSmallInteger('wattage')->nullable();
            $table->string('form', 16)->nullable();
            $table->string('avito_brand')->nullable();
            $table->string('avito_model')->nullable();
            $table->string('avito_code')->nullable();
            $table->string('source', 16)->default('heuristic');
            $table->timestamp('mapped_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'socket']);
            $table->index(['type', 'ddr']);
        });

        Schema::create('store_avito_ads', function (Blueprint $table) {
            $table->id();
            $table->string('config_id', 16)->unique();
            $table->string('fingerprint', 64)->unique();
            $table->string('title');
            $table->longText('description');
            $table->unsignedInteger('price');
            $table->json('components');
            $table->json('xml');
            $table->json('images')->nullable();
            $table->string('status', 16)->default('active');
            $table->unsignedBigInteger('avito_id')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'generated_at']);
        });

        Schema::create('store_avito_chats', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id')->unique();
            $table->unsignedBigInteger('avito_user_id')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->string('client_name')->nullable();
            $table->string('client_avatar')->nullable();
            $table->string('client_link')->nullable();
            $table->unsignedBigInteger('ad_id')->nullable();
            $table->string('ad_title')->nullable();
            $table->string('ad_url')->nullable();
            $table->unsignedInteger('ad_price')->nullable();
            $table->string('config_id', 16)->nullable()->index();
            $table->boolean('important')->default(false);
            $table->boolean('unread')->default(true);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
        });

        Schema::create('store_avito_messages', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id');
            $table->string('avito_message_id')->nullable()->index();
            $table->unsignedBigInteger('author_id')->nullable();
            $table->string('type', 32)->default('text');
            $table->json('content')->nullable();
            $table->boolean('from_us')->default(false);
            $table->boolean('read')->default(false);
            $table->timestamp('avito_created_at')->nullable();
            $table->timestamps();

            $table->index(['chat_id', 'id']);
            $table->foreign('chat_id')->references('chat_id')->on('store_avito_chats')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_avito_messages');
        Schema::dropIfExists('store_avito_chats');
        Schema::dropIfExists('store_avito_ads');
        Schema::dropIfExists('store_avito_product_attrs');
        Schema::dropIfExists('store_avito_settings');
    }
};
