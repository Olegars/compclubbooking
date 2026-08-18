<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import axios from 'axios'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useClubName } from '@/Composables/useClubName'

const clubName = useClubName()

const props = defineProps<{
    settings: {
        id: number
        club_id: number | null
        is_enabled: boolean
        llm_provider: string
        llm_base_url: string
        llm_model: string
        has_llm_api_key: boolean
        llm_key_source: 'db' | 'env'
        speech_provider: string
        yandex_folder_id: string
        has_yandex_api_key: boolean
        yandex_key_source: 'db' | 'env'
        openai_base_url: string
        stt_model: string
        tts_model: string
        has_openai_api_key: boolean
        openai_key_source: 'db' | 'env'
        tts_voice: string
        max_reply_chars: number
        companion_prompt: string
        greeting_prompt: string
        using_default_companion: boolean
        using_default_greeting: boolean
        credentials_ok: boolean
        has_llm_credentials: boolean
        has_openai_credentials: boolean
        has_speech_credentials: boolean
        llm_preset_base_url: string
        llm_preset_model: string
    }
    voices: Record<string, string>
    openaiVoices: Record<string, string>
    yandexVoices: Record<string, string>
    llmProviders: Record<string, string>
    speechProviders: Record<string, string>
    llmPresets: Record<string, { base_url: string; model: string }>
    clubs: Array<{ id: number; name: string }>
    env_enabled: boolean
    placeholders: {
        companion: string[]
        greeting: string[]
    }
}>()

const page = usePage()
const flashSuccess = computed(() => (page.props as any).flash?.success as string | undefined)

const form = useForm({
    club_id: props.settings.club_id,
    is_enabled: props.settings.is_enabled,
    llm_provider: props.settings.llm_provider,
    llm_api_key: '',
    clear_llm_api_key: false,
    llm_base_url: props.settings.llm_base_url,
    llm_model: props.settings.llm_model,
    speech_provider: props.settings.speech_provider,
    yandex_api_key: '',
    clear_yandex_api_key: false,
    yandex_folder_id: props.settings.yandex_folder_id,
    openai_api_key: '',
    clear_openai_api_key: false,
    openai_base_url: props.settings.openai_base_url,
    stt_model: props.settings.stt_model,
    tts_model: props.settings.tts_model,
    tts_voice: props.settings.tts_voice,
    max_reply_chars: props.settings.max_reply_chars,
    companion_prompt: props.settings.companion_prompt,
    greeting_prompt: props.settings.greeting_prompt,
})

watch(
    () => props.settings,
    (s) => {
        form.club_id = s.club_id
        form.is_enabled = s.is_enabled
        form.llm_provider = s.llm_provider
        form.llm_api_key = ''
        form.clear_llm_api_key = false
        form.llm_base_url = s.llm_base_url
        form.llm_model = s.llm_model
        form.speech_provider = s.speech_provider
        form.yandex_api_key = ''
        form.clear_yandex_api_key = false
        form.yandex_folder_id = s.yandex_folder_id
        form.openai_api_key = ''
        form.clear_openai_api_key = false
        form.openai_base_url = s.openai_base_url
        form.stt_model = s.stt_model
        form.tts_model = s.tts_model
        form.tts_voice = s.tts_voice
        form.max_reply_chars = s.max_reply_chars
        form.companion_prompt = s.companion_prompt
        form.greeting_prompt = s.greeting_prompt
        form.clearErrors()
    },
    { deep: true }
)

const liveHint = computed(() => {
    if (!props.env_enabled) return 'Шел выключен: на сервере AI_ASSISTANT_ENABLED=false. «Проверить LLM / озвучку» при этом работают. Поставь true в .env и php artisan config:clear.'
    if (!props.settings.has_llm_credentials) return 'Нет LLM-ключа DeepSeek (админка или .env)'
    if (!props.settings.has_speech_credentials) {
        return form.speech_provider === 'openai'
            ? 'DeepSeek есть, но нет OpenAI ключа — без него нет распознавания и озвучки'
            : 'DeepSeek есть, но нет ключа Yandex SpeechKit — без него нет распознавания и озвучки'
    }
    if (!form.is_enabled) return 'Выключено тумблером в админке'
    return 'Голосовой вопрос готов (нужна активная сессия на ПК)'
})

const voiceOptions = computed(() =>
    form.speech_provider === 'openai' ? props.openaiVoices : props.yandexVoices
)

