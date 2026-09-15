<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('store_avito_settings')) {
            return;
        }
        Schema::table('store_avito_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('store_avito_settings', 'ringtone_path')) {
                $table->string('ringtone_path', 255)->nullable()->after('auto_reply_text');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('store_avito_settings') || ! Schema::hasColumn('store_avito_settings', 'ringtone_path')) {
            return;
        }
        Schema::table('store_avito_settings', function (Blueprint $table) {
            $table->dropColumn('ringtone_path');
        });
    }
};
