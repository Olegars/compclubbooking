<?php

namespace App\Http\Controllers;

use App\Models\Computer;
use App\Services\ShellQrLoginService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ShellQrLoginController extends Controller
{
    public function redeem(Request $request, ShellQrLoginService $qr)
    {
        $data = $request->validate([
            'token' => 'required|uuid',
        ]);

        try {
            $result = $qr->redeem($request->user(), $data['token']);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => collect($e->errors())->flatten()->first() ?: 'Ошибка',
            ], 422);
        }

        return response()->json($result);
    }

    public function quote(Request $request, ShellQrLoginService $qr)
    {
        $data = $request->validate([
            'token' => 'required|uuid',
            'duration_minutes' => 'required|integer|min:60',
        ]);

        try {
            $result = $qr->previewQuote(
                $request->user(),
                $data['token'],
                (int) $data['duration_minutes']
            );
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => collect($e->errors())->flatten()->first() ?: 'Ошибка',
            ], 422);
        }

        return response()->json($result);
    }

    public function book(Request $request, ShellQrLoginService $qr)
    {
        $data = $request->validate([
            'token' => 'required|uuid',
            'duration_minutes' => 'required|integer|min:60',
        ]);

        try {
            $result = $qr->bookFromQr(
                $request->user(),
                $data['token'],
                (int) $data['duration_minutes']
            );
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => collect($e->errors())->flatten()->first() ?: 'Ошибка',
            ], 422);
        }

        return response()->json($result);
    }
}
