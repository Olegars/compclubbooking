<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payrolls', function (Blueprint $table) {
            $table->id();

            // ВАЖНО: Привязываем к таблице admins!
            $table->foreignId('admin_id')->constrained('admins')->onDelete('cascade');

            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('gross_pay', 10, 2);
            $table->decimal('ndfl_tax', 10, 2);
            $table->decimal('net_pay', 10, 2);
            $table->decimal('employer_taxes', 10, 2);
            $table->enum('status', ['draft', 'paid'])->default('draft');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payrolls');
    }
};
