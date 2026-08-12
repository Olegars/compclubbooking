<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained('clubs')->cascadeOnDelete();
            $table->string('name');
            $table->string('phone', 32);
            $table->string('email')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['club_id', 'phone']);
            $table->index(['club_id', 'name']);
        });

        Schema::create('store_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained('clubs')->cascadeOnDelete();
            $table->string('name');
            $table->string('sku', 64)->nullable();
            $table->string('category', 32)->default('component'); // component|pc|peripheral|service
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('cost', 12, 2)->nullable();
            $table->integer('stock')->default(0);
            $table->boolean('serial_tracked')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['club_id', 'sku']);
            $table->index(['club_id', 'category']);
        });

        Schema::create('store_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained('clubs')->cascadeOnDelete();
            $table->foreignId('store_product_id')->constrained('store_products')->cascadeOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('type', 32); // receive|write_off|inventory|sale|return
            $table->integer('qty');
            $table->integer('stock_after')->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['club_id', 'created_at']);
        });

        Schema::create('store_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained('clubs')->cascadeOnDelete();
            $table->foreignId('store_client_id')->nullable()->constrained('store_clients')->nullOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('status', 32)->default('new'); // new|assembling|ready|issued|cancelled|returned
            $table->decimal('total', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['club_id', 'status']);
        });

        Schema::create('store_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_order_id')->constrained('store_orders')->cascadeOnDelete();
            $table->foreignId('store_product_id')->nullable()->constrained('store_products')->nullOnDelete();
            $table->string('name');
            $table->unsignedInteger('qty')->default(1);
            $table->decimal('price', 12, 2)->default(0);
            $table->json('serials')->nullable();
            $table->timestamps();
        });

        Schema::create('store_warranties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained('clubs')->cascadeOnDelete();
            $table->foreignId('store_client_id')->nullable()->constrained('store_clients')->nullOnDelete();
            $table->foreignId('store_order_id')->nullable()->constrained('store_orders')->nullOnDelete();
            $table->foreignId('store_order_item_id')->nullable()->constrained('store_order_items')->nullOnDelete();
            $table->string('serial')->nullable();
            $table->string('product_name')->nullable();
            $table->date('started_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->string('status', 32)->default('active'); // active|claimed|closed
            $table->text('claim_notes')->nullable();
            $table->timestamps();

            $table->index(['club_id', 'status']);
            $table->index(['club_id', 'serial']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_warranties');
        Schema::dropIfExists('store_order_items');
        Schema::dropIfExists('store_orders');
        Schema::dropIfExists('store_stock_movements');
        Schema::dropIfExists('store_products');
        Schema::dropIfExists('store_clients');
    }
};
