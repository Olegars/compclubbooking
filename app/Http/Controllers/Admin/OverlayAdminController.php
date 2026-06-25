<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Overlay;
use App\Events\OverlayUpdated;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OverlayAdminController extends Controller
{
    // ... методы index(), getOverlays() оставляем без изменений ...

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

        // ГЛУБОКАЯ ОЧИСТКА ССЫЛОК ПЕРЕД ЗАПИСЬЮ В БАЗУ
        // Проходим по каждому слою внутри структуры 'layers'
        if (isset($validated['content']['layers']) && is_array($validated['content']['layers'])) {
            foreach ($validated['content']['layers'] as $key => $layer) {
                if (isset($layer['value'])) {
                    $urlPath = parse_url($layer['value'], PHP_URL_PATH);

                    // Если это была полная ссылка, parse_url вернет строго "/storage/videos/..."
                    // Если это уже был относительный путь, он останется нетронутым
                    if ($urlPath) {
                        // Убираем ведущий слэш для единообразия, чтобы получилось "storage/videos/..."
                        $validated['content']['layers'][$key]['value'] = ltrim($urlPath, '/');
                    }
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

            // Возвращаем полный URL
            return response()->json([
                'url' => asset('storage/' . $path)
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

                // Возвращаем полный URL к файлу для QML-шелла
                return response()->json([
                    'status' => 'success',
                    'url' => asset('storage/' . $path)
                ]);
            }

            return response()->json(['status' => 'error', 'message' => 'No file provided'], 400);

        } catch (\Exception $e) {
            \Log::error('Video upload failed: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Upload failed'], 500);
        }
    }
}
