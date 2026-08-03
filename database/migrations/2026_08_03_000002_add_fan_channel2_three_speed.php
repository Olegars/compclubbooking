<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('space_fans', function (Blueprint $table) {
            $table->unsignedSmallInteger('channel2')->default(2)->after('channel');
        });

        $fans = DB::table('space_fans')->orderBy('id')->get();
        foreach ($fans as $fan) {
            $ch1 = (int) $fan->channel;
            $used = DB::table('space_fans')
                ->where('relay_board_id', $fan->relay_board_id)
                ->where('id', '!=', $fan->id)
                ->get(['channel', 'channel2']);

            $taken = [];
            foreach ($used as $row) {
                $taken[(int) $row->channel] = true;
                if ($row->channel2 !== null) {
                    $taken[(int) $row->channel2] = true;
                }
            }
            $taken[$ch1] = true;

            $ch2 = null;
            for ($c = 1; $c <= 16; $c++) {
                if (! isset($taken[$c])) {
                    $ch2 = $c;
                    break;
                }
            }
            if ($ch2 === null) {
                $ch2 = $ch1 >= 16 ? 15 : $ch1 + 1;
            }

            $norm = static function (int $p): int {
                if ($p <= 1) {
                    return 1;
                }
                if ($p === 2) {
                    return 2;
                }
                if ($p === 3) {
                    return 3;
                }
                if ($p < 50) {
                    return 1;
                }
                if ($p < 90) {
                    return 2;
                }

                return 3;
            };

            DB::table('space_fans')->where('id', $fan->id)->update([
                'channel2' => $ch2,
                'applied_power' => $norm((int) $fan->applied_power),
                'desired_power' => $norm((int) $fan->desired_power),
                'default_on_power' => 3,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('space_fans', function (Blueprint $table) {
            $table->dropColumn('channel2');
        });
    }
};