watch(() => form.speech_provider, (provider) => {
    const options = provider === 'openai' ? props.openaiVoices : props.yandexVoices
    if (!Object.keys(options).includes(form.tts_voice)) {
        form.tts_voice = provider === 'openai' ? 'nova' : 'alena'
    }
})

const liveOk = computed(() => props.env_enabled && props.settings.credentials_ok && form.is_enabled)

const switchClub = (clubId: number) => {
    router.get('/admin/ai-assistant', { club_id: clubId }, { preserveState: false })
}

const applyLlmPreset = () => {
    const preset = props.llmPresets[form.llm_provider]
    if (!preset) return
    if (!form.llm_base_url.trim()) form.llm_base_url = preset.base_url
    if (!form.llm_model.trim()) form.llm_model = preset.model
}

watch(() => form.llm_provider, () => {
    // Soft fill empty fields only
    applyLlmPreset()
})

const save = () => {
    form.post('/admin/ai-assistant', {
        preserveScroll: true,
        onSuccess: () => {
            form.llm_api_key = ''
            form.yandex_api_key = ''
            form.openai_api_key = ''
            form.clear_llm_api_key = false
            form.clear_yandex_api_key = false
            form.clear_openai_api_key = false
        },
    })
}

const resetPrompts = () => {
    if (!confirm('Сбросить оба промпта к значениям по умолчанию?')) return
    router.post('/admin/ai-assistant/reset-prompts', { club_id: form.club_id }, { preserveScroll: true })
}

const testBusy = ref(false)
const testCaseName = ref('')
const testCasePart = ref('')
const testQuestionText = ref('Как зайти в игру?')
const testTtsBusy = ref(false)
let testAudio: HTMLAudioElement | null = null
const testResult = ref<{
    ok: boolean
    reply?: string
    message?: string
    model?: string
    base_url?: string
    provider?: string
    name?: string
    part?: string
    color?: string | null
    glass?: string | null
    form?: string | null
    labels?: { color: string, glass: string, form: string }
    raw?: Record<string, unknown> | null
    voice?: string
} | null>(null)

const testError = (e: any, fallback: string) => ({
    ok: false as const,
    message: e?.response?.data?.message || e?.message || fallback,
})

const testLlm = async () => {
    testBusy.value = true
    testResult.value = null
    try {
        const payload: Record<string, unknown> = { club_id: form.club_id }
        const caseName = testCaseName.value.trim()
        if (caseName) {
            payload.case_name = caseName
            payload.case_part = testCasePart.value.trim() || undefined
        }
        const { data } = await axios.post('/admin/ai-assistant/test-llm', payload)
        testResult.value = data
    } catch (e: any) {
        testResult.value = testError(e, 'Тест LLM не прошёл')
    } finally {
        testBusy.value = false
    }
}

const testQuestion = async () => {
    const question = testQuestionText.value.trim()
    if (!question) {
        testResult.value = { ok: false, message: 'Введите вопрос' }
        return
    }
    testBusy.value = true
    testResult.value = null
    try {
        const { data } = await axios.post('/admin/ai-assistant/test-question', {
            club_id: form.club_id,
            question,
        })
        testResult.value = data
    } catch (e: any) {
        testResult.value = testError(e, 'Вопрос к ИИ не прошёл')
    } finally {
        testBusy.value = false
    }
}

const testTts = async () => {
    testTtsBusy.value = true
    testResult.value = null
    try {
        const { data } = await axios.post('/admin/ai-assistant/test-tts', {
            club_id: form.club_id,
            text: testQuestionText.value.trim() || undefined,
        })
        testResult.value = data
        if (data?.ok && data.audio_base64) {
            testAudio?.pause()
            testAudio = new Audio(`data:${data.audio_mime || 'audio/mpeg'};base64,${data.audio_base64}`)
            await testAudio.play()
        }
    } catch (e: any) {
        testResult.value = testError(e, 'Озвучка не прошла')
    } finally {
        testTtsBusy.value = false
    }
}

const inputClass = 'w-full bg-black/40 border border-white/10 focus:border-cyan-500/50 rounded-xl px-5 py-3 text-sm text-white outline-none'
const labelClass = 'block text-[10px] uppercase tracking-[0.3em] text-white/40 font-black italic mb-3'
</script>

