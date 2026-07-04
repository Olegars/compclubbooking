<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('clubs', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Название: "Reactor Center", "Reactor West"
            $table->string('slug')->unique(); // Для URL: "center", "west"
            $table->string('address')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('clubs');
    }
};
