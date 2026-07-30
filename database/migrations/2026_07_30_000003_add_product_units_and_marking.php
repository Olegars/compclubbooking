<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'requires_marking')) {
                $table->boolean('requires_marking')->default(false)->after('is_active');
            }
        });

        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('marking_code', 512);
            $table->enum('status', ['available', 'sold', 'written_off'])->default('available');
            $table->foreignId('received_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->unsignedBigInteger('sold_order_id')->nullable();
            $table->timestamp('sold_at')->nullable();
            $table->foreignId('written_off_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('write_off_reason')->nullable();
            $table->timestamp('written_off_at')->nullable();
            $table->timestamps();

            $table->unique('marking_code');
            $table->index(['product_id', 'status']);
            $table->index('sold_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_units');

        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'requires_marking')) {
                $table->dropColumn('requires_marking');
            }
        });
    }
};
