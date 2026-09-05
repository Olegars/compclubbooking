<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_interns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained('shifts')->cascadeOnDelete();
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->timestamp('joined_at');
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->unique(['shift_id', 'admin_id']);
            $table->index(['admin_id', 'left_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_interns');
    }
};
