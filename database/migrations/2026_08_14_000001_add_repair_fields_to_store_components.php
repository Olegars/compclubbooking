<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_components', function (Blueprint $table) {
            $table->string('status_before_repair', 32)->nullable()->after('status');
            $table->timestamp('sent_to_repair_at')->nullable()->after('status_before_repair');
            $table->foreignId('replaces_component_id')
                ->nullable()
                ->after('sent_to_repair_at')
                ->constrained('store_components')
                ->nullOnDelete();
            $table->foreignId('replaced_by_component_id')
                ->nullable()
                ->after('replaces_component_id')
                ->constrained('store_components')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('store_components', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replaces_component_id');
            $table->dropConstrainedForeignId('replaced_by_component_id');
            $table->dropColumn(['status_before_repair', 'sent_to_repair_at']);
        });
    }
};
