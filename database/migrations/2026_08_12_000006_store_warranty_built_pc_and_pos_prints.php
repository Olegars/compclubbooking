<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_warranties', function (Blueprint $table) {
            $table->foreignId('store_built_pc_id')
                ->nullable()
                ->after('store_order_item_id')
                ->constrained('store_built_pcs')
                ->nullOnDelete();
            $table->unsignedSmallInteger('warranty_months')->nullable()->after('ends_at');
            $table->unsignedSmallInteger('repair_days')->nullable()->after('warranty_months');
            $table->json('build_snapshot')->nullable()->after('repair_days');

            $table->unique('store_built_pc_id');
        });

        Schema::create('store_pos_prints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained('clubs')->cascadeOnDelete();
            $table->foreignId('store_warranty_id')->nullable()->constrained('store_warranties')->nullOnDelete();
            $table->string('kind', 32)->default('barcode'); // barcode
            $table->string('serial', 32);
            $table->text('payload_text');
            $table->string('status', 24)->default('pending'); // pending|claimed|printed|failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
            $table->index(['club_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_pos_prints');

        Schema::table('store_warranties', function (Blueprint $table) {
            $table->dropUnique(['store_built_pc_id']);
            $table->dropConstrainedForeignId('store_built_pc_id');
            $table->dropColumn(['warranty_months', 'repair_days', 'build_snapshot']);
        });
    }
};
