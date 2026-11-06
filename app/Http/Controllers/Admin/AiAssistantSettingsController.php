<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiAssistantSetting;
use App\Models\Club;
use App\Services\AiAssistant\AlibabaBalanceClient;
use App\Services\AiAssistant\DeepSeekChat;
use App\Services\AiAssistant\SpeechService;
use App\Services\AiAssistant\YandexBillingBalanceClient;
use App\Services\StoreCaseCatalogEnrichmentService;
use App\Support\AdminLocation;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AiAssistantSettingsController extends Controller
{
    public function index(Request $request)
    {
        $clubId = (int) ($request->input('club_id') ?: Club::query()->value('id'));
        $settings = AiAssistantSetting::forClub($clubId);

        return Inertia::render('Admin/AiAssistant', [
            'settings' => $settings->toAdminArray(),
            'voices' => AiAssistantSetting::voicesFor($settings->resolvedSpeechProvider()),
            'openaiVoices' => AiAssistantSetting::OPENAI_VOICES,
            'yandexVoices' => AiAssistantSetting::YANDEX_VOICES,
            'llmProviders' => AiAssistantSetting::LLM_PROVIDERS,
            'speechProviders' => AiAssistantSetting::SPEECH_PROVIDERS,
            'voiceStacks' => AiAssistantSetting::VOICE_STACKS,
            'qwenVoices' => AiAssistantSetting::QWEN_VOICES,
            'llmPresets' => AiAssistantSetting::LLM_PRESETS,
            'clubs' => Club::visibleToAdmin(AdminLocation::id())->select('id', 'name')->orderBy('name')->get(),
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
            'speech_provider' => 'required|string|in:'.implode(',', array_keys(AiAssistantSetting::SPEECH_PROVIDERS)),
            'voice_stack' => 'required|string|in:'.implode(',', array_keys(AiAssistantSetting::VOICE_STACKS)),
            'qwen_api_key' => 'nullable|string|max:2000',
            'clear_qwen_api_key' => 'nullable|boolean',
            'qwen_tts_voice' => [
                'nullable',
                'string',
                Rule::requiredIf(fn () => $request->input('voice_stack') === 'qwen'),
                Rule::in(array_keys(AiAssistantSetting::QWEN_VOICES)),
            ],
            'yandex_api_key' => 'nullable|string|max:2000',
            'clear_yandex_api_key' => 'nullable|boolean',
            'yandex_folder_id' => 'nullable|string|max:64',
            'openai_api_key' => 'nullable|string|max:2000',
            'clear_openai_api_key' => 'nullable|boolean',
            'openai_base_url' => 'nullable|string|max:512',
            'stt_model' => 'nullable|string|max:64',
            'tts_model' => 'nullable|string|max:64',
            'tts_voice' => [
                'required',
                'string',
                Rule::in(array_keys(AiAssistantSetting::voicesFor((string) $request->input('speech_provider', 'yandex')))),
            ],
            'max_reply_chars' => 'required|integer|min:80|max:2000',
            'companion_prompt' => 'required|string|max:20000',
            'greeting_prompt' => 'required|string|max:20000',
            'alibaba_access_key_id' => 'nullable|string|max:2000',
            'alibaba_access_key_secret' => 'nullable|string|max:2000',
            'clear_alibaba_billing' => 'nullable|boolean',
            'yandex_billing_iam_token' => 'nullable|string|max:4000',
            'clear_yandex_billing_iam_token' => 'nullable|boolean',
            'yandex_billing_account_id' => 'nullable|string|max:64',
        ], [
            'tts_voice.in' => 'Выберите голос из списка',
            'llm_provider.in' => 'Выберите провайдера LLM',
            'voice_stack.in' => 'Выберите стек вопроса',
            'qwen_tts_voice.required' => 'Выберите голос Qwen',
            'qwen_tts_voice.in' => 'Выберите голос Qwen из списка',
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
            'speech_provider' => $data['voice_stack'] === 'qwen' ? $data['speech_provider'] : 'yandex',
            'voice_stack' => $data['voice_stack'],
            'yandex_folder_id' => filled($data['yandex_folder_id'] ?? null) ? trim($data['yandex_folder_id']) : null,
            'llm_base_url' => filled($data['llm_base_url'] ?? null)
                ? AiAssistantSetting::normalizeLlmBaseUrl((string) $data['llm_base_url']) ?: null
                : null,
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
            'yandex_billing_account_id' => filled($data['yandex_billing_account_id'] ?? null)
                ? trim((string) $data['yandex_billing_account_id'])
                : null,
        ];

        if (filled($data['qwen_tts_voice'] ?? null)) {
            $qwenVoice = AiAssistantSetting::sanitizeTtsVoice('qwen', (string) $data['qwen_tts_voice']);
            if ($qwenVoice !== null) {
                $payload['qwen_tts_voice'] = $qwenVoice;
            }
        }

        if (! empty($data['clear_llm_api_key'])) {
            $payload['llm_api_key'] = null;
        } elseif (array_key_exists('llm_api_key', $data) && filled($data['llm_api_key'])) {
            $payload['llm_api_key'] = trim($data['llm_api_key']);
        }

        if (! empty($data['clear_yandex_api_key'])) {
            $payload['yandex_api_key'] = null;
        } elseif (array_key_exists('yandex_api_key', $data) && filled($data['yandex_api_key'])) {
            $payload['yandex_api_key'] = trim($data['yandex_api_key']);
        }

        if (! empty($data['clear_qwen_api_key'])) {
            $payload['qwen_api_key'] = null;
        } elseif (array_key_exists('qwen_api_key', $data) && filled($data['qwen_api_key'])) {
            $payload['qwen_api_key'] = trim($data['qwen_api_key']);
        }

        if (! empty($data['clear_openai_api_key'])) {
            $payload['openai_api_key'] = null;
        } elseif (array_key_exists('openai_api_key', $data) && filled($data['openai_api_key'])) {
            $payload['openai_api_key'] = trim($data['openai_api_key']);
        }

        if (! empty($data['clear_alibaba_billing'])) {
            $payload['alibaba_access_key_id'] = null;
            $payload['alibaba_access_key_secret'] = null;
        } else {
            if (array_key_exists('alibaba_access_key_id', $data) && filled($data['alibaba_access_key_id'])) {
                $payload['alibaba_access_key_id'] = trim($data['alibaba_access_key_id']);
            }
            if (array_key_exists('alibaba_access_key_secret', $data) && filled($data['alibaba_access_key_secret'])) {
                $payload['alibaba_access_key_secret'] = trim($data['alibaba_access_key_secret']);
            }
        }

        if (! empty($data['clear_yandex_billing_iam_token'])) {
            $payload['yandex_billing_iam_token'] = null;
        } elseif (array_key_exists('yandex_billing_iam_token', $data) && filled($data['yandex_billing_iam_token'])) {
            $payload['yandex_billing_iam_token'] = trim($data['yandex_billing_iam_token']);
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

    public function balance(
        Request $request,
        DeepSeekChat $llm,
        AlibabaBalanceClient $alibaba,
        YandexBillingBalanceClient $yandex,
    ) {
        $data = $request->validate([
            'club_id' => 'nullable|integer|exists:clubs,id',
        ]);
        $clubId = (int) ($data['club_id'] ?? Club::query()->value('id'));

        return response()->json([
            'deepseek' => $llm->balance($clubId),
            'qwen' => $alibaba->balance($clubId),
            'yandex' => $yandex->balance($clubId),
        ]);
    }

    public function testLlm(Request $request, DeepSeekChat $llm, StoreCaseCatalogEnrichmentService $cases)
    {
        $data = $request->validate([
            'club_id' => 'nullable|integer|exists:clubs,id',
            'case_name' => 'nullable|string|max:500',
            'case_part' => 'nullable|string|max:200',
        ]);

        $clubId = (int) ($data['club_id'] ?? Club::query()->value('id'));
        $caseName = trim((string) ($data['case_name'] ?? ''));

        try {
            if ($caseName !== '') {
                $result = $cases->classifyProbe($caseName, (string) ($data['case_part'] ?? ''));
                $result['provider'] = AiAssistantSetting::forClub($clubId)->resolvedLlmProvider();

                return response()->json($result);
            }

            return response()->json($llm->probe($clubId));
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function testQuestion(Request $request, DeepSeekChat $llm)
    {
        $data = $request->validate([
            'club_id' => 'nullable|integer|exists:clubs,id',
            'question' => 'required|string|max:1000',
        ]);

        $clubId = (int) ($data['club_id'] ?? Club::query()->value('id'));
        $club = Club::query()->find($clubId);

        try {
            $reply = $llm->reply(trim($data['question']), [
                'game_title' => 'проверка из админки',
                'player_name' => 'админ',
                'club_name' => $club?->name,
                'club_id' => $clubId,
            ]);

            $settings = AiAssistantSetting::forClub($clubId);
            $qwen = $settings->resolvedVoiceStack() === 'qwen';

            return response()->json([
                'ok' => true,
                'reply' => $reply,
                'provider' => $qwen ? 'qwen' : 'deepseek',
                'model' => $qwen ? $settings->resolvedQwenChatModel() : $settings->resolvedDeepSeekModel(),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function testTts(Request $request, SpeechService $speech)
    {
        $data = $request->validate([
            'club_id' => 'nullable|integer|exists:clubs,id',
            'text' => 'nullable|string|max:400',
        ]);

        $clubId = (int) ($data['club_id'] ?? Club::query()->value('id'));
        $settings = AiAssistantSetting::forClub($clubId);
        $text = trim((string) ($data['text'] ?? ''));
        if ($text === '') {
            $text = $settings->resolvedVoiceStack() === 'qwen'
                ? 'Проверка озвучки. Если слышите это, Qwen работает.'
                : 'Проверка озвучки. Если слышите это, SpeechKit работает.';
        }

        try {
            $result = $speech->synthesize($text, $settings);
            $speechProvider = $settings->resolvedVoiceSpeechProvider();

            return response()->json([
                'ok' => true,
                'provider' => $speechProvider,
                'voice' => $speechProvider === 'qwen' ? $settings->resolvedQwenTtsVoice() : $settings->resolvedQuestionTtsVoice(),
                'model' => match ($speechProvider) {
                    'openai' => $settings->resolvedTtsModel(),
                    'qwen' => $settings->resolvedQwenTtsModel(),
                    default => 'speechkit',
                },
                'audio_mime' => $result['mime'],
                'audio_base64' => base64_encode($result['binary']),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
