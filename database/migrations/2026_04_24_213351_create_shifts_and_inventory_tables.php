<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // Таблица смен
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('admins'); // Кто открыл
            $table->foreignId('closed_by')->nullable()->constrained('admins'); // Кто закрыл
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->decimal('cash_start', 10, 2)->default(0);
            $table->decimal('cash_end', 10, 2)->nullable();
            $table->enum('status', ['open', 'transferring', 'closed'])->default('open');
            $table->timestamps();
        });

        // Состояние склада при передаче
        Schema::create('shift_inventory', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained();
            $table->integer('expected_stock');
            $table->integer('actual_stock')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('shift_inventory');
        Schema::dropIfExists('shifts');
    }
};
