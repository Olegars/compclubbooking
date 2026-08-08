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
            'clubs' => Club::query()->select('id', 'name')->orderBy('name')->get(),
            'env_enabled' => (bool) config('ai_assistant.enabled'),
            'keys_configured' => filled(config('ai_assistant.deepseek.api_key'))
                && filled(config('ai_assistant.openai.api_key')),
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
            'tts_voice' => 'required|string|in:'.implode(',', array_keys(AiAssistantSetting::VOICES)),
            'companion_prompt' => 'required|string|max:20000',
            'greeting_prompt' => 'required|string|max:20000',
        ], [
            'tts_voice.in' => 'Выберите голос из списка',
            'companion_prompt.required' => 'Укажите промпт F1-компаньона',
            'greeting_prompt.required' => 'Укажите промпт приветствия',
        ]);

        $clubId = (int) ($data['club_id'] ?? Club::query()->value('id'));
        $row = AiAssistantSetting::forClub($clubId);

        $companion = trim($data['companion_prompt']);
        $greeting = trim($data['greeting_prompt']);

        $row->update([
            'is_enabled' => (bool) $data['is_enabled'],
            'tts_voice' => $data['tts_voice'],
            'companion_prompt' => $companion === AiAssistantSetting::defaultCompanionPromptTemplate()
                ? null
                : $companion,
            'greeting_prompt' => $greeting === AiAssistantSetting::defaultGreetingPromptTemplate()
                ? null
                : $greeting,
        ]);

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
