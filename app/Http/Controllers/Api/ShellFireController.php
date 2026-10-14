<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Computer;
use App\Services\Fire\FireAlarmService;
use Illuminate\Http\Request;

class ShellFireController extends Controller
{
    public function show(Request $request, FireAlarmService $alarms)
    {
        $terminalId = (int) $request->query('terminal_id', 0);
        $computer = $terminalId > 0 ? Computer::query()->find($terminalId) : null;

        return response()->json($alarms->screenForComputer($computer));
    }
}
