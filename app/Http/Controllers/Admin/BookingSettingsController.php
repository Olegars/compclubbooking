<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClubBookingSetting;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BookingSettingsController extends Controller
{
    public function index()
    {
        $settings = ClubBookingSetting::current();
        $minutes = $settings->cancelBeforeMinutes();

        return Inertia::render('Admin/BookingSettings', [
            'settings' => [
                'cancel_before_minutes' => $minutes,
                'cancel_before_hours' => round($minutes / 60, 2),
            ],
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'cancel_before_hours' => 'required|numeric|min:0|max:168',
        ], [
            'cancel_before_hours.required' => 'Укажите срок отмены',
            'cancel_before_hours.min' => 'Срок не может быть отрицательным',
            'cancel_before_hours.max' => 'Максимум 168 часов (неделя)',
        ]);

        $minutes = (int) round(((float) $data['cancel_before_hours']) * 60);

        $settings = ClubBookingSetting::current();
        $settings->update([
            'cancel_before_minutes' => max(0, $minutes),
        ]);

        return back()->with('success', 'Правила отмены брони сохранены');
    }
}
