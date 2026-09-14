<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_avito_chats', function (Blueprint $table) {
            $table->string('workflow', 16)->default('inbox')->after('important');
            $table->foreignId('accepted_by_id')->nullable()->after('workflow')->constrained('admins')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable()->after('accepted_by_id');
            $table->timestamp('done_at')->nullable()->after('accepted_at');
            $table->index('workflow');
        });

        Schema::table('store_avito_messages', function (Blueprint $table) {
            $table->foreignId('admin_id')->nullable()->after('from_us')->constrained('admins')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('store_avito_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('admin_id');
        });

        Schema::table('store_avito_chats', function (Blueprint $table) {
            $table->dropIndex(['workflow']);
            $table->dropConstrainedForeignId('accepted_by_id');
            $table->dropColumn(['workflow', 'accepted_at', 'done_at']);
        });
    }
};
