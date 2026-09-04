<?php

namespace App\Http\Controllers\Admin\Store;

use App\Jobs\GenerateStoreAvitoAdsJob;
use App\Models\StoreAvitoAd;
use App\Models\StoreAvitoChat;
use App\Models\StoreAvitoMessage;
use App\Models\StoreAvitoSetting;
use App\Services\StoreAvito\StoreAvitoMessengerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;

class AvitoController extends StoreController
{
    public function index(Request $request)
    {
        $settings = StoreAvitoSetting::current();
        $tab = $request->string('tab')->toString() ?: 'ads';
        $chatId = $request->string('chat')->toString();

        $chats = StoreAvitoChat::query()
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(80)
            ->get();

        $activeChat = $chatId !== ''
            ? $chats->firstWhere('chat_id', $chatId) ?? StoreAvitoChat::query()->where('chat_id', $chatId)->first()
            : $chats->first();

        if ($activeChat && $request->boolean('mark_read')) {
            $activeChat->update(['unread' => false]);
            StoreAvitoMessage::query()->where('chat_id', $activeChat->chat_id)->update(['read' => true]);
        }

        $messages = $activeChat
            ? StoreAvitoMessage::query()->where('chat_id', $activeChat->chat_id)->orderBy('id')->limit(200)->get()
            : collect();

        return Inertia::render('Admin/Store/Avito', [
            'tab' => in_array($tab, ['ads', 'chats', 'settings'], true) ? $tab : 'ads',
            'settings' => $this->settingsPayload($settings),
            'feed_url' => URL::to('/avito/'.$settings->feed_token.'/feed.xml'),
            'ads' => StoreAvitoAd::query()->orderByDesc('id')->limit(120)->get(),
            'chats' => $chats,
            'active_chat' => $activeChat,
            'messages' => $messages,
            'canManage' => $this->admin()->canManageStoreCatalog() || $this->admin()->role === 'owner',
            'unread' => StoreAvitoChat::query()->where('unread', true)->count(),
        ]);
    }

    public function updateSettings(Request $request)
    {
        abort_unless($this->admin()->canManageStoreCatalog() || $this->admin()->role === 'owner', 403);

        $request->merge([
            'avito_user_id' => $request->filled('avito_user_id') ? $request->input('avito_user_id') : null,
            'client_id' => $request->filled('client_id') ? $request->input('client_id') : null,
        ]);

        $data = $request->validate([
            'enabled' => 'sometimes|boolean',
            'ads_per_hour' => 'required|integer|min:1|max:50',
            'keep_active' => 'required|integer|min:20|max:2000',
            'address' => 'nullable|string|max:255',
            'contact_phone' => 'nullable|string|max:32',
            'manager_name' => 'nullable|string|max:80',
            'pc_type' => 'required|string|max:32',
            'markup_percent' => 'required|numeric|min:0|max:200',
            'extra_rub' => 'required|numeric|min:0|max:100000',
            'round_to' => 'required|integer|min:1|max:1000',
            'discount_over_60k_pct' => 'required|numeric|min:0|max:30',
            'discount_over_100k_pct' => 'required|numeric|min:0|max:30',
            'client_id' => 'nullable|string|max:128',
            'client_secret' => 'nullable|string|max:255',
            'avito_user_id' => 'nullable|integer|min:1',
            'auto_reply_enabled' => 'sometimes|boolean',
            'auto_reply_from' => 'required|integer|min:0|max:23',
            'auto_reply_to' => 'required|integer|min:0|max:23',
            'auto_reply_text' => 'nullable|string|max:2000',
        ]);

        $settings = StoreAvitoSetting::current();
        if (! filled($data['client_secret'] ?? null)) {
            unset($data['client_secret']);
        }
        $data['enabled'] = $request->boolean('enabled');
        $data['auto_reply_enabled'] = $request->boolean('auto_reply_enabled');
        $settings->fill($data)->save();

        return back()->with('success', 'Настройки Avito сохранены.');
    }

