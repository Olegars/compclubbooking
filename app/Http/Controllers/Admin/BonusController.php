<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ReviewClaim;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BonusController extends Controller
{
    // Метод для ИГРОКА: отправить текст на проверку
    // app/Http/Controllers/Admin/BonusController.php

    public function submitReview(Request $request)
    {
        // Требуем валидный URL
        $request->validate([
            'link' => 'required|url|max:500',
        ]);

        // Проверяем, что это именно Яндекс
        if (!str_contains(strtolower($request->link), 'yandex.ru') && !str_contains(strtolower($request->link), 'yandex.com')) {
            return response()->json(['message' => 'Принимаются только ссылки с Яндекс.Карт.'], 422);
        }

        // Проверяем, нет ли уже активной заявки на проверке
        $hasPending = ReviewClaim::where('user_id', auth()->id())
            ->where('status', 'pending')
            ->exists();

        if ($hasPending) {
            return response()->json(['message' => 'У вас уже есть заявка на проверке.'], 422);
        }

        // Проверяем, не использовал ли кто-то (или этот же юзер) эту ссылку ранее
        $isUsedLink = ReviewClaim::where('review_link', $request->link)->exists();

        if ($isUsedLink) {
            return response()->json(['message' => 'Этот отзыв уже был оплачен системой.'], 422);
        }

        // Создаем заявку
        ReviewClaim::create([
            'user_id' => auth()->id(),
            'review_link' => $request->link,
            'bonus_amount' => 100, // Платим только за 5 звезд
            'status' => 'pending'
        ]);

        return response()->json(['message' => 'Review claim submitted']);
    }

    // Метод для АДМИНА: список заявок
    public function index()
    {
        $claims = ReviewClaim::with('user:id,name,phone')
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('Admin/Bonuses', [ // Убедись, что путь к Vue файлу верный
            'claims' => $claims
        ]);
    }

    // Метод для АДМИНА: подтверждение/отклонение
    public function verify(Request $request, $id)
    {
        $claim = ReviewClaim::findOrFail($id);

        if ($request->status === 'approved') {
            $claim->user->increment('balance', $claim->bonus_amount);
            $claim->update(['status' => 'approved', 'verified_at' => now()]);
        } else {
            $claim->update(['status' => 'rejected']);
        }

        return back();
    }

}
