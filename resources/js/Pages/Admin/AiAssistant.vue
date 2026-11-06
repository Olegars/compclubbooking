<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3'
import { computed, onMounted, ref, watch } from 'vue'
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
        voice_stack: string
        has_qwen_api_key: boolean
        qwen_key_source: 'db' | 'env'
        has_qwen_credentials: boolean
        qwen_tts_voice: string
        qwen_chat_model: string
        qwen_asr_model: string
        qwen_tts_model: string
        yandex_folder_id: string
        has_yandex_api_key: boolean
        yandex_key_source: 'db' | 'env'
        has_alibaba_billing_key: boolean
        alibaba_billing_key_source: 'db' | 'env'
        has_yandex_billing_iam_token: boolean
        yandex_billing_key_source: 'db' | 'env'
        yandex_billing_account_id: string
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
    voiceStacks: Record<string, string>
    qwenVoices: Record<string, string>
    llmPresets: Record<string, { base_url: string; model: string }>
    clubs: Array<{ id: number; name: string }>
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
    voice_stack: props.settings.voice_stack || 'deepseek_yandex',
    qwen_api_key: '',
    clear_qwen_api_key: false,
    qwen_tts_voice: props.settings.qwen_tts_voice || 'Cherry',
    yandex_api_key: '',
    clear_yandex_api_key: false,
    yandex_folder_id: props.settings.yandex_folder_id,
    alibaba_access_key_id: '',
    alibaba_access_key_secret: '',
    clear_alibaba_billing: false,
    yandex_billing_iam_token: '',
    clear_yandex_billing_iam_token: false,
    yandex_billing_account_id: props.settings.yandex_billing_account_id,
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
        form.voice_stack = s.voice_stack || 'deepseek_yandex'
        form.qwen_api_key = ''
        form.clear_qwen_api_key = false
        form.qwen_tts_voice = s.qwen_tts_voice || 'Cherry'
        form.yandex_api_key = ''
        form.clear_yandex_api_key = false
        form.yandex_folder_id = s.yandex_folder_id
        form.alibaba_access_key_id = ''
        form.alibaba_access_key_secret = ''
        form.clear_alibaba_billing = false
        form.yandex_billing_iam_token = ''
        form.clear_yandex_billing_iam_token = false
        form.yandex_billing_account_id = s.yandex_billing_account_id
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
    if (form.voice_stack === 'qwen') {
        if (!props.settings.has_qwen_credentials) return 'Нет ключа Qwen (админка или .env)'
        if (!form.is_enabled) return 'Выключено тумблером в админке'
        return 'Вопрос идёт через Qwen: распознавание, ответ и озвучка'
    }
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

watch(() => form.voice_stack, (stack) => {
    if (stack === 'deepseek_yandex') {
        form.llm_provider = 'deepseek'
        form.speech_provider = 'yandex'
        if (!Object.keys(props.yandexVoices).includes(form.tts_voice)) {
            form.tts_voice = 'alena'
        }
    }
    if (stack === 'qwen' && !Object.keys(props.qwenVoices || {}).includes(form.qwen_tts_voice)) {
        form.qwen_tts_voice = 'Cherry'
    }
})

const liveOk = computed(() => {
    if (!form.is_enabled) return false
    if (form.voice_stack === 'qwen') return props.settings.has_qwen_credentials
    return props.settings.has_llm_credentials && props.settings.has_speech_credentials
})

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
            form.qwen_api_key = ''
            form.alibaba_access_key_id = ''
            form.alibaba_access_key_secret = ''
            form.yandex_billing_iam_token = ''
            form.clear_llm_api_key = false
            form.clear_yandex_api_key = false
            form.clear_openai_api_key = false
            form.clear_qwen_api_key = false
            form.clear_alibaba_billing = false
            form.clear_yandex_billing_iam_token = false
            loadBalance()
        },
    })
}

const resetPrompts = () => {
    if (!confirm('Сбросить оба промпта к значениям по умолчанию?')) return
    router.post('/admin/ai-assistant/reset-prompts', { club_id: form.club_id }, { preserveScroll: true })
}

