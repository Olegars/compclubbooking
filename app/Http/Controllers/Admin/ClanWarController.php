<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClanRating;
use App\Models\ClanWar;
use App\Models\Club;
use App\Services\ClanWarService;
use App\Support\AdminLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use RuntimeException;

class ClanWarController extends Controller
{
    public function index(ClanWarService $wars)
    {
        $admin = Auth::guard('admin')->user();
        $clubId = AdminLocation::id($admin);
        $wars->expireOverdue();

        $list = ClanWar::query()
            ->orderByRaw("case status when 'live' then 0 when 'planned' then 1 else 2 end")
            ->orderByDesc('id')
            ->limit(40)
            ->get()
            ->map(fn (ClanWar $war) => $wars->serialize($war));

        $board = ClanRating::query()
            ->orderByDesc('rating')
            ->orderByDesc('wars_won')
            ->limit(20)
            ->get();

        return Inertia::render('Admin/ClanWars', [
            'wars' => $list,
            'board' => $board,
            'clubs' => Club::query()
                ->where(function ($q) {
                    $q->whereNull('type')->orWhereIn('type', ['club', 'both']);
                })
                ->orderBy('name')
                ->get(['id', 'name', 'slug']),
            'host_club_id' => $clubId,
        ]);
    }

    public function store(Request $request, ClanWarService $wars)
    {
        $data = $request->validate([
            'name' => 'nullable|string|max:120',
            'mode' => 'required|in:zone,location',
            'game' => 'nullable|in:cs2,dota,any',
            'duration_minutes' => 'nullable|integer|min:10|max:240',
            'side_a_club_id' => 'nullable|integer|exists:clubs,id',
            'side_b_club_id' => 'nullable|integer|exists:clubs,id',
        ]);

        try {
            $wars->create($data, AdminLocation::id(Auth::guard('admin')->user()));
        } catch (RuntimeException $e) {
            return back()->withErrors(['mode' => $e->getMessage()]);
        }

        return back();
    }

    public function updateStatus(Request $request, ClanWar $clanWar, ClanWarService $wars)
    {
        $data = $request->validate([
            'status' => 'required|in:live,finished,cancelled',
        ]);

        try {
            if ($data['status'] === 'live') {
                $wars->start($clanWar);
            } elseif ($data['status'] === 'finished') {
                $wars->finish($clanWar);
            } else {
                $wars->cancel($clanWar);
            }
        } catch (RuntimeException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back();
    }
}
