<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FaceitIdentity;
use App\Models\FaceitMatch;
use App\Models\Tournament;
use App\Services\ClubFeatureService;
use App\Services\Faceit\FaceitClient;
use App\Services\Faceit\FaceitIdentityService;
use App\Support\AdminLocation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FaceitAdminController extends Controller
{
    public function index(ClubFeatureService $features, FaceitIdentityService $faceit, FaceitClient $client): Response
    {
        $clubId = AdminLocation::id(auth('admin')->user());
        $mode = $faceit->mode($clubId);
        $limited = FaceitIdentity::query()->whereNotNull('rate_limited_at')->orderByDesc('rate_limited_at')->first();
        $championships = [];
        if ($client->configured()) {
            foreach (Tournament::query()->whereNotNull('faceit_championship_id')->orderByDesc('id')->limit(8)->get() as $row) {
                try {
                    $championships[] = [
                        'tournament_id' => $row->id,
                        'name' => $row->name,
                        'championship_id' => $row->faceit_championship_id,
                        'results' => $client->championshipResults((string) $row->faceit_championship_id),
                    ];
                } catch (\Throwable $e) {
                    $championships[] = [
                        'tournament_id' => $row->id,
                        'name' => $row->name,
                        'championship_id' => $row->faceit_championship_id,
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }

        return Inertia::render('Admin/Faceit', [
            'mode' => $mode,
            'enabled' => $features->enabled($clubId, 'faceit'),
            'api_key_set' => $client->configured(),
            'oauth_set' => $client->oauthConfigured(),
            'webhook_set' => trim((string) config('services.faceit.webhook_secret')) !== '',
            'hub_id' => $faceit->hubId($clubId),
            'hub_url' => $mode === 'hub' ? $faceit->hubUrl($clubId) : null,
            'identities' => FaceitIdentity::query()->count(),
            'rate_limited_at' => $limited?->rate_limited_at?->toIso8601String(),
            'matches' => FaceitMatch::query()->orderByDesc('id')->limit(20)->get(['match_id', 'status', 'map', 'finished_at']),
            'tournaments' => Tournament::query()->orderByDesc('id')->limit(30)->get(['id', 'name', 'faceit_championship_id']),
            'championships' => $championships,
        ]);
    }

    public function championship(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tournament_id' => ['required', 'integer', 'exists:tournaments,id'],
            'championship_id' => ['nullable', 'string', 'max:64'],
        ]);
        $id = trim((string) ($data['championship_id'] ?? ''));
        Tournament::query()->whereKey($data['tournament_id'])->update([
            'faceit_championship_id' => $id !== '' ? $id : null,
        ]);

        return back()->with('success', $id !== '' ? 'Чемпионат FACEIT привязан, призы клуба не платим' : 'Чемпионат FACEIT снят');
    }
}