    public function generate(Request $request)
    {
        abort_unless($this->admin()->canManageStoreCatalog() || $this->admin()->role === 'owner', 403);

        $count = (int) $request->input('count', StoreAvitoSetting::current()->ads_per_hour);
        GenerateStoreAvitoAdsJob::dispatch(max(1, min(50, $count)), true);

        return back()->with('success', 'Генерация объявлений поставлена в очередь.');
    }

    public function updateAd(Request $request, StoreAvitoAd $storeAvitoAd)
    {
        abort_unless($this->admin()->canManageStoreCatalog() || $this->admin()->role === 'owner', 403);

        $data = $request->validate([
            'status' => 'required|in:active,archived,blocked',
        ]);
        $storeAvitoAd->update($data);

        return back()->with('success', 'Объявление обновлено.');
    }

    public function markChat(Request $request, StoreAvitoChat $storeAvitoChat)
    {
        abort_unless($this->admin()->canManageStoreCatalog() || $this->admin()->role === 'owner', 403);

        $data = $request->validate([
            'important' => 'sometimes|boolean',
            'unread' => 'sometimes|boolean',
        ]);
        $storeAvitoChat->update($data);
        if (array_key_exists('unread', $data) && ! $data['unread']) {
            StoreAvitoMessage::query()->where('chat_id', $storeAvitoChat->chat_id)->update(['read' => true]);
        }

        return back();
    }

    public function sendMessage(Request $request, StoreAvitoMessengerService $messenger)
    {
        abort_unless($this->admin()->canManageStoreCatalog() || $this->admin()->role === 'owner', 403);

        $data = $request->validate([
            'chat_id' => 'required|string|max:128',
            'text' => 'required|string|max:2000',
        ]);
        abort_unless(StoreAvitoChat::query()->where('chat_id', $data['chat_id'])->exists(), 404);

        $ok = $messenger->sendText($data['chat_id'], $data['text']);
        abort_unless($ok, 502, 'Не удалось отправить сообщение в Avito.');

        return back()->with('success', 'Сообщение отправлено.');
    }

    public function sendBom(Request $request, StoreAvitoMessengerService $messenger)
    {
        abort_unless($this->admin()->canManageStoreCatalog() || $this->admin()->role === 'owner', 403);

        $data = $request->validate([
            'chat_id' => 'required|string|max:128',
            'config_id' => 'required|string|max:16',
        ]);
        $text = $messenger->bomReply(strtoupper($data['config_id']));
        abort_unless($text, 404, 'Конфигурация не найдена.');
        $messenger->sendText($data['chat_id'], $text);

        return back()->with('success', 'Комплектация отправлена.');
    }

    public function connectWebhook(Request $request, StoreAvitoMessengerService $messenger)
    {
        abort_unless($this->admin()->canManageStoreCatalog() || $this->admin()->role === 'owner', 403);

        $url = URL::to('/api/store/avito/webhook');
        $messenger->registerWebhook($url);

        return back()->with('success', 'Webhook Avito зарегистрирован: '.$url);
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsPayload(StoreAvitoSetting $settings): array
    {
        return [
            'enabled' => $settings->enabled,
            'ads_per_hour' => $settings->ads_per_hour,
            'keep_active' => $settings->keep_active,
            'address' => $settings->address,
            'contact_phone' => $settings->contact_phone,
            'manager_name' => $settings->manager_name,
            'pc_type' => $settings->pc_type,
            'markup_percent' => (float) $settings->markup_percent,
            'extra_rub' => (float) $settings->extra_rub,
            'round_to' => $settings->round_to,
            'discount_over_60k_pct' => (float) $settings->discount_over_60k_pct,
            'discount_over_100k_pct' => (float) $settings->discount_over_100k_pct,
            'client_id' => $settings->client_id,
            'has_client_secret' => filled($settings->client_secret),
            'has_access_token' => filled($settings->access_token),
            'avito_user_id' => $settings->avito_user_id,
            'auto_reply_enabled' => $settings->auto_reply_enabled,
            'auto_reply_from' => $settings->auto_reply_from,
            'auto_reply_to' => $settings->auto_reply_to,
            'auto_reply_text' => $settings->auto_reply_text,
            'last_generated_at' => $settings->last_generated_at?->toIso8601String(),
            'last_generate_result' => $settings->last_generate_result,
            'last_error' => $settings->last_error,
        ];
    }
}
