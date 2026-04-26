<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void {
        Schema::create('gift_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('users'); // Кто выдал
            $table->foreignId('user_id')->constrained('users');  // Кому выдал
            $table->integer('minutes');                          // Сколько
            $table->string('reason');                            // Причина (выбор из списка)
            $table->string('pc_name')->nullable();               // На каком ПК был сбой
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gift_logs');
    }
};
