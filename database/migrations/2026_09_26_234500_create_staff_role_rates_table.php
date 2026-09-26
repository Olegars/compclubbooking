<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_role_rates', function (Blueprint $table) {
            $table->id();
            $table->string('role', 32)->unique();
            $table->decimal('shift_rate', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_role_rates');
    }
};
