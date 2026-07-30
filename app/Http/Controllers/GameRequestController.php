<?php

namespace App\Http\Controllers;

use App\Models\GameRequest;
use App\Services\GameRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GameRequestController extends Controller
{
    public function mine(Request $request): JsonResponse
    {
        $rows = GameRequest::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->limit(20)
            ->get(['id', 'title', 'comment', 'status', 'created_at']);

        return response()->json([
            'requests' => $rows->map(fn (GameRequest $r) => [
                'id' => $r->id,
                'title' => $r->title,
                'comment' => $r->comment,
                'status' => $r->status,
                'created_at' => $r->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function store(Request $request, GameRequestService $service): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:120',
            'comment' => 'nullable|string|max:500',
        ]);

        $row = $service->create(
            $request->user(),
            $data['title'],
            $data['comment'] ?? null,
            GameRequest::SOURCE_CABINET
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Заявка принята. Если наберётся спрос — поставим на диски.',
            'request' => [
                'id' => $row->id,
                'title' => $row->title,
                'status' => $row->status,
            ],
        ], 201);
    }
}
