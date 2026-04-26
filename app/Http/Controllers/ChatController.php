<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class ChatController extends Controller
{
    // Игрок зовет админа
    public function callAdmin(Request $request)
    {
        $request->validate(['message' => 'required|string|max:255']);

        // Сохраняем вызов в таблицу (создай её или используй существующую)
        DB::table('admin_calls')->insert([
            'user_id' => Auth::id(),
            'pc_name' => Auth::user()->name, // Или логика получения номера ПК
            'message' => $request->message,
            'status' => 'pending',
            'created_at' => Carbon::now(),
        ]);

        return response()->json(['status' => 'signal_sent']);
    }

    // Админка запрашивает список активных вызовов
    public function getActiveCalls()
    {
        $calls = DB::table('admin_calls')
            ->where('status', 'pending')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function($call) {
                return [
                    'id' => $call->id,
                    'pc_name' => $call->pc_name,
                    'message' => $call->message,
                    'time' => Carbon::parse($call->created_at)->format('H:i'),
                ];
            });

        return response()->json($calls);
    }

    // Админ закрывает тикет
    public function resolveCall($id)
    {
        DB::table('admin_calls')
            ->where('id', $id)
            ->update(['status' => 'resolved', 'updated_at' => Carbon::now()]);

        return response()->json(['status' => 'resolved']);
    }
}
