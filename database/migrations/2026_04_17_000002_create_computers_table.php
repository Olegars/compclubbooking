<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('computers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->onDelete('cascade'); // Привязка к клубу
            $table->string('name'); // Номер ПК: "01", "02"
            $table->float('x')->default(0); // Меняем на float
            $table->float('y')->default(0); // Меняем на float
            $table->string('type')->default('standard');
            $table->string('status')->default('available'); // available, occupied, maintenance
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('computers');
    }
};
