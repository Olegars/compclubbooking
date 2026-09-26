<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Баллы эффективности смены. 70% месяца — премия в ведомости, 30% — квартальный фонд.
 * Слова «штраф», «вычет», «удержание» в текстах этой подсистемы не используются.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_bonus_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('xp_to_rub_rate', 8, 2)->default(10);
            $table->decimal('bar_target_rub', 12, 2)->default(15000);
            $table->timestamps();
        });

        Schema::create('staff_xp_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->foreignId('club_id')->nullable()->constrained('clubs')->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->integer('amount_xp');
            $table->string('action_type', 40);
            $table->string('description');
            $table->string('period_key', 7);
            $table->foreignId('settlement_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['admin_id', 'period_key', 'settlement_id']);
            $table->unique(['shift_id', 'action_type']);
        });

        Schema::create('staff_bonus_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->foreignId('club_id')->nullable()->constrained('clubs')->nullOnDelete();
            $table->string('period_type', 24);
            $table->string('period_label', 16);
            $table->integer('total_xp');
            $table->decimal('rate', 8, 2);
            $table->decimal('calculated_rub', 12, 2);
            $table->decimal('paid_rub', 12, 2)->default(0);
            $table->decimal('held_rub', 12, 2)->default(0);
            $table->string('status', 20)->default('approved');
            $table->foreignId('approved_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('ledger_id')->nullable()->constrained('staff_ledgers')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['admin_id', 'period_type', 'period_label']);
        });

        Schema::table('staff_xp_transactions', function (Blueprint $table) {
            $table->foreign('settlement_id')
                ->references('id')
                ->on('staff_bonus_settlements')
                ->nullOnDelete();
        });

        Schema::table('staff_quarter_reserves', function (Blueprint $table) {
            $table->timestamp('burned_at')->nullable()->after('points');
        });
    }

    public function down(): void
    {
        Schema::table('staff_quarter_reserves', function (Blueprint $table) {
            $table->dropColumn('burned_at');
        });

        Schema::table('staff_xp_transactions', function (Blueprint $table) {
            $table->dropForeign(['settlement_id']);
        });

        Schema::dropIfExists('staff_bonus_settlements');
        Schema::dropIfExists('staff_xp_transactions');
        Schema::dropIfExists('staff_bonus_settings');
    }
};
