<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_claims', function (Blueprint $table) {
            $table->text('review_text')->nullable()->after('user_id');
            $table->string('source', 20)->nullable()->after('review_text');
            $table->string('external_review_id', 191)->nullable()->after('source');
            $table->decimal('matched_score', 5, 4)->nullable()->after('external_review_id');
            $table->timestamp('expires_at')->nullable()->after('verified_at');
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            try {
                DB::statement('ALTER TABLE review_claims DROP INDEX review_claims_review_link_unique');
            } catch (\Throwable $e) {
                // unique index may already be gone
            }
            DB::statement('ALTER TABLE review_claims MODIFY review_link VARCHAR(500) NULL');
            DB::statement("ALTER TABLE review_claims MODIFY COLUMN status ENUM('pending','approved','rejected','expired') NOT NULL DEFAULT 'pending'");
        } else {
            Schema::table('review_claims', function (Blueprint $table) {
                $table->string('review_link', 500)->nullable()->change();
            });
        }

        Schema::table('review_claims', function (Blueprint $table) {
            $table->unique('external_review_id');
        });
    }

    public function down(): void
    {
        Schema::table('review_claims', function (Blueprint $table) {
            $table->dropUnique(['external_review_id']);
            $table->dropColumn(['review_text', 'source', 'external_review_id', 'matched_score', 'expires_at']);
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE review_claims MODIFY COLUMN status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending'");
            DB::statement('ALTER TABLE review_claims MODIFY review_link VARCHAR(500) NOT NULL');
            try {
                DB::statement('ALTER TABLE review_claims ADD UNIQUE review_claims_review_link_unique (review_link)');
            } catch (\Throwable $e) {
                // ignore
            }
        }
    }
};
