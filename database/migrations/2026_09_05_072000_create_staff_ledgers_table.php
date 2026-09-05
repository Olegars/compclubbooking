<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->enum('type', ['accrual', 'fine', 'payout']);
            $table->decimal('amount', 10, 2);
            $table->string('reason')->nullable();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->string('period_key', 16)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->unique('shift_id');
            $table->index(['admin_id', 'type']);
            $table->index(['admin_id', 'type', 'period_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_ledgers');
    }
};
