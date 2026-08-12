<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained('clubs')->cascadeOnDelete();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['club_id', 'name']);
        });

        Schema::create('store_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained('clubs')->cascadeOnDelete();
            $table->foreignId('store_supplier_id')->nullable()->constrained('store_suppliers')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('name');
            $table->string('type', 32); // cpu, ram, gpu, ...
            $table->decimal('purchase_price', 12, 2)->default(0);
            $table->string('warranty_number')->nullable();
            $table->unsignedInteger('warranty_months')->nullable();
            $table->unsignedInteger('qty')->default(1);
            $table->string('status', 32)->default('in_stock'); // in_stock|reserved|used|written_off
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['club_id', 'type']);
            $table->index(['club_id', 'status']);
        });

        Schema::create('store_built_pcs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained('clubs')->cascadeOnDelete();
            $table->foreignId('store_client_id')->nullable()->constrained('store_clients')->nullOnDelete();
            $table->foreignId('assembled_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('accepted_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('title')->nullable(); // краткое название сборки
            $table->json('build_spec')->nullable(); // комплектация: [{type,name,component_id?}, ...]
            $table->string('serial_number')->nullable();
            $table->decimal('sale_price', 12, 2)->nullable();
            $table->string('sale_tax_mode', 32)->nullable(); // with_tax|without_tax
            $table->timestamp('sold_at')->nullable();
            $table->string('status', 32)->default('assembling'); // assembling|ready|sold|cancelled
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['club_id', 'status']);
            $table->unique(['club_id', 'serial_number']);
        });

        Schema::create('store_built_pc_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_built_pc_id')->constrained('store_built_pcs')->cascadeOnDelete();
            $table->foreignId('store_component_id')->nullable()->constrained('store_components')->nullOnDelete();
            $table->string('type', 32)->nullable();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_built_pc_components');
        Schema::dropIfExists('store_built_pcs');
        Schema::dropIfExists('store_components');
        Schema::dropIfExists('store_suppliers');
    }
};
