<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_slot_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->nullable()->constrained('clubs')->nullOnDelete();
            $table->unsignedTinyInteger('hours')->default(12);
            $table->timestamps();

            $table->unique('club_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_slot_settings');
    }
};
