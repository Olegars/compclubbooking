<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');

            // --- НАШИ НОВЫЕ ПОЛЯ ---

            // 1. Обновленная строгая роль
            $table->enum('role', ['admin', 'supervisor', 'owner'])->default('admin');

            // 2. Зарплатная часть
            $table->boolean('is_official_employee')->default(false);
            $table->decimal('base_rate', 10, 2)->nullable();
            $table->enum('pay_type', ['shift', 'monthly'])->nullable();

            // ------------------------

            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('admins');
    }
};
