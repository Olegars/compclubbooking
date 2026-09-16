<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useClubName } from '@/Composables/useClubName'
import { useToast } from '@/Composables/useToast'

type Field = {
    key: string
    label: string
    type: 'int' | 'number' | 'bool'
    default: number | boolean
    min?: number
    max?: number
    step?: number
    suffix?: string
    hint?: string
}

type Feature = {
    key: string
    title: string
    description: string
    group: string
    group_title: string
    icon: string
    admin_path: string | null
    enabled: boolean
    settings: Record<string, number | boolean>
    fields: Field[]
}

const props = defineProps<{
    club_id: number | null
    features: Feature[]
}>()

const clubName = useClubName()
const page = usePage()
const { success, error } = useToast()

const flashSuccess = computed(() => (page.props as any).flash?.success as string | undefined)
watch(flashSuccess, (msg) => { if (msg) success(msg) }, { immediate: true })
watch(() => (page.props as any).errors?.message as string | undefined, (msg) => { if (msg) error(msg) })

const drafts = reactive<Record<string, Record<string, number | boolean>>>({})
const busy = reactive<Record<string, boolean>>({})

const syncDrafts = (list: Feature[]) => {
    for (const feature of list) {
        drafts[feature.key] = { ...feature.settings }
    }
}
syncDrafts(props.features)
watch(() => props.features, (list) => syncDrafts(list), { deep: true })

const groups = computed(() => {
    const order: string[] = []
    const map = new Map<string, { title: string; items: Feature[] }>()
    for (const feature of props.features) {
        if (!map.has(feature.group)) {
            map.set(feature.group, { title: feature.group_title, items: [] })
            order.push(feature.group)
        }
        map.get(feature.group)!.items.push(feature)
    }
    return order.map((key) => map.get(key)!).filter(Boolean)
})

const post = (feature: Feature, enabled: boolean, withSettings: boolean) => {
    if (busy[feature.key]) return
    busy[feature.key] = true
    const payload: Record<string, unknown> = { enabled }
    if (withSettings) {
        payload.settings = { ...(drafts[feature.key] || feature.settings) }
    }
    router.post(`/admin/config/features/${feature.key}`, payload, {
        preserveScroll: true,
        onError: () => error('Не удалось сохранить фичу'),
        onFinish: () => { busy[feature.key] = false },
    })
}

const toggle = (feature: Feature) => post(feature, !feature.enabled, false)

const saveSettings = (feature: Feature) => post(feature, feature.enabled, true)

const dirty = (feature: Feature) => {
    const draft = drafts[feature.key] || {}
    return feature.fields.some((field) => draft[field.key] !== feature.settings[field.key])
}
</script>