<template>
    <Head :title="`${clubName} | ИИ-ассистент`" />
    <AdminLayout>
        <div class="max-w-4xl mx-auto space-y-8 animate-in fade-in duration-500 font-mono pb-20 px-4">
            <div class="bg-[#0a0a0a] border border-white/5 p-8 rounded-[1rem] shadow-2xl">
                <h1 class="text-3xl font-black uppercase italic text-cyan-400 tracking-tighter">
                    ИИ <span class="text-white">ассистент</span>
                </h1>
                <p class="text-white/20 text-[10px] uppercase tracking-[0.4em] font-black mt-2 italic">
                    Провайдер · ключи · промпты · голос
                </p>
                <p class="mt-4 text-[11px] italic"
                   :class="liveOk ? 'text-cyan-400/70' : 'text-amber-400/70'">
                    Статус: {{ liveHint }}
                </p>
                <ul class="mt-4 grid sm:grid-cols-2 gap-2 text-[11px] text-white/50">
                    <li :class="env_enabled ? 'text-[#22c55e]/80' : 'text-amber-400/80'">
                        {{ env_enabled ? '✓' : '✗' }} AI_ASSISTANT_ENABLED в .env сервера
                    </li>
                    <li :class="form.is_enabled ? 'text-[#22c55e]/80' : 'text-amber-400/80'">
                        {{ form.is_enabled ? '✓' : '✗' }} Тумблер в админке
                    </li>
                    <li :class="settings.has_llm_credentials ? 'text-[#22c55e]/80' : 'text-amber-400/80'">
                        {{ settings.has_llm_credentials ? '✓' : '✗' }} DeepSeek (текст ответа)
                    </li>
                    <li :class="settings.has_speech_credentials ? 'text-[#22c55e]/80' : 'text-amber-400/80'">
                        {{ settings.has_speech_credentials ? '✓' : '✗' }}
                        {{ form.speech_provider === 'openai' ? 'OpenAI (Whisper + TTS)' : 'Yandex SpeechKit (речь)' }}
                    </li>
                </ul>
                <p class="mt-3 text-[10px] text-white/25 italic leading-relaxed">
                    Вопрос в Shell: микрофон → SpeechKit/Whisper → DeepSeek → озвучка в наушники. Нужна активная бронь.
                    Хоткей в config.ini сейчас Grave (Ё / ~), не F1.
                </p>
            </div>

            <div
                v-if="flashSuccess"
                class="px-5 py-4 rounded-2xl border border-[#22c55e]/30 bg-[#22c55e]/10 text-[#22c55e] text-xs font-black uppercase tracking-wider"
            >
                {{ flashSuccess }}
            </div>

            <form
                class="bg-[#0a0a0a] border border-white/5 rounded-[1rem] p-8 space-y-8"
                @submit.prevent="save"
            >
                <div v-if="clubs.length > 1" class="space-y-3">
                    <label :class="labelClass">Клуб</label>
                    <select
                        :value="form.club_id"
                        :class="inputClass"
                        @change="switchClub(Number(($event.target as HTMLSelectElement).value))"
                    >
                        <option v-for="c in clubs" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                </div>

                <div class="flex flex-wrap items-center gap-6">
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input v-model="form.is_enabled" type="checkbox" class="w-5 h-5 accent-cyan-500" />
                        <span class="text-[10px] uppercase tracking-[0.3em] text-white/50 font-black italic">
                            Включён в админке
                        </span>
                    </label>
                </div>

                <div class="border-t border-white/5 pt-8 space-y-6">
                    <h2 class="text-sm font-black uppercase italic tracking-wider text-white/70">
                        Провайдер и ключи
                    </h2>

                    <div>
                        <label :class="labelClass">LLM для ответов</label>
                        <select v-model="form.llm_provider" :class="inputClass + ' max-w-xs'">
                            <option v-for="(label, key) in llmProviders" :key="key" :value="key">
                                {{ label }}
                            </option>
                        </select>
                        <p v-if="form.errors.llm_provider" class="mt-2 text-red-400 text-xs">{{ form.errors.llm_provider }}</p>
                    </div>

                    <div class="grid md:grid-cols-2 gap-6">
                        <div>
                            <label :class="labelClass">
                                LLM API-ключ
                                <span v-if="settings.has_llm_api_key" class="text-cyan-500/70 normal-case tracking-normal">(сохранён в БД)</span>
                                <span v-else-if="settings.llm_key_source === 'env'" class="text-white/25 normal-case tracking-normal">(из .env)</span>
                            </label>
                            <input
                                v-model="form.llm_api_key"
                                type="password"
                                autocomplete="new-password"
                                placeholder="••••••"
                                :class="inputClass"
                            />
                            <label
                                v-if="settings.has_llm_api_key"
                                class="flex items-center gap-2 mt-2 text-[10px] text-white/40 cursor-pointer"
                            >
                                <input v-model="form.clear_llm_api_key" type="checkbox" class="accent-red-500" />
                                Очистить ключ в БД (останется .env)
                            </label>
                            <p v-if="form.errors.llm_api_key" class="mt-2 text-red-400 text-xs">{{ form.errors.llm_api_key }}</p>
                        </div>
                    </div>

                    <div class="border-t border-white/5 pt-8 space-y-6">
                        <h2 class="text-sm font-black uppercase italic tracking-wider text-white/70">
                            Речь (распознавание и озвучка)
                        </h2>
                        <p class="text-[10px] text-white/30 italic">
                            Из РФ OpenAI часто недоступен. По умолчанию — Yandex SpeechKit.
                        </p>

                        <div>
                            <label :class="labelClass">Провайдер речи</label>
                            <select v-model="form.speech_provider" :class="inputClass + ' max-w-xs'">
                                <option v-for="(label, key) in speechProviders" :key="key" :value="key">
                                    {{ label }}
                                </option>
                            </select>
                        </div>

                        <div v-if="form.speech_provider === 'yandex'" class="grid md:grid-cols-2 gap-6">
                            <div>
                                <label :class="labelClass">
                                    SpeechKit API-ключ
                                    <span v-if="settings.has_yandex_api_key" class="text-cyan-500/70 normal-case tracking-normal">(сохранён в БД)</span>
                                    <span v-else-if="settings.yandex_key_source === 'env'" class="text-white/25 normal-case tracking-normal">(из .env)</span>
                                </label>
                                <input
                                    v-model="form.yandex_api_key"
                                    type="password"
                                    autocomplete="new-password"
                                    placeholder="••••••"
                                    :class="inputClass"
                                />
                                <label
                                    v-if="settings.has_yandex_api_key"
                                    class="flex items-center gap-2 mt-2 text-[10px] text-white/40 cursor-pointer"
                                >
                                    <input v-model="form.clear_yandex_api_key" type="checkbox" class="accent-red-500" />
                                    Очистить ключ в БД (останется .env)
                                </label>
                                <p class="mt-2 text-[10px] text-white/25 italic">Сервисный аккаунт → API-ключ. Роли ai.speechkit-stt.user и ai.speechkit-tts.user</p>
                            </div>
                            <div>
                                <label :class="labelClass">Folder ID каталога</label>
                                <input
                                    v-model="form.yandex_folder_id"
                                    type="text"
                                    placeholder="b1g…"
                                    :class="inputClass"
                                />
                                <p class="mt-2 text-[10px] text-white/25 italic">Нужен, если ключ не от сервисного аккаунта. Для SA можно оставить пустым.</p>
                            </div>
                        </div>

                        <div v-else class="grid md:grid-cols-2 gap-6">
                            <div>
                                <label :class="labelClass">
                                    OpenAI ключ (STT + TTS)
                                    <span v-if="settings.has_openai_api_key" class="text-cyan-500/70 normal-case tracking-normal">(сохранён в БД)</span>
                                    <span v-else-if="settings.openai_key_source === 'env'" class="text-white/25 normal-case tracking-normal">(из .env)</span>
                                </label>
                                <input
                                    v-model="form.openai_api_key"
                                    type="password"
                                    autocomplete="new-password"
                                    placeholder="••••••"
                                    :class="inputClass"
                                />
                                <label
                                    v-if="settings.has_openai_api_key"
                                    class="flex items-center gap-2 mt-2 text-[10px] text-white/40 cursor-pointer"
                                >
                                    <input v-model="form.clear_openai_api_key" type="checkbox" class="accent-red-500" />
                                    Очистить ключ в БД (останется .env)
                                </label>
                            </div>
                            <div>
                                <label :class="labelClass">OpenAI Base URL</label>
                                <input
                                    v-model="form.openai_base_url"
                                    type="text"
                                    placeholder="https://api.openai.com/v1"
                                    :class="inputClass"
                                />
                            </div>
                        </div>
                    </div>

                    <div class="grid md:grid-cols-2 gap-6">
                        <div>
                            <label :class="labelClass">LLM Base URL</label>
                            <input
                                v-model="form.llm_base_url"
                                type="text"
                                :placeholder="settings.llm_preset_base_url"
                                :class="inputClass"
                            />
                            <p class="mt-2 text-[10px] text-white/25 italic">Пусто — пресет / .env</p>
                            <p v-if="form.errors.llm_base_url" class="mt-2 text-red-400 text-xs">{{ form.errors.llm_base_url }}</p>
                        </div>
                        <div>
                            <label :class="labelClass">LLM модель</label>
                            <input
                                v-model="form.llm_model"
                                type="text"
                                :placeholder="settings.llm_preset_model"
                                :class="inputClass"
                            />
                            <p class="mt-2 text-[10px] text-white/25 italic">Пусто — пресет / .env</p>
                            <p v-if="form.errors.llm_model" class="mt-2 text-red-400 text-xs">{{ form.errors.llm_model }}</p>
                        </div>
                    </div>

                    <div class="rounded-xl border border-white/10 bg-black/30 px-5 py-4 space-y-4">
                        <div>
                            <label :class="labelClass">Вопрос игрока (проверка F1 без микрофона)</label>
                            <textarea
                                v-model="testQuestionText"
                                rows="3"
                                :class="inputClass + ' min-h-[72px] resize-y'"
                                placeholder="Как зайти в игру?"
                            />
                        </div>
                        <div class="space-y-3">
                            <div>
                                <label :class="labelClass">Название корпуса (опционально)</label>
                                <input
                                    v-model="testCaseName"
                                    type="text"
                                    :class="inputClass"
                                    placeholder="Напр. Корпус DeepCool CH560 Digital WH White"
                                />
                            </div>
                            <div>
                                <label :class="labelClass">Партномер (опционально)</label>
                                <input
                                    v-model="testCasePart"
                                    type="text"
                                    :class="inputClass"
                                    placeholder="R-CH560-WHAPE4-G-1"
                                />
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-3">
                            <button
                                type="button"
                                class="px-6 py-3 rounded-xl border border-cyan-500/40 text-cyan-400 font-black uppercase tracking-widest text-[10px] hover:bg-cyan-500/10 transition-all disabled:opacity-50"
                                :disabled="testBusy"
                                @click="testQuestion"
                            >
                                {{ testBusy ? 'Спрашиваю…' : 'Спросить ИИ' }}
                            </button>
                            <button
                                type="button"
                                class="px-6 py-3 rounded-xl border border-white/20 text-white/70 font-black uppercase tracking-widest text-[10px] hover:bg-white/5 transition-all disabled:opacity-50"
                                :disabled="testBusy"
                                @click="testLlm"
                            >
                                {{ testBusy ? 'Проверка…' : (testCaseName.trim() ? 'Проверить корпус' : 'Пинг LLM') }}
                            </button>
                            <button
                                type="button"
                                class="px-6 py-3 rounded-xl border border-amber-500/40 text-amber-300 font-black uppercase tracking-widest text-[10px] hover:bg-amber-500/10 transition-all disabled:opacity-50"
                                :disabled="testTtsBusy"
                                @click="testTts"
                            >
                                {{ testTtsBusy ? 'Озвучка…' : 'Проверить озвучку' }}
                            </button>
                            <p class="text-[10px] text-white/30 italic max-w-md">
                                «Спросить» — тот же промпт, что F1, без микрофона. «Озвучка» проверяет SpeechKit / OpenAI TTS.
                            </p>
                        </div>
                        <div v-if="testResult?.ok" class="text-xs text-[#22c55e] leading-relaxed space-y-2">
                            <p>
                                OK · {{ testResult.provider || testResult.voice || 'llm' }} · {{ testResult.model }}
                            </p>
                            <p v-if="testResult.reply" class="text-white/80 normal-case tracking-normal not-italic font-black">
                                {{ testResult.reply }}
                            </p>
                            <p v-else-if="testResult.voice" class="text-white/70 normal-case tracking-normal not-italic">
                                Озвучка проиграна голосом {{ testResult.voice }}
                            </p>
                            <pre v-if="testResult.raw"
                                 class="mt-2 p-3 rounded-lg bg-black/50 border border-white/10 text-[11px] text-cyan-300/90 overflow-x-auto normal-case tracking-normal not-italic font-mono">{{ JSON.stringify(testResult.raw, null, 2) }}</pre>
                        </div>
                        <p v-else-if="testResult && !testResult.ok" class="text-xs text-red-400 leading-relaxed break-words">
                            {{ testResult.message }}
                        </p>
                    </div>

                    <div v-if="form.speech_provider === 'openai'" class="grid md:grid-cols-2 gap-6">
                        <div>
                            <label :class="labelClass">STT модель</label>
                            <input v-model="form.stt_model" type="text" placeholder="whisper-1" :class="inputClass" />
                        </div>
                        <div>
                            <label :class="labelClass">TTS модель</label>
                            <input v-model="form.tts_model" type="text" placeholder="tts-1" :class="inputClass" />
                        </div>
                    </div>

                    <div class="grid md:grid-cols-2 gap-6">
                        <div>
                            <label :class="labelClass">
                                Голос озвучки
                                {{ form.speech_provider === 'yandex' ? '(Yandex)' : '(OpenAI)' }}
                            </label>
                            <select v-model="form.tts_voice" :class="inputClass">
                                <option v-for="(label, key) in voiceOptions" :key="key" :value="key">
                                    {{ label }} ({{ key }})
                                </option>
                            </select>
                            <p v-if="form.errors.tts_voice" class="mt-2 text-red-400 text-xs">{{ form.errors.tts_voice }}</p>
                        </div>
                        <div>
                            <label :class="labelClass">Макс. длина ответа (символы)</label>
                            <input
                                v-model.number="form.max_reply_chars"
                                type="number"
                                min="80"
                                max="2000"
                                :class="inputClass"
                            />
                            <p v-if="form.errors.max_reply_chars" class="mt-2 text-red-400 text-xs">{{ form.errors.max_reply_chars }}</p>
                        </div>
                    </div>
                </div>

                <div class="border-t border-white/5 pt-8 space-y-8">
                    <h2 class="text-sm font-black uppercase italic tracking-wider text-white/70">
                        Промпты
                    </h2>

                    <div>
                        <div class="flex flex-wrap items-baseline justify-between gap-2 mb-3">
                            <label class="text-[10px] uppercase tracking-[0.3em] text-white/40 font-black italic">
                                Промпт F1-компаньона
                            </label>
                            <span
                                v-if="settings.using_default_companion && form.companion_prompt === settings.companion_prompt"
                                class="text-[9px] uppercase tracking-widest text-white/25"
                            >
                                дефолт
                            </span>
                        </div>
                        <textarea
                            v-model="form.companion_prompt"
                            rows="14"
                            class="w-full bg-black/40 border border-white/10 focus:border-cyan-500/50 rounded-xl px-5 py-4 text-xs text-white/80 outline-none leading-relaxed resize-y min-h-[220px]"
                        />
                        <p class="mt-2 text-[10px] text-white/25 italic">
                            Плейсхолдеры:
                            <span
                                v-for="p in placeholders.companion"
                                :key="p"
                                class="text-cyan-400/60 mx-1"
                            >{{ p }}</span>
                        </p>
                        <p v-if="form.errors.companion_prompt" class="mt-2 text-red-400 text-xs">
                            {{ form.errors.companion_prompt }}
                        </p>
                    </div>

                    <div>
                        <div class="flex flex-wrap items-baseline justify-between gap-2 mb-3">
                            <label class="text-[10px] uppercase tracking-[0.3em] text-white/40 font-black italic">
                                Промпт приветствия при входе
                            </label>
                            <span
                                v-if="settings.using_default_greeting && form.greeting_prompt === settings.greeting_prompt"
                                class="text-[9px] uppercase tracking-widest text-white/25"
                            >
                                дефолт
                            </span>
                        </div>
                        <textarea
                            v-model="form.greeting_prompt"
                            rows="14"
                            class="w-full bg-black/40 border border-white/10 focus:border-cyan-500/50 rounded-xl px-5 py-4 text-xs text-white/80 outline-none leading-relaxed resize-y min-h-[220px]"
                        />
                        <p class="mt-2 text-[10px] text-white/25 italic">
                            Плейсхолдеры:
                            <span
                                v-for="p in placeholders.greeting"
                                :key="p"
                                class="text-cyan-400/60 mx-1"
                            >{{ p }}</span>
                        </p>
                        <p v-if="form.errors.greeting_prompt" class="mt-2 text-red-400 text-xs">
                            {{ form.errors.greeting_prompt }}
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap gap-4">
                    <button
                        type="submit"
                        class="px-8 py-4 rounded-xl bg-cyan-500 text-black font-black uppercase tracking-widest text-[10px] hover:scale-[1.02] transition-all disabled:opacity-50"
                        :disabled="form.processing"
                    >
                        {{ form.processing ? 'Сохранение…' : 'Сохранить' }}
                    </button>
                    <button
                        type="button"
                        class="px-8 py-4 rounded-xl border border-white/10 text-white/50 font-black uppercase tracking-widest text-[10px] hover:border-white/25 hover:text-white transition-all"
                        @click="resetPrompts"
                    >
                        Сбросить промпты к дефолту
                    </button>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
