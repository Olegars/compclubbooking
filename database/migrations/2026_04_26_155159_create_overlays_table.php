<?php

// database/migrations/xxxx_create_overlays_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overlays', function (Blueprint $table) {
            $table->id();
            $table->string('block_position'); // 'top_left', 'top_right', 'mid_left' и т.д. (наши 6 блоков)
            $table->string('title');          // Название блока (например, "HARDWARE STORE")
            $table->string('type');           // Тип контента: 'text', 'list', 'promo', 'html'
            $table->json('content');          // Само содержимое (текст, массив данных, JSON)
            $table->boolean('is_active')->default(true); // Включен/выключен
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overlays');
    }
};
