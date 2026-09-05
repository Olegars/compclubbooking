<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_documents', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('kind');
            $table->string('title');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->index(['kind', 'sort_order']);
        });

        Schema::create('staff_document_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('staff_documents')->cascadeOnDelete();
            $table->string('title');
            $table->text('body');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['document_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_document_sections');
        Schema::dropIfExists('staff_documents');
    }
};
