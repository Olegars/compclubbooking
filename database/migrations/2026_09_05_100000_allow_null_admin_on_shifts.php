<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropForeign(['admin_id']);
            $table->dropForeign(['closed_by']);
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE shifts MODIFY admin_id BIGINT UNSIGNED NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE shifts ALTER COLUMN admin_id DROP NOT NULL');
        } else {
            Schema::table('shifts', function (Blueprint $table) {
                $table->unsignedBigInteger('admin_id')->nullable()->change();
            });
        }

        Schema::table('shifts', function (Blueprint $table) {
            $table->foreign('admin_id')->references('id')->on('admins')->nullOnDelete();
            $table->foreign('closed_by')->references('id')->on('admins')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropForeign(['admin_id']);
            $table->dropForeign(['closed_by']);
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE shifts MODIFY admin_id BIGINT UNSIGNED NOT NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE shifts ALTER COLUMN admin_id SET NOT NULL');
        } else {
            Schema::table('shifts', function (Blueprint $table) {
                $table->unsignedBigInteger('admin_id')->nullable(false)->change();
            });
        }

        Schema::table('shifts', function (Blueprint $table) {
            $table->foreign('admin_id')->references('id')->on('admins');
            $table->foreign('closed_by')->references('id')->on('admins');
        });
    }
};
