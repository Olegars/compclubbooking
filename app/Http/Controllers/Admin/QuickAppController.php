<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\QuickApp;
use Illuminate\Http\Request;
use Inertia\Inertia;

class QuickAppController extends Controller
{
    public function index()
    {
        return Inertia::render('Admin/QuickApps', [
            'apps' => QuickApp::query()
                ->orderBy('sort_order')
                ->orderBy('title')
                ->get(['id', 'title', 'exe_path', 'launch_args', 'sort_order', 'is_enabled']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'id' => 'nullable|integer|exists:quick_apps,id',
            'title' => 'required|string|max:120',
            'exe_path' => 'required|string|max:500',
            'launch_args' => 'nullable|string|max:500',
            'sort_order' => 'nullable|integer|min:0|max:9999',
            'is_enabled' => 'nullable|boolean',
        ]);

        $app = !empty($validated['id'])
            ? QuickApp::findOrFail($validated['id'])
            : new QuickApp();

        $app->title = $validated['title'];
        $app->exe_path = $validated['exe_path'];
        $app->launch_args = $validated['launch_args'] ?? '';
        $app->sort_order = (int) ($validated['sort_order'] ?? 0);
        $app->is_enabled = array_key_exists('is_enabled', $validated)
            ? (bool) $validated['is_enabled']
            : true;
        $app->save();

        return back();
    }

    public function destroy(QuickApp $quickApp)
    {
        $quickApp->delete();

        return back();
    }
}
