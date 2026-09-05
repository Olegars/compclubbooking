<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $indexes = collect(Schema::getIndexes('staff_ledgers'))->pluck('name');

        Schema::table('staff_ledgers', function (Blueprint $table) use ($indexes) {
            if ($indexes->contains('staff_ledgers_admin_id_shift_id_unique')) {
                $table->dropUnique(['admin_id', 'shift_id']);
            }
        });

        $indexes = collect(Schema::getIndexes('staff_ledgers'))->pluck('name');
        Schema::table('staff_ledgers', function (Blueprint $table) use ($indexes) {
            if (! $indexes->contains('staff_ledgers_admin_id_shift_id_type_unique')) {
                $table->unique(['admin_id', 'shift_id', 'type']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('staff_ledgers', function (Blueprint $table) {
            $table->dropUnique(['admin_id', 'shift_id', 'type']);
            $table->unique(['admin_id', 'shift_id']);
        });
    }
};
