<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('bonus_amount', 10, 2)->default(100);
            $table->unsignedInteger('site_reviews_limit')->default(6);
            $table->boolean('show_on_site')->default(true);
            $table->timestamps();
        });

        DB::table('review_settings')->insert([
            'bonus_amount' => (float) (config('club.reviews.bonus_amount') ?: 100),
            'site_reviews_limit' => 6,
            'show_on_site' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::create('map_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('source', 20);
            $table->string('external_review_id', 191);
            $table->string('external_author_id', 191)->nullable()->index();
            $table->string('author_name')->nullable();
            $table->text('text');
            $table->decimal('rating', 3, 1)->default(0);
            $table->string('url', 500)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('rewarded_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('review_claim_id')->nullable()->constrained('review_claims')->nullOnDelete();
            $table->timestamps();

            $table->unique(['source', 'external_review_id']);
        });

        Schema::table('review_claims', function (Blueprint $table) {
            $table->string('external_author_id', 191)->nullable()->after('external_review_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('review_claims', function (Blueprint $table) {
            $table->dropColumn('external_author_id');
        });
        Schema::dropIfExists('map_reviews');
        Schema::dropIfExists('review_settings');
    }
};
