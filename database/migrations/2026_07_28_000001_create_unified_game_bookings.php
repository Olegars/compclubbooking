<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('status', 32)->default('pending_payment');
            $table->string('payment_status', 32)->default('unpaid');
            $table->char('currency', 3)->default('RUB');
            $table->unsignedBigInteger('computers_total_minor')->default(0);
            $table->unsignedBigInteger('games_total_minor')->default(0);
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->unsignedBigInteger('paid_total_minor')->default(0);
            $table->unsignedBigInteger('refunded_total_minor')->default(0);
            $table->jsonb('pricing_snapshot')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();

            $table->index(['club_id', 'starts_at', 'ends_at']);
            $table->index(['user_id', 'status']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('booking_group_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->nullOnDelete();
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->unsignedBigInteger('price_minor')->nullable();
            $table->timestampTz('actual_started_at')->nullable();
            $table->timestampTz('actual_ended_at')->nullable();

            $table->index(['computer_id', 'starts_at', 'ends_at'], 'bookings_computer_period_index');
        });

        Schema::create('club_games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_enabled')->default(true);
            $table->string('billing_mode', 32)->default('free');
            $table->unsignedBigInteger('unit_price_minor')->default(0);
            $table->unsignedInteger('billing_unit_minutes')->default(60);
            $table->char('currency', 3)->default('RUB');
            $table->timestampsTz();

            $table->unique(['club_id', 'game_id']);
        });

        Schema::create('computer_games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('computer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_installed')->default(true);
            $table->timestampTz('verified_at')->nullable();
            $table->timestampsTz();

            $table->unique(['computer_id', 'game_id']);
        });

        Schema::table('game_accounts', function (Blueprint $table) {
            $table->foreignId('club_id')
                ->nullable()
                ->after('game_id')
                ->constrained()
                ->nullOnDelete();
            $table->boolean('is_enabled')->default(true);
            $table->index(['club_id', 'game_id', 'is_enabled']);
        });

        Schema::create('booking_games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('club_game_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('game_title');
            $table->string('platform');
            $table->string('billing_mode', 32);
            $table->unsignedBigInteger('unit_price_minor')->default(0);
            $table->unsignedInteger('billing_unit_minutes')->default(60);
            $table->unsignedBigInteger('line_total_minor')->default(0);
            $table->timestampsTz();

            $table->unique(['booking_group_id', 'club_game_id']);
        });

        Schema::create('game_account_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_account_id')->constrained()->restrictOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('status', 32)->default('held');
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->timestampsTz();

            $table->unique(['booking_game_id', 'booking_id']);
            $table->index(['game_account_id', 'starts_at', 'ends_at'], 'game_account_period_index');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('booking_group_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            if (!Schema::hasColumn('transactions', 'payload')) {
                $table->jsonb('payload')->nullable();
            }

            $table->string('idempotency_key')->nullable()->unique();
        });

        $this->backfillLegacyBookings();
        $this->backfillGameCatalog();
        $this->addDatabaseConstraints();
    }

    private function backfillLegacyBookings(): void
    {
        DB::table('bookings')
            ->whereNull('booking_group_id')
            ->orderBy('id')
            ->each(function ($booking) {
                $clubId = DB::table('computers')->where('id', $booking->computer_id)->value('club_id');
                if (!$clubId) {
                    return;
                }

                try {
                    $date = CarbonImmutable::parse($booking->date)->startOfDay();
                } catch (\Throwable) {
                    return;
                }

                $startsAt = $date->addMinutes((int) round(((float) $booking->start_time) * 60));
                $endsAt = $startsAt->addMinutes((int) round(((float) $booking->duration) * 60));
                $priceMinor = max(0, (int) $booking->price * 100);
                $status = match ($booking->status) {
                    'active' => 'active',
                    'completed' => 'completed',
                    'cancelled' => 'cancelled',
                    default => 'confirmed',
                };
                $now = now();

                $groupId = DB::table('booking_groups')->insertGetId([
                    'user_id' => $booking->user_id,
                    'club_id' => $clubId,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'status' => $status,
                    'payment_status' => 'paid',
                    'currency' => 'RUB',
                    'computers_total_minor' => $priceMinor,
                    'games_total_minor' => 0,
                    'total_minor' => $priceMinor,
                    'paid_total_minor' => $priceMinor,
                    'refunded_total_minor' => 0,
                    'pricing_snapshot' => json_encode([
                        'source' => 'legacy_booking',
                        'legacy_booking_id' => $booking->id,
                    ], JSON_THROW_ON_ERROR),
                    'paid_at' => $booking->created_at ?? $now,
                    'cancelled_at' => $status === 'cancelled' ? ($booking->updated_at ?? $now) : null,
                    'created_at' => $booking->created_at ?? $now,
                    'updated_at' => $booking->updated_at ?? $now,
                ]);

                DB::table('bookings')->where('id', $booking->id)->update([
                    'booking_group_id' => $groupId,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'price_minor' => $priceMinor,
                ]);
            });
    }

    private function backfillGameCatalog(): void
    {
        $now = now();
        $gameIds = DB::table('games')->pluck('id');

        foreach (DB::table('clubs')->pluck('id') as $clubId) {
            foreach ($gameIds as $gameId) {
                DB::table('club_games')->insertOrIgnore([
                    'club_id' => $clubId,
                    'game_id' => $gameId,
                    'is_enabled' => true,
                    'billing_mode' => 'free',
                    'unit_price_minor' => 0,
                    'billing_unit_minutes' => 60,
                    'currency' => 'RUB',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        foreach (DB::table('computers')->pluck('id') as $computerId) {
            foreach ($gameIds as $gameId) {
                DB::table('computer_games')->insertOrIgnore([
                    'computer_id' => $computerId,
                    'game_id' => $gameId,
                    'is_installed' => true,
                    'verified_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function addDatabaseConstraints(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        DB::statement("
            ALTER TABLE booking_groups
            ADD CONSTRAINT booking_groups_valid_period
            CHECK (ends_at > starts_at)
        ");

        DB::statement("
            ALTER TABLE bookings
            ADD CONSTRAINT bookings_valid_period
            CHECK (starts_at IS NULL OR ends_at > starts_at)
        ");

        DB::statement("
            ALTER TABLE club_games
            ADD CONSTRAINT club_games_valid_billing_mode
            CHECK (billing_mode IN ('free', 'per_seat_hour', 'per_seat_booking', 'per_booking_hour', 'fixed'))
        ");

        DB::statement("
            ALTER TABLE bookings
            ADD CONSTRAINT bookings_no_computer_overlap
            EXCLUDE USING gist (
                computer_id WITH =,
                tstzrange(starts_at, ends_at, '[)') WITH &&
            ) WHERE (status IN ('confirmed', 'active'))
        ");

        DB::statement("
            ALTER TABLE game_account_reservations
            ADD CONSTRAINT game_accounts_no_reservation_overlap
            EXCLUDE USING gist (
                game_account_id WITH =,
                tstzrange(starts_at, ends_at, '[)') WITH &&
            ) WHERE (status IN ('held', 'confirmed', 'active'))
        ");

        DB::statement('ALTER TABLE transactions DROP CONSTRAINT IF EXISTS transactions_type_check');
        DB::statement("
            ALTER TABLE transactions
            ADD CONSTRAINT transactions_type_check
            CHECK (type IN ('deposit', 'withdraw', 'booking', 'booking_upgrade', 'refund', 'purchase'))
        ");
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['booking_group_id']);
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['booking_group_id', 'idempotency_key']);

            if (Schema::hasColumn('transactions', 'payload')) {
                $table->dropColumn('payload');
            }
        });

        Schema::dropIfExists('game_account_reservations');
        Schema::dropIfExists('booking_games');

        Schema::table('game_accounts', function (Blueprint $table) {
            $table->dropForeign(['club_id']);
            $table->dropIndex(['club_id', 'game_id', 'is_enabled']);
            $table->dropColumn(['club_id', 'is_enabled']);
        });

        Schema::dropIfExists('computer_games');
        Schema::dropIfExists('club_games');

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['booking_group_id']);
            $table->dropIndex('bookings_computer_period_index');
            $table->dropColumn([
                'booking_group_id',
                'starts_at',
                'ends_at',
                'price_minor',
                'actual_started_at',
                'actual_ended_at',
            ]);
        });

        Schema::dropIfExists('booking_groups');
    }
};
