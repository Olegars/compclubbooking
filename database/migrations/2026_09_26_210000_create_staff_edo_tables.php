<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * КЭДО персонала: согласие на ПЭП, дисциплинарные инциденты смен, акты.
 * Срок объяснений — 2 рабочих дня (ст. 193 ТК РФ).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_edo_agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->unique()->constrained('admins')->cascadeOnDelete();
            $table->string('agreement_version', 20);
            $table->string('phone_number', 20);
            $table->string('telegram_id', 32)->nullable();
            $table->timestamp('signed_at');
            $table->string('ip_address', 45);
            $table->text('user_agent')->nullable();
            $table->string('otp_code_hash', 64);
            $table->string('document_hash', 64);
            $table->timestamps();
        });

        Schema::create('staff_disciplinary_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->foreignId('club_id')->nullable()->constrained('clubs')->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->foreignId('shift_slot_booking_id')->nullable()->constrained('shift_slot_bookings')->nullOnDelete();
            $table->string('incident_type', 32);
            $table->timestamp('detected_at');
            $table->timestamp('deadline_at')->nullable();
            $table->string('status', 40)->default('demand_sent');
            $table->timestamp('demand_delivered_at')->nullable();
            $table->text('explanation_text')->nullable();
            $table->json('explanation_files')->nullable();
            $table->timestamp('explanation_signed_at')->nullable();
            $table->json('evidence_meta')->nullable();
            $table->string('resolution', 32)->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->decimal('xp_forfeited', 10, 2)->default(0);
            $table->timestamps();

            $table->index(['status', 'deadline_at']);
            $table->index(['admin_id', 'status']);
        });

        Schema::create('staff_edo_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->nullable()->constrained('staff_disciplinary_incidents')->cascadeOnDelete();
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->string('doc_type', 40);
            $table->string('doc_title');
            $table->string('storage_path');
            $table->string('doc_hash_sha256', 64);
            $table->json('signatures');
            $table->timestamps();
        });

        Schema::create('staff_edo_otps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->string('purpose', 32);
            $table->string('code_hash', 64);
            $table->string('phone', 20)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('staff_presence_pings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->timestamp('seen_at');
            $table->string('source', 32)->default('face');
            $table->timestamps();

            $table->index(['admin_id', 'seen_at']);
        });

        Schema::create('staff_quarter_reserves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('quarter');
            $table->decimal('points', 10, 2)->default(0);
            $table->timestamps();

            $table->unique(['admin_id', 'year', 'quarter']);
        });

        Schema::create('staff_sfr_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('staff_disciplinary_incidents')->cascadeOnDelete();
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->string('event_code', 32);
            $table->string('reason_code', 32);
            $table->json('payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_sfr_events');
        Schema::dropIfExists('staff_quarter_reserves');
        Schema::dropIfExists('staff_presence_pings');
        Schema::dropIfExists('staff_edo_otps');
        Schema::dropIfExists('staff_edo_documents');
        Schema::dropIfExists('staff_disciplinary_incidents');
        Schema::dropIfExists('staff_edo_agreements');
    }
};
