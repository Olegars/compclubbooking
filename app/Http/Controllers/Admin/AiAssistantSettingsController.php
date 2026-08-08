<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiAssistantSetting;
use App\Models\Club;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AiAssistantSettingsController extends Controller
{
    public function index(Request $request)
    {
        $clubId = (int) ($request->input('club_id') ?: Club::query()->value('id'));
        $settings = AiAssistantSetting::forClub($clubId);

        return Inertia::render('Admin/AiAssistant', [
            'settings' => $settings->toAdminArray(),
            'voices' => AiAssistantSetting::VOICES,
            'llmProviders' => AiAssistantSetting::LLM_PROVIDERS,
            'llmPresets' => AiAssistantSetting::LLM_PRESETS,
            'clubs' => Club::query()->select('id', 'name')->orderBy('name')->get(),
            'env_enabled' => (bool) config('ai_assistant.enabled'),
            'placeholders' => [
                'companion' => ['{{club}}', '{{player}}', '{{game}}', '{{max_chars}}'],
                'greeting' => ['{{club}}', '{{player}}', '{{pc}}', '{{time}}', '{{visit_line}}', '{{games}}', '{{max_chars}}'],
            ],
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'club_id' => 'nullable|integer|exists:clubs,id',
            'is_enabled' => 'required|boolean',
            'llm_provider' => 'required|string|in:'.implode(',', array_keys(AiAssistantSetting::LLM_PROVIDERS)),
            'llm_api_key' => 'nullable|string|max:2000',
            'clear_llm_api_key' => 'nullable|boolean',
            'llm_base_url' => 'nullable|string|max:512',
            'llm_model' => 'nullable|string|max:128',
            'openai_api_key' => 'nullable|string|max:2000',
            'clear_openai_api_key' => 'nullable|boolean',
            'openai_base_url' => 'nullable|string|max:512',
            'stt_model' => 'nullable|string|max:64',
            'tts_model' => 'nullable|string|max:64',
            'tts_voice' => 'required|string|in:'.implode(',', array_keys(AiAssistantSetting::VOICES)),
            'max_reply_chars' => 'required|integer|min:80|max:2000',
            'companion_prompt' => 'required|string|max:20000',
            'greeting_prompt' => 'required|string|max:20000',
        ], [
            'tts_voice.in' => 'Выберите голос из списка',
            'llm_provider.in' => 'Выберите провайдера LLM',
            'companion_prompt.required' => 'Укажите промпт F1-компаньона',
            'greeting_prompt.required' => 'Укажите промпт приветствия',
        ]);

        $clubId = (int) ($data['club_id'] ?? Club::query()->value('id'));
        $row = AiAssistantSetting::forClub($clubId);

        $companion = trim($data['companion_prompt']);
        $greeting = trim($data['greeting_prompt']);

        $payload = [
            'is_enabled' => (bool) $data['is_enabled'],
            'llm_provider' => $data['llm_provider'],
            'llm_base_url' => filled($data['llm_base_url'] ?? null) ? rtrim(trim($data['llm_base_url']), '/') : null,
            'llm_model' => filled($data['llm_model'] ?? null) ? trim($data['llm_model']) : null,
            'openai_base_url' => filled($data['openai_base_url'] ?? null) ? rtrim(trim($data['openai_base_url']), '/') : null,
            'stt_model' => filled($data['stt_model'] ?? null) ? trim($data['stt_model']) : null,
            'tts_model' => filled($data['tts_model'] ?? null) ? trim($data['tts_model']) : null,
            'tts_voice' => $data['tts_voice'],
            'max_reply_chars' => (int) $data['max_reply_chars'],
            'companion_prompt' => $companion === AiAssistantSetting::defaultCompanionPromptTemplate()
                ? null
                : $companion,
            'greeting_prompt' => $greeting === AiAssistantSetting::defaultGreetingPromptTemplate()
                ? null
                : $greeting,
        ];

        if (! empty($data['clear_llm_api_key'])) {
            $payload['llm_api_key'] = null;
        } elseif (array_key_exists('llm_api_key', $data) && filled($data['llm_api_key'])) {
            $payload['llm_api_key'] = trim($data['llm_api_key']);
        }

        if (! empty($data['clear_openai_api_key'])) {
            $payload['openai_api_key'] = null;
        } elseif (array_key_exists('openai_api_key', $data) && filled($data['openai_api_key'])) {
            $payload['openai_api_key'] = trim($data['openai_api_key']);
        }

        $row->update($payload);

        return back()->with('success', 'Настройки ИИ сохранены');
    }

    public function resetPrompts(Request $request)
    {
        $data = $request->validate([
            'club_id' => 'nullable|integer|exists:clubs,id',
        ]);

        $clubId = (int) ($data['club_id'] ?? Club::query()->value('id'));
        $row = AiAssistantSetting::forClub($clubId);
        $row->update([
            'companion_prompt' => null,
            'greeting_prompt' => null,
        ]);

        return back()->with('success', 'Промпты сброшены к значениям по умолчанию');
    }
}
