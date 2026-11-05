<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_assistant_settings', function (Blueprint $table) {
            $table->text('alibaba_access_key_id')->nullable()->after('greeting_prompt');
            $table->text('alibaba_access_key_secret')->nullable()->after('alibaba_access_key_id');
            $table->text('yandex_billing_iam_token')->nullable()->after('alibaba_access_key_secret');
            $table->string('yandex_billing_account_id', 64)->nullable()->after('yandex_billing_iam_token');
        });
    }

    public function down(): void
    {
        Schema::table('ai_assistant_settings', function (Blueprint $table) {
            $table->dropColumn([
                'alibaba_access_key_id',
                'alibaba_access_key_secret',
                'yandex_billing_iam_token',
                'yandex_billing_account_id',
            ]);
        });
    }
};
