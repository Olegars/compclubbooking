<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_spec_dictionary', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained('clubs')->cascadeOnDelete();
            $table->string('dict_key', 64); // cpu_brand, ram_brand, ...
            $table->string('value', 128);
            $table->timestamps();

            $table->unique(['club_id', 'dict_key', 'value']);
            $table->index(['club_id', 'dict_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_spec_dictionary');
    }
};
