<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Waitlist;
use App\Models\Zone;
use Illuminate\Http\Request;

class QueueController extends Controller
{
    /**
     * Встать в очередь
     */
    public function join(Request $request)
    {
        $request->validate([
            'zone_id' => 'required|exists:zones,id',
        ]);

        $user = $request->user();

        // Проверяем, не стоит ли он уже в очереди в эту зону
        $alreadyWaiting = Waitlist::where('user_id', $user->id)
            ->whereIn('status', ['waiting', 'notified'])
            ->exists();

        if ($alreadyWaiting) {
            return response()->json(['message' => 'Вы уже находитесь в очереди ожидания'], 400);
        }

        $waitlist = Waitlist::create([
            'user_id' => $user->id,
            'zone_id' => $request->zone_id,
            'status' => 'waiting'
        ]);

        // Вычисляем, какой он по счету
        $position = Waitlist::where('zone_id', $request->zone_id)
            ->where('status', 'waiting')
            ->where('id', '<=', $waitlist->id)
            ->count();

        return response()->json([
            'message' => 'Вы успешно добавлены в очередь',
            'position' => $position
        ]);
    }

    /**
     * Получить текущий статус (терминал или телефон гостя может опрашивать этот роут)
     */
    public function status(Request $request)
    {
        $user = $request->user();

        $activeQueue = Waitlist::with('zone')
            ->where('user_id', $user->id)
            ->whereIn('status', ['waiting', 'notified'])
            ->first();

        if (!$activeQueue) {
            return response()->json(['in_queue' => false]);
        }

        $position = Waitlist::where('zone_id', $activeQueue->zone_id)
            ->where('status', 'waiting')
            ->where('id', '<=', $activeQueue->id)
            ->count();

        return response()->json([
            'in_queue' => true,
            'status' => $activeQueue->status,
            'zone' => $activeQueue->zone->name,
            'position' => $position
        ]);
    }

    /**
     * Покинуть очередь
     */
    public function leave(Request $request)
    {
        $user = $request->user();
        Waitlist::where('user_id', $user->id)
            ->whereIn('status', ['waiting', 'notified'])
            ->update(['status' => 'cancelled']);

        return response()->json(['message' => 'Вы покинули очередь']);
    }
}
