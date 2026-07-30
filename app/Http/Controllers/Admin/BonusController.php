<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MapReview;
use App\Models\ReviewClaim;
use App\Services\ReviewBonusService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BonusController extends Controller
{
    public function submitReview(Request $request, ReviewBonusService $service)
    {
        $minLen = $service->minTextLength();

        $request->validate([
            'text' => "required|string|min:{$minLen}|max:5000",
        ], [
            'text.required' => 'Вставьте текст отзыва',
            'text.min' => "Текст отзыва слишком короткий (минимум {$minLen} символов)",
        ]);

        try {
            $claim = $service->submitClaim((int) auth()->id(), (string) $request->input('text'));
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Заявка принята. Проверим публикацию отзыва в течение суток.',
            'claim' => $claim,
        ]);
    }

    public function index(ReviewBonusService $service)
    {
        $settings = $service->settings();

        $claims = ReviewClaim::with('user:id,name,phone')
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at')
            ->get();

        $mapReviews = MapReview::with('rewardedUser:id,name,phone')
            ->orderByDesc('reviewed_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return Inertia::render('Admin/Bonuses', [
            'claims' => $claims,
            'map_reviews' => $mapReviews,
            'settings' => [
                'bonus_amount' => (float) $settings->bonus_amount,
                'site_reviews_limit' => (int) $settings->site_reviews_limit,
                'show_on_site' => (bool) $settings->show_on_site,
            ],
            'review_meta' => $service->clientMeta(),
        ]);
    }

    public function updateSettings(Request $request, ReviewBonusService $service)
    {
        $data = $request->validate([
            'bonus_amount' => 'required|numeric|min:0|max:100000',
            'site_reviews_limit' => 'nullable|integer|min:1|max:24',
            'show_on_site' => 'nullable|boolean',
        ]);

        $service->updateBonusAmount((float) $data['bonus_amount']);
        $service->updateSiteSettings(
            isset($data['site_reviews_limit']) ? (int) $data['site_reviews_limit'] : null,
            array_key_exists('show_on_site', $data) ? (bool) $data['show_on_site'] : null,
        );

        return back()->with('success', 'Настройки бонуса сохранены');
    }

    public function sync(ReviewBonusService $service)
    {
        $result = $service->processPendingClaims();

        return back()->with('success', sprintf(
            'Синхронизация: отзывов %d, совпадений %d',
            $result['fetched'],
            $result['matched']
        ));
    }

    public function verify(Request $request, $id, ReviewBonusService $service)
    {
        $request->validate([
            'status' => 'required|in:approved,rejected',
        ]);

        $claim = ReviewClaim::findOrFail($id);

        if ($claim->status !== ReviewClaim::STATUS_PENDING) {
            return back()->withErrors(['status' => 'Заявка уже обработана']);
        }

        $service->verifyManually($claim, $request->status);

        return back();
    }
}
