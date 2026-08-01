<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Overlay;
use App\Events\OverlayUpdated;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OverlayAdminController extends Controller
{
    public function index()
    {
        return Inertia::render('Admin/OverlayManager');
    }

    public function getOverlays()
    {
        return response()->json(
            Overlay::query()->orderBy('block_position')->orderBy('id')->get()
        );
    }

    // Сохраняет изменения из админки (PUT /api/overlays/{id})
    public function updateOverlay(Request $request, $id)
    {
        $overlay = Overlay::findOrFail($id);

        $validated = $request->validate([
            'title'     => 'required|string|max:255',
            'type'      => 'required|string',
            'content'   => 'required|array',
            'is_active' => 'required|boolean'
        ]);

        // Очистка абсолютных URL только у media-слоёв (video/image), не у text
        if (isset($validated['content']['layers']) && is_array($validated['content']['layers'])) {
            foreach ($validated['content']['layers'] as $key => $layer) {
                $type = $layer['type'] ?? '';
                if (! in_array($type, ['video', 'video_url', 'image'], true)) {
                    continue;
                }
                if (! isset($layer['value']) || ! is_string($layer['value'])) {
                    continue;
                }
                $urlPath = parse_url($layer['value'], PHP_URL_PATH);
                if ($urlPath) {
                    $validated['content']['layers'][$key]['value'] = ltrim($urlPath, '/');
                }
            }
        }

        // Теперь в базу пишется чистая структура без привязки к конкретному IP/домену
        $overlay->update($validated);

        broadcast(new \App\Events\OverlayUpdated($overlay));

        return response()->json([
            'status' => 'success',
            'data'   => $overlay->fresh()
        ]);
    }
    public function uploadImage(Request $request)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
        ]);

        if ($request->hasFile('image')) {
            $file = $request->file('image');
            // Сохраняем в public/storage/overlays
            $path = $file->store('overlays', 'public');

            // Относительный путь — Shell подставит api_ip из config.ini
            return response()->json([
                'url' => 'storage/'.$path
            ]);
        }

        return response()->json(['error' => 'No file'], 400);
    }
    public function uploadVideo(Request $request)
    {
        // ВАЖНО: Валидация файла (250 Мб = 256000 Кб)
        $request->validate([
            'video' => 'required|file|mimes:mp4,webm|max:256000',
        ]);

        try {
            if ($request->hasFile('video')) {
                // Сохраняем файл в папку public/videos
                $path = $request->file('video')->store('videos', 'public');

                // Относительный путь — Shell сам подставит api_ip из config.ini
                return response()->json([
                    'status' => 'success',
                    'url' => 'storage/'.$path
                ]);
            }

            return response()->json(['status' => 'error', 'message' => 'No file provided'], 400);

        } catch (\Exception $e) {
            \Log::error('Video upload failed: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Upload failed'], 500);
        }
    }
}
