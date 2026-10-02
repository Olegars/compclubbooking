<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->string('kind', 24); // fiscalize | print_copy
            $table->uuid('external_id')->unique();
            $table->string('status', 24)->default('pending'); // pending|claimed|success|error|uncertain
            $table->json('payload');
            $table->json('result')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
            $table->index(['transaction_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_jobs');
    }
};