type AiBalanceRow = {
    currency: string
    total: string
    granted?: string
    topped_up?: string
    cash?: string
    credit?: string
    name?: string
    active?: boolean
}
type AiBalance = {
    ok: boolean
    supported: boolean
    provider?: string
    is_available?: boolean
    balances?: AiBalanceRow[]
    message?: string
}
type AiBalances = {
    deepseek?: AiBalance
    qwen?: AiBalance
    yandex?: AiBalance
}

const balance = ref<AiBalances | null>(null)
const balanceCards = [
    { key: 'deepseek' as const, title: 'DeepSeek' },
    { key: 'qwen' as const, title: 'Qwen' },
    { key: 'yandex' as const, title: 'Яндекс' },
]
const balanceLoading = ref(false)

const loadBalance = async () => {
    balanceLoading.value = true
    try {
        const { data } = await axios.get('/admin/ai-assistant/balance', {
            params: { club_id: form.club_id },
        })
        balance.value = data
    } catch (e: any) {
        balance.value = {
            ok: false,
            supported: true,
            message: e?.response?.data?.message || 'Не удалось получить баланс',
        }
    } finally {
        balanceLoading.value = false
    }
}

onMounted(loadBalance)
watch(() => props.settings.club_id, () => {
    loadBalance()
})

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
                    <li :class="form.is_enabled ? 'text-[#22c55e]/80' : 'text-amber-400/80'">
                        {{ form.is_enabled ? '✓' : '✗' }} Тумблер в админке
                    </li>
                    <li v-if="form.voice_stack === 'qwen'" :class="settings.has_qwen_credentials ? 'text-[#22c55e]/80' : 'text-amber-400/80'">
                        {{ settings.has_qwen_credentials ? '✓' : '✗' }} Qwen (распознавание, ответ, озвучка)
                    </li>
                    <template v-else>
                        <li :class="settings.has_llm_credentials ? 'text-[#22c55e]/80' : 'text-amber-400/80'">
                            {{ settings.has_llm_credentials ? '✓' : '✗' }} DeepSeek (текст ответа)
                        </li>
                        <li :class="settings.has_speech_credentials ? 'text-[#22c55e]/80' : 'text-amber-400/80'">
                            {{ settings.has_speech_credentials ? '✓' : '✗' }}
                            {{ form.speech_provider === 'openai' ? 'OpenAI (Whisper + TTS)' : 'Yandex SpeechKit (речь)' }}
                        </li>
                    </template>
                </ul>
                <div class="mt-5 border-t border-white/5 pt-4">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-[10px] uppercase tracking-[0.3em] text-white/40 font-black">Баланс</p>
                        <button
                            type="button"
                            class="px-4 py-2 rounded-xl border border-white/10 text-[10px] uppercase tracking-widest text-white/50 hover:text-cyan-400 hover:border-cyan-500/40 disabled:opacity-40"
                            :disabled="balanceLoading"
                            @click="loadBalance"
                        >
                            Обновить
                        </button>
                    </div>
                    <div class="mt-3 grid md:grid-cols-3 gap-4">
                        <div v-for="card in balanceCards" :key="card.key" class="rounded-xl border border-white/5 bg-black/30 p-4">
                            <p class="text-[10px] uppercase tracking-[0.25em] text-white/35 font-black">{{ card.title }}</p>
                            <p v-if="balanceLoading" class="mt-2 text-sm text-white/40">Запрашиваем…</p>
                            <template v-else-if="balance?.[card.key]?.ok && balance[card.key]?.balances?.length">
                                <p v-for="(row, index) in balance[card.key].balances" :key="row.currency + index" class="mt-2">
                                    <span class="text-lg font-black text-white">{{ row.total }} {{ row.currency }}</span>
                                    <span v-if="row.name" class="block text-[10px] text-white/45">{{ row.name }}</span>
                                    <span v-if="row.granted || row.topped_up" class="block text-[10px] text-white/35">
                                        пополнение {{ row.topped_up }}, грант {{ row.granted }}
                                    </span>
                                    <span v-else-if="row.cash || row.credit" class="block text-[10px] text-white/35">
                                        наличные {{ row.cash || '0' }}<template v-if="row.credit">, кредит {{ row.credit }}</template>
                                    </span>
                                    <span v-if="row.active === false" class="block text-[10px] text-amber-400/80">счёт неактивен</span>
                                </p>
                                <p v-if="balance[card.key].is_available === false" class="mt-1 text-[10px] text-amber-400/80">
                                    Недостаточно для запросов
                                </p>
                            </template>
                            <p v-else class="mt-2 text-[11px] text-white/40 italic leading-snug">
                                {{ balance?.[card.key]?.message || 'Баланс недоступен' }}
                            </p>
                        </div>
                    </div>
                </div>
                <p class="mt-3 text-[10px] text-white/25 italic leading-relaxed">
                    Вопрос в Shell: микрофон → речь → ответ → озвучка. Стек ниже: DeepSeek + Яндекс Speech или Qwen целиком. Нужна активная бронь.
                    Хоткей в config.ini сейчас Grave (Ё / ~), не F1.
                    Баланс Qwen — AccessKey Alibaba, не ключ картинок. Баланс Яндекса — IAM-токен биллинга, не ключ SpeechKit.
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
                        Стек вопроса
                    </h2>
                    <p class="text-[10px] text-white/30 italic">
                        DeepSeek + Яндекс Speech — как сейчас. Qwen сам распознаёт, отвечает и озвучивает. Ники, накладные и шёпот тренера остаются на DeepSeek.
                    </p>
                    <div>
                        <label :class="labelClass">Кто отвечает на вопрос в Shell</label>
                        <select v-model="form.voice_stack" :class="inputClass + ' max-w-md'">
                            <option v-for="(label, key) in voiceStacks" :key="key" :value="key">
                                {{ label }}
                            </option>
                        </select>
                        <p v-if="form.errors.voice_stack" class="mt-2 text-red-400 text-xs">{{ form.errors.voice_stack }}</p>
                    </div>

                    <div v-if="form.voice_stack === 'qwen'" class="grid md:grid-cols-2 gap-6">
                        <div>
                            <label :class="labelClass">
                                Qwen API-ключ
                                <span v-if="settings.has_qwen_api_key" class="text-cyan-500/70 normal-case tracking-normal">(сохранён в БД)</span>
                                <span v-else-if="settings.qwen_key_source === 'env'" class="text-white/25 normal-case tracking-normal">(из .env)</span>
                            </label>
                            <input
                                v-model="form.qwen_api_key"
                                type="password"
                                autocomplete="new-password"
                                placeholder="sk-…"
                                :class="inputClass"
                            />
                            <label
                                v-if="settings.has_qwen_api_key"
                                class="flex items-center gap-2 mt-2 text-[10px] text-white/40 cursor-pointer"
                            >
                                <input v-model="form.clear_qwen_api_key" type="checkbox" class="accent-red-500" />
                                Очистить ключ в БД (останется .env)
                            </label>
                            <p class="mt-2 text-[10px] text-white/25 italic">Тот же ключ, что у стилизации аватара. Регион ключа и базы должен совпадать.</p>
                            <p v-if="form.errors.qwen_api_key" class="mt-2 text-red-400 text-xs">{{ form.errors.qwen_api_key }}</p>
                        </div>
                        <div>
                            <label :class="labelClass">Голос Qwen</label>
                            <select v-model="form.qwen_tts_voice" :class="inputClass">
                                <option v-for="(label, key) in qwenVoices" :key="key" :value="key">
                                    {{ label }}
                                </option>
                            </select>
                            <p class="mt-2 text-[10px] text-white/25 italic">
                                {{ settings.qwen_asr_model }} → {{ settings.qwen_chat_model }} → {{ settings.qwen_tts_model }}, язык русский.
                            </p>
                            <p v-if="form.errors.qwen_tts_voice" class="mt-2 text-red-400 text-xs">{{ form.errors.qwen_tts_voice }}</p>
                        </div>
                    </div>

                    <h2 class="text-sm font-black uppercase italic tracking-wider text-white/70">
                        Провайдер и ключи
                    </h2>
                    <p v-if="form.voice_stack === 'qwen'" class="text-[10px] text-white/30 italic">
                        Для вопроса в Shell эти поля не используются. DeepSeek по-прежнему нужен никам, накладным и шёпоту.
                    </p>

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
                            Ключи баланса
                        </h2>
                        <p class="text-[10px] text-white/30 italic">
                            Пустое поле не затирает сохранённый ключ. Можно задать и в .env.
                        </p>
                        <div class="grid md:grid-cols-2 gap-6">
                            <div>
                                <label :class="labelClass">
                                    Alibaba AccessKey ID
                                    <span v-if="settings.has_alibaba_billing_key" class="text-cyan-500/70 normal-case tracking-normal">(сохранён в БД)</span>
                                    <span v-else-if="settings.alibaba_billing_key_source === 'env'" class="text-white/25 normal-case tracking-normal">(из .env)</span>
                                </label>
                                <input v-model="form.alibaba_access_key_id" type="password" autocomplete="new-password" placeholder="LTAI…" :class="inputClass" />
                            </div>
                            <div>
                                <label :class="labelClass">Alibaba AccessKey Secret</label>
                                <input v-model="form.alibaba_access_key_secret" type="password" autocomplete="new-password" placeholder="••••••" :class="inputClass" />
                                <label v-if="settings.has_alibaba_billing_key" class="flex items-center gap-2 mt-2 text-[10px] text-white/40 cursor-pointer">
                                    <input v-model="form.clear_alibaba_billing" type="checkbox" class="accent-red-500" />
                                    Очистить ключ в БД (останется .env)
                                </label>
                                <p class="mt-2 text-[10px] text-white/25 italic">Пара AccessKey с правом bss:DescribeAcccount. Не QWEN_API_KEY.</p>
                            </div>
                            <div>
                                <label :class="labelClass">
                                    IAM-токен биллинга Яндекса
                                    <span v-if="settings.has_yandex_billing_iam_token" class="text-cyan-500/70 normal-case tracking-normal">(сохранён в БД)</span>
                                    <span v-else-if="settings.yandex_billing_key_source === 'env'" class="text-white/25 normal-case tracking-normal">(из .env)</span>
                                </label>
                                <input v-model="form.yandex_billing_iam_token" type="password" autocomplete="new-password" placeholder="••••••" :class="inputClass" />
                                <label v-if="settings.has_yandex_billing_iam_token" class="flex items-center gap-2 mt-2 text-[10px] text-white/40 cursor-pointer">
                                    <input v-model="form.clear_yandex_billing_iam_token" type="checkbox" class="accent-red-500" />
                                    Очистить токен в БД (останется .env)
                                </label>
                                <p class="mt-2 text-[10px] text-white/25 italic">Живёт около 12 часов. Ключ SpeechKit сюда не подходит.</p>
                            </div>
                            <div>
                                <label :class="labelClass">ID платёжного аккаунта Яндекса</label>
                                <input v-model="form.yandex_billing_account_id" type="text" placeholder="dn2…" :class="inputClass" />
                                <p class="mt-2 text-[10px] text-white/25 italic">Пусто — показать все счета, которые видит токен.</p>
                            </div>
                        </div>
                    </div>

                    <div v-if="form.voice_stack !== 'qwen'" class="border-t border-white/5 pt-8 space-y-6">
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
                                <p class="mt-2 text-[10px] text-white/25 italic">Для API-ключа не отправляется. Если 401 PermissionDenied — выдайте сервисному аккаунту роли ai.speechkit-tts.user и ai.speechkit-stt.user на каталог ключа.</p>
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
                                «Спросить» — тот же промпт, что вопрос в Shell, без микрофона. «Озвучка» проверяет речь выбранного стека.
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

                    <div v-if="form.voice_stack !== 'qwen' && form.speech_provider === 'openai'" class="grid md:grid-cols-2 gap-6">
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
                        <div v-if="form.voice_stack !== 'qwen'">
                            <label :class="labelClass">
                                Голос озвучки
                                {{ form.speech_provider === 'yandex' ? '(Yandex)' : '(OpenAI)' }}
                            </label>
                            <select v-model="form.tts_voice" :class="inputClass">
                                <option v-for="(label, key) in voiceOptions" :key="key" :value="key">
                                    {{ label }} ({{ key }})
                                </option>
                            </select>
                            <p class="mt-2 text-[10px] text-white/25 italic leading-relaxed">
                                Алёна / Филипп / Джейн / Захар — классика SpeechKit.
                                Марина, Даша, Александр и остальные идут через API v3; раньше на них был Bad Request.
                            </p>
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
