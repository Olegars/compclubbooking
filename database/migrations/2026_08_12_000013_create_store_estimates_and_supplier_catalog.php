<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_supplier_catalog_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('external_id')->unique();
            $table->unsignedBigInteger('parent_external_id')->nullable()->index();
            $table->string('name');
            $table->boolean('leaf')->default(false);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('store_supplier_catalog_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sku')->unique();
            $table->unsignedBigInteger('category_external_id')->nullable()->index();
            $table->string('name');
            $table->string('part')->nullable()->index();
            $table->string('vendor')->nullable()->index();
            $table->decimal('rrp', 12, 2)->nullable();
            $table->string('warranty')->nullable();
            $table->unsignedInteger('multiplicity')->default(1);
            $table->text('barcodes')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['name']);
        });

        Schema::create('store_estimates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained('clubs')->cascadeOnDelete();
            $table->foreignId('store_client_id')->nullable()->constrained('store_clients')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('store_order_id')->nullable()->constrained('store_orders')->nullOnDelete();
            $table->string('title')->nullable();
            $table->string('status', 32)->default('draft'); // draft|agreed|procuring|ready|converted|cancelled
            $table->decimal('sale_total', 12, 2)->default(0);
            $table->decimal('purchase_total', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['club_id', 'status']);
        });

        Schema::create('store_estimate_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_estimate_id')->constrained('store_estimates')->cascadeOnDelete();
            $table->string('type', 32)->nullable();
            $table->string('name');
            $table->string('part')->nullable();
            $table->unsignedBigInteger('supplier_sku')->nullable()->index();
            $table->string('supplier_part')->nullable();
            $table->string('supplier_name')->nullable();
            $table->decimal('supplier_price', 12, 2)->nullable();
            $table->unsignedInteger('supplier_qty')->nullable();
            $table->decimal('sale_price', 12, 2)->nullable();
            $table->unsignedInteger('qty')->default(1);
            $table->string('status', 32)->default('planned'); // planned|from_stock|to_order|ordered|received
            $table->foreignId('store_component_id')->nullable()->constrained('store_components')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['store_estimate_id', 'status']);
        });

        Schema::create('store_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained('clubs')->cascadeOnDelete();
            $table->foreignId('store_estimate_id')->constrained('store_estimates')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->unsignedBigInteger('external_order_id')->nullable()->index();
            $table->string('status', 32)->default('draft'); // draft|submitted|confirmed|received|cancelled
            $table->decimal('total', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->index(['club_id', 'status']);
        });

        Schema::create('store_purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_purchase_id')->constrained('store_purchases')->cascadeOnDelete();
            $table->foreignId('store_estimate_item_id')->nullable()->constrained('store_estimate_items')->nullOnDelete();
            $table->unsignedBigInteger('supplier_sku');
            $table->string('name');
            $table->unsignedInteger('qty')->default(1);
            $table->decimal('price', 12, 2)->default(0);
            $table->string('status', 32)->default('pending'); // pending|ordered|received
            $table->foreignId('store_component_id')->nullable()->constrained('store_components')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_purchase_items');
        Schema::dropIfExists('store_purchases');
        Schema::dropIfExists('store_estimate_items');
        Schema::dropIfExists('store_estimates');
        Schema::dropIfExists('store_supplier_catalog_products');
        Schema::dropIfExists('store_supplier_catalog_categories');
    }
};
