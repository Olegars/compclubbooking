<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcBan;
use App\Models\User;
use App\Services\ReactorAc\AcGate;
use App\Support\AdminLocation;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FairPlayController extends Controller
{
    public function __construct(private readonly AcGate $gate)
    {
    }

    public function index()
    {
        $clubId = AdminLocation::id(auth('admin')->user());

        return Inertia::render('Admin/FairPlay', $this->gate->adminPayload($clubId));
    }

    public function ban(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'scope' => ['required', 'string', 'in:match_making,tournament,full_ban'],
            'reason' => ['nullable', 'string', 'max:255'],
            'days' => ['nullable', 'integer', 'min:0', 'max:3650'],
        ]);
        $user = User::query()->findOrFail($data['user_id']);
        $days = array_key_exists('days', $data) && $data['days'] !== null
            ? (int) $data['days']
            : (int) config('reactor_ac.ban_days_temp', 7);
        $this->gate->ban($user, $data['scope'], (string) ($data['reason'] ?? ''), $days, auth('admin')->id());

        return back()->with('success', 'Бан '.$data['scope'].' записан');
    }

    public function pardon(AcBan $ban)
    {
        $this->gate->pardon($ban);

        return back()->with('success', 'Бан снят');
    }
}
