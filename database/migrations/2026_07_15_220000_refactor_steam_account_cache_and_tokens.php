<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Поля аккаунта, общие для всех машин (JWT / Guard / профиль)
        Schema::table('game_accounts', function (Blueprint $table) {
            if (!Schema::hasColumn('game_accounts', 'shared_secret')) {
                $table->string('shared_secret', 128)->nullable()->after('password');
            }
            if (!Schema::hasColumn('game_accounts', 'persona_name')) {
                $table->string('persona_name')->nullable()->after('shared_secret');
            }
            if (!Schema::hasColumn('game_accounts', 'steam_id')) {
                $table->string('steam_id', 32)->nullable()->after('persona_name');
            }
            if (!Schema::hasColumn('game_accounts', 'refresh_token')) {
                $table->text('refresh_token')->nullable()->after('steam_id');
            }
            if (!Schema::hasColumn('game_accounts', 'refresh_token_updated_at')) {
                $table->timestamp('refresh_token_updated_at')->nullable()->after('refresh_token');
            }
        });

        // Кэш VDF: многие-ко-многим (аккаунт × компьютер)
        if (!Schema::hasTable('game_account_machine_caches')) {
            Schema::create('game_account_machine_caches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('game_account_id')->constrained('game_accounts')->cascadeOnDelete();
                $table->foreignId('computer_id')->constrained('computers')->cascadeOnDelete();
                $table->longText('config_vdf')->nullable();
                $table->longText('loginusers_vdf')->nullable();
                $table->longText('local_vdf')->nullable();
                $table->timestamps();

                $table->unique(['game_account_id', 'computer_id'], 'game_account_computer_cache_unique');
            });
        }

        // Перенос старых VDF с аккаунта → pivot, если знаем current_pc_id
        if (Schema::hasColumn('game_accounts', 'config_vdf')
            || Schema::hasColumn('game_accounts', 'loginusers_vdf')
            || Schema::hasColumn('game_accounts', 'local_vdf')
        ) {
            $accounts = DB::table('game_accounts')
                ->whereNotNull('current_pc_id')
                ->get();

            foreach ($accounts as $account) {
                $config = $account->config_vdf ?? null;
                $loginusers = $account->loginusers_vdf ?? null;
                $local = $account->local_vdf ?? null;

                if ($config === null && $loginusers === null && $local === null) {
                    continue;
                }

                $exists = DB::table('computers')->where('id', $account->current_pc_id)->exists();
                if (!$exists) {
                    continue;
                }

                DB::table('game_account_machine_caches')->updateOrInsert(
                    [
                        'game_account_id' => $account->id,
                        'computer_id' => $account->current_pc_id,
                    ],
                    [
                        'config_vdf' => $config,
                        'loginusers_vdf' => $loginusers,
                        'local_vdf' => $local,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }

            Schema::table('game_accounts', function (Blueprint $table) {
                $drop = [];
                foreach (['config_vdf', 'loginusers_vdf', 'local_vdf'] as $column) {
                    if (Schema::hasColumn('game_accounts', $column)) {
                        $drop[] = $column;
                    }
                }
                if (!empty($drop)) {
                    $table->dropColumn($drop);
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('game_accounts', function (Blueprint $table) {
            if (!Schema::hasColumn('game_accounts', 'config_vdf')) {
                $table->longText('config_vdf')->nullable();
            }
            if (!Schema::hasColumn('game_accounts', 'loginusers_vdf')) {
                $table->longText('loginusers_vdf')->nullable();
            }
            if (!Schema::hasColumn('game_accounts', 'local_vdf')) {
                $table->longText('local_vdf')->nullable();
            }
        });

        Schema::dropIfExists('game_account_machine_caches');

        Schema::table('game_accounts', function (Blueprint $table) {
            foreach (['refresh_token_updated_at', 'refresh_token', 'steam_id'] as $column) {
                if (Schema::hasColumn('game_accounts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
