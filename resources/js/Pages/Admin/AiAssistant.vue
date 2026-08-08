<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3'
import { computed, watch } from 'vue'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const props = defineProps<{
    settings: {
        id: number
        club_id: number | null
        is_enabled: boolean
        tts_voice: string
        companion_prompt: string
        greeting_prompt: string
        using_default_companion: boolean
        using_default_greeting: boolean
    }
    voices: Record<string, string>
    clubs: Array<{ id: number; name: string }>
    env_enabled: boolean
    keys_configured: boolean
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
    tts_voice: props.settings.tts_voice,
    companion_prompt: props.settings.companion_prompt,
    greeting_prompt: props.settings.greeting_prompt,
})

watch(
    () => props.settings,
    (s) => {
        form.club_id = s.club_id
        form.is_enabled = s.is_enabled
        form.tts_voice = s.tts_voice
        form.companion_prompt = s.companion_prompt
        form.greeting_prompt = s.greeting_prompt
        form.clearErrors()
    },
    { deep: true }
)

const liveHint = computed(() => {
    if (!props.env_enabled) return 'Выключено в .env (AI_ASSISTANT_ENABLED=false)'
    if (!props.keys_configured) return 'Нет ключей DEEPSEEK_API_KEY / OPENAI_API_KEY'
    if (!form.is_enabled) return 'Выключено в админке'
    return 'Активен'
})

const liveOk = computed(() => props.env_enabled && props.keys_configured && form.is_enabled)

const switchClub = (clubId: number) => {
    router.get('/admin/ai-assistant', { club_id: clubId }, { preserveState: false })
}

const save = () => {
    form.post('/admin/ai-assistant', { preserveScroll: true })
}

const resetPrompts = () => {
    if (!confirm('Сбросить оба промпта к значениям по умолчанию?')) return
    router.post('/admin/ai-assistant/reset-prompts', { club_id: form.club_id }, { preserveScroll: true })
}
</script>

<template>
    <Head title="REACTOR | ИИ-ассистент" />
    <AdminLayout>
        <div class="max-w-4xl mx-auto space-y-8 animate-in fade-in duration-500 font-mono pb-20 px-4">
            <div class="bg-[#0a0a0a] border border-white/5 p-8 rounded-[1rem] shadow-2xl">
                <h1 class="text-3xl font-black uppercase italic text-cyan-400 tracking-tighter">
                    ИИ <span class="text-white">ассистент</span>
                </h1>
                <p class="text-white/20 text-[10px] uppercase tracking-[0.4em] font-black mt-2 italic">
                    Промпты · голос TTS · F1 и приветствие
                </p>
                <p class="mt-4 text-[11px] italic"
                   :class="liveOk ? 'text-cyan-400/70' : 'text-amber-400/70'">
                    Статус: {{ liveHint }}
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
                    <label class="block text-[10px] uppercase tracking-[0.3em] text-white/40 font-black italic">
                        Клуб
                    </label>
                    <select
                        :value="form.club_id"
                        class="w-full bg-black/40 border border-white/10 focus:border-cyan-500/50 rounded-xl px-5 py-3 text-sm text-white outline-none"
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
                    <p v-if="form.errors.is_enabled" class="text-red-400 text-xs">{{ form.errors.is_enabled }}</p>
                </div>

                <div>
                    <label class="block text-[10px] uppercase tracking-[0.3em] text-white/40 font-black italic mb-3">
                        Голос озвучки (OpenAI TTS)
                    </label>
                    <select
                        v-model="form.tts_voice"
                        class="w-full max-w-xs bg-black/40 border border-white/10 focus:border-cyan-500/50 rounded-xl px-5 py-3 text-sm text-white outline-none"
                    >
                        <option v-for="(label, key) in voices" :key="key" :value="key">
                            {{ label }} ({{ key }})
                        </option>
                    </select>
                    <p v-if="form.errors.tts_voice" class="mt-2 text-red-400 text-xs">{{ form.errors.tts_voice }}</p>
                    <p class="mt-3 text-[11px] text-white/30 italic">
                        Один голос для F1-компаньона и приветствия при входе.
                    </p>
                </div>

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
