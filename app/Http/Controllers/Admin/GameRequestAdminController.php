<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GameRequest;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GameRequestAdminController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->query('status', 'open');
        if (! in_array($status, ['open', 'done', 'rejected', 'all'], true)) {
            $status = 'open';
        }

        $topQuery = GameRequest::query()
            ->selectRaw('title_normalized')
            ->selectRaw('MAX(title) as title')
            ->selectRaw('COUNT(*) as requests_count')
            ->selectRaw('COUNT(DISTINCT user_id) as users_count')
            ->selectRaw('MAX(created_at) as last_requested_at')
            ->selectRaw("SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_count")
            ->groupBy('title_normalized')
            ->orderByDesc('users_count')
            ->orderByDesc('requests_count')
            ->limit(50);

        if ($status !== 'all') {
            $topQuery->where('status', $status);
        }

        $top = $topQuery->get()->map(fn ($row) => [
            'title' => $row->title,
            'title_normalized' => $row->title_normalized,
            'requests_count' => (int) $row->requests_count,
            'users_count' => (int) $row->users_count,
            'open_count' => (int) $row->open_count,
            'last_requested_at' => $row->last_requested_at,
        ]);

        $recentQuery = GameRequest::query()
            ->with(['user:id,name,phone'])
            ->orderByDesc('id')
            ->limit(100);

        if ($status !== 'all') {
            $recentQuery->where('status', $status);
        }

        $recent = $recentQuery->get()->map(fn (GameRequest $r) => [
            'id' => $r->id,
            'title' => $r->title,
            'comment' => $r->comment,
            'source' => $r->source,
            'status' => $r->status,
            'created_at' => $r->created_at?->toIso8601String(),
            'user' => [
                'id' => $r->user?->id,
                'name' => $r->user?->name,
                'phone' => $r->user?->phone,
            ],
        ]);

        return Inertia::render('Admin/GameRequests', [
            'top' => $top,
            'recent' => $recent,
            'filter_status' => $status,
            'stats' => [
                'open' => GameRequest::where('status', GameRequest::STATUS_OPEN)->count(),
                'done' => GameRequest::where('status', GameRequest::STATUS_DONE)->count(),
                'rejected' => GameRequest::where('status', GameRequest::STATUS_REJECTED)->count(),
            ],
        ]);
    }

    public function updateStatus(Request $request, GameRequest $gameRequest)
    {
        $data = $request->validate([
            'status' => 'required|in:open,done,rejected',
        ]);

        $gameRequest->update(['status' => $data['status']]);

        return back();
    }

    /** Mark all open requests with the same normalized title. */
    public function bulkStatus(Request $request)
    {
        $data = $request->validate([
            'title_normalized' => 'required|string|max:191',
            'status' => 'required|in:open,done,rejected',
        ]);

        GameRequest::query()
            ->where('title_normalized', $data['title_normalized'])
            ->where('status', GameRequest::STATUS_OPEN)
            ->update(['status' => $data['status']]);

        return back();
    }
}
