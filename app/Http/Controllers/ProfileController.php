<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class ProfileController extends Controller
{
    public function dashboard()
    {
        $user = Auth::user();

        // Получаем кошелек или создаем его, если вдруг он не создался при реге
        $wallet = $user->wallet()->firstOrCreate(['user_id' => $user->id], ['balance' => 0]);

        // Берем 5 последних транзакций
        $transactions = $user->transactions()
            ->latest()
            ->take(5)
            ->get()
            ->map(function($t) {
                return [
                    'id' => $t->id,
                    'amount' => (float)$t->amount,
                    'type' => $t->type,
                    'description' => $t->description,
                    'date' => $t->created_at->format('d.m / H:i'),
                ];
            });

        return Inertia::render('User/Dashboard', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
            ],
            'gizmo' => [
                'balance' => (float)$wallet->balance,
                'spent_total' => (float)abs($user->transactions()->where('amount', '<', 0)->sum('amount')),
            ],
            'transactions' => $transactions // ПЕРЕДАЕМ ИСТОРИЮ
        ]);
    }

    // ... остальные методы (edit, update) остаются как были
}
