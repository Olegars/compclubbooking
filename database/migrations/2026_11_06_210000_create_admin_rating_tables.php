<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_rating_events', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->unsignedInteger('points');
            $table->string('direction', 16);
            $table->timestamps();
        });

        Schema::create('admin_rating_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->foreignId('event_id')->nullable()->constrained('admin_rating_events')->nullOnDelete();
            $table->string('event_name', 80);
            $table->integer('points');
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_rating_entries');
        Schema::dropIfExists('admin_rating_events');
    }
};