<template>
    <Head :title="`${clubName} | Фичи`" />
    <AdminLayout>
        <div class="max-w-4xl mx-auto space-y-8 animate-in fade-in duration-500 font-mono pb-20 px-4">
            <div class="bg-[#0a0a0a] border border-white/5 p-8 rounded-[1rem] shadow-2xl">
                <h1 class="text-3xl font-black uppercase italic text-cyan-400 tracking-tighter">
                    Фичи
                </h1>
                <p class="text-white/20 text-[10px] uppercase tracking-[0.4em] font-black mt-2 italic">
                    Тумблеры клуба · настройки контуров
                </p>
                <p class="text-white/50 text-xs font-bold mt-4 leading-relaxed">
                    Выключенная фича пропадает из шелла и перестаёт писать события.
                    Значения по умолчанию совпадают с тем, как контур работал до этой страницы.
                </p>
            </div>

            <section v-for="group in groups" :key="group.title" class="space-y-4">
                <h2 class="text-[10px] uppercase tracking-[0.35em] text-white/35 font-black italic px-1">
                    {{ group.title }}
                </h2>

                <article
                    v-for="feature in group.items"
                    :key="feature.key"
                    class="bg-[#0a0a0a] border rounded-[1rem] p-6 space-y-5 transition-colors"
                    :class="feature.enabled ? 'border-white/5' : 'border-white/[0.04] opacity-80'"
                >
                    <div class="flex items-start gap-4">
                        <div class="w-11 h-11 rounded-2xl bg-white/[0.03] border border-white/10 flex items-center justify-center text-lg shrink-0">
                            {{ feature.icon }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-3">
                                <h3 class="text-lg font-black uppercase italic tracking-tight text-white">
                                    {{ feature.title }}
                                </h3>
                                <span
                                    class="text-[9px] font-black uppercase tracking-widest px-2 py-0.5 rounded-full border"
                                    :class="feature.enabled
                                        ? 'border-[#22c55e]/40 text-[#22c55e] bg-[#22c55e]/10'
                                        : 'border-white/10 text-white/35'"
                                >
                                    {{ feature.enabled ? 'ON' : 'OFF' }}
                                </span>
                            </div>
                            <p class="text-[12px] text-white/45 mt-2 leading-relaxed">
                                {{ feature.description }}
                            </p>
                            <Link
                                v-if="feature.admin_path"
                                :href="feature.admin_path"
                                class="inline-block mt-3 text-[10px] font-black uppercase tracking-widest text-cyan-400/80 hover:text-cyan-300"
                            >
                                Открыть раздел →
                            </Link>
                        </div>
                        <button
                            type="button"
                            class="shrink-0 relative w-14 h-8 rounded-full border transition-colors"
                            :class="feature.enabled ? 'bg-cyan-500/20 border-cyan-500/50' : 'bg-black/40 border-white/10'"
                            :disabled="busy[feature.key]"
                            :aria-pressed="feature.enabled"
                            @click="toggle(feature)"
                        >
                            <span
                                class="absolute top-1 w-6 h-6 rounded-full transition-all"
                                :class="feature.enabled ? 'left-7 bg-cyan-400' : 'left-1 bg-white/30'"
                            />
                        </button>
                    </div>

                    <div v-if="feature.fields.length" class="grid gap-4 sm:grid-cols-2 pt-2 border-t border-white/5">
                        <label
                            v-for="field in feature.fields"
                            :key="field.key"
                            class="block"
                            :class="field.type === 'bool' ? 'sm:col-span-2' : ''"
                        >
                            <span class="block text-[10px] uppercase tracking-[0.22em] text-white/40 font-black italic mb-2">
                                {{ field.label }}
                            </span>
                            <div v-if="field.type === 'bool'" class="flex items-center gap-3">
                                <input
                                    type="checkbox"
                                    class="w-5 h-5 accent-cyan-500"
                                    :checked="Boolean(drafts[feature.key]?.[field.key])"
                                    :disabled="!feature.enabled || busy[feature.key]"
                                    @change="drafts[feature.key][field.key] = ($event.target as HTMLInputElement).checked"
                                />
                                <span class="text-[11px] text-white/45 italic">{{ field.hint || 'Вкл / выкл' }}</span>
                            </div>
                            <div v-else class="flex items-center gap-3">
                                <input
                                    :type="'number'"
                                    class="w-full bg-black/40 border border-white/10 focus:border-cyan-500/50 rounded-xl px-4 py-3 text-white font-black outline-none disabled:opacity-40"
                                    :min="field.min"
                                    :max="field.max"
                                    :step="field.step"
                                    :disabled="!feature.enabled || busy[feature.key]"
                                    :value="drafts[feature.key]?.[field.key]"
                                    @input="drafts[feature.key][field.key] = field.type === 'int'
                                        ? Number(($event.target as HTMLInputElement).value)
                                        : Number(($event.target as HTMLInputElement).value)"
                                />
                                <span v-if="field.suffix" class="text-[11px] text-white/35 font-black uppercase tracking-wider shrink-0">
                                    {{ field.suffix }}
                                </span>
                            </div>
                            <p v-if="field.hint && field.type !== 'bool'" class="mt-1.5 text-[10px] text-white/30 italic leading-relaxed">
                                {{ field.hint }}
                            </p>
                        </label>
                        <div class="sm:col-span-2 flex justify-end">
                            <button
                                type="button"
                                class="px-5 py-2.5 rounded-xl border text-[10px] font-black uppercase tracking-widest transition-colors disabled:opacity-40"
                                :class="dirty(feature)
                                    ? 'border-cyan-500/40 text-cyan-300 bg-cyan-500/10'
                                    : 'border-white/10 text-white/35'"
                                :disabled="!feature.enabled || !dirty(feature) || busy[feature.key]"
                                @click="saveSettings(feature)"
                            >
                                Сохранить настройки
                            </button>
                        </div>
                    </div>
                </article>
            </section>
        </div>
    </AdminLayout>
</template>
