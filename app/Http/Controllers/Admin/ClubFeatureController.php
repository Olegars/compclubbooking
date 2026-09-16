<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ClubFeatureService;
use App\Support\AdminLocation;
use App\Support\ClubFeatureCatalog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use RuntimeException;

class ClubFeatureController extends Controller
{
    public function __construct(
        private readonly ClubFeatureService $features,
    ) {
    }

    public function index()
    {
        $clubId = AdminLocation::id(auth('admin')->user());

        return Inertia::render('Admin/ClubFeatures', [
            'club_id' => $clubId,
            'features' => $this->features->adminPayload($clubId),
        ]);
    }

    public function update(Request $request, string $key)
    {
        if (! ClubFeatureCatalog::get($key)) {
            abort(404);
        }

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'settings' => ['nullable', 'array'],
            'settings.*' => ['nullable'],
        ]);

        $clubId = AdminLocation::id(auth('admin')->user());

        try {
            $this->features->save(
                $clubId,
                $key,
                (bool) $data['enabled'],
                array_key_exists('settings', $data) ? ($data['settings'] ?? []) : null,
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['message' => $e->getMessage()]);
        }

        $label = ClubFeatureCatalog::get($key)['title'] ?? $key;
        $savedSettings = array_key_exists('settings', $data);

        $message = ! $data['enabled']
            ? 'Фича «'.$label.'» выключена'
            : ($savedSettings ? 'Настройки «'.$label.'» сохранены' : 'Фича «'.$label.'» включена');

        return back()->with('success', $message);
    }
}
