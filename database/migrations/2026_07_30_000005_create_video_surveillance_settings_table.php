<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_surveillance_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->nullable()->constrained('clubs')->nullOnDelete();
            $table->boolean('is_enabled')->default(false);
            /** generic_webhook | hikvision | trassir | macroscop | custom */
            $table->string('provider', 32)->default('generic_webhook');
            $table->string('api_base_url', 512)->nullable();
            $table->string('api_login', 191)->nullable();
            $table->text('api_secret')->nullable();
            $table->unsignedInteger('marker_duration_sec')->default(30);
            $table->unsignedInteger('marker_pre_sec')->default(0);
            $table->string('default_channel', 128)->nullable();
            $table->string('webhook_path', 255)->nullable();
            $table->string('webhook_method', 16)->default('POST');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique('club_id');
        });

        Schema::create('video_surveillance_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->nullable()->constrained('clubs')->nullOnDelete();
            /** Уникальный код события (для API / логов) */
            $table->string('code', 64);
            $table->string('name', 191);
            $table->string('description', 500)->nullable();
            $table->boolean('is_enabled')->default(true);
            /**
             * Системный триггер (пока известные):
             * hid.disconnected | hid.device_changed | hid.unstable | manual | custom
             */
            $table->string('trigger_key', 64)->nullable();
            $table->string('channel', 128)->nullable(); // переопределение камеры
            $table->string('marker_title', 191)->nullable(); // шаблон заголовка метки
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['club_id', 'code']);
            $table->index(['club_id', 'trigger_key', 'is_enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_surveillance_events');
        Schema::dropIfExists('video_surveillance_settings');
    }
};
