<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

type DocItem = {
    title: string
    description: string
    path: string | null
    audience: string
}

type DocSection = {
    id: string
    title: string
    items: DocItem[]
}

const props = defineProps<{
    sections: DocSection[]
}>()

const query = ref('')
const activeSection = ref<string>('all')

/** id → раскрыт. По умолчанию все свёрнуты. */
const openMap = ref<Record<string, boolean>>({})

const ensureDefaults = () => {
    const next: Record<string, boolean> = { ...openMap.value }
    for (const s of props.sections) {
        if (!(s.id in next)) next[s.id] = false
    }
    openMap.value = next
}
ensureDefaults()
watch(() => props.sections.map(s => s.id).join(','), ensureDefaults)

const filtered = computed(() => {
    const q = query.value.trim().toLowerCase()
    return props.sections
        .filter(s => activeSection.value === 'all' || s.id === activeSection.value)
        .map(s => ({
            ...s,
            items: s.items.filter(item => {
                if (!q) return true
                return (
                    item.title.toLowerCase().includes(q)
                    || item.description.toLowerCase().includes(q)
                    || item.audience.toLowerCase().includes(q)
                    || (item.path || '').toLowerCase().includes(q)
                )
            }),
        }))
        .filter(s => s.items.length > 0)
})

const totalItems = computed(() =>
    props.sections.reduce((n, s) => n + s.items.length, 0)
)

const isOpen = (id: string) => Boolean(openMap.value[id])

const toggle = (id: string) => {
    openMap.value = { ...openMap.value, [id]: !openMap.value[id] }
}

const expandAll = () => {
    const next: Record<string, boolean> = {}
    for (const s of filtered.value) next[s.id] = true
    openMap.value = { ...openMap.value, ...next }
}

const collapseAll = () => {
    const next: Record<string, boolean> = {}
    for (const s of props.sections) next[s.id] = false
    openMap.value = next
}

// Поиск / фильтр раздела — автоматически раскрываем подходящие блоки.
watch([query, activeSection, filtered], () => {
    const q = query.value.trim()
    if (q) {
        const next = { ...openMap.value }
        for (const s of filtered.value) next[s.id] = true
        openMap.value = next
        return
    }
    if (activeSection.value !== 'all') {
        openMap.value = { ...openMap.value, [activeSection.value]: true }
    }
})
</script>

<template>
    <Head title="REACTOR | О системе" />
    <AdminLayout>
        <div class="max-w-5xl mx-auto space-y-6 animate-in fade-in duration-500 font-mono pb-20 px-4">

            <div class="bg-[#0a0a0a] border border-white/5 p-8 rounded-[1rem] shadow-2xl space-y-6">
                <div>
                    <h1 class="text-3xl font-black uppercase italic text-white tracking-tighter">
                        О <span class="text-[#22c55e]">системе</span>
                    </h1>
                    <p class="text-white/25 text-[10px] uppercase tracking-[0.35em] font-black mt-2 italic">
                        Справочник функций Reactor · {{ totalItems }} модулей · разделы свёрнуты
                    </p>
                </div>

                <div class="flex flex-col md:flex-row gap-3">
                    <input
                        v-model="query"
                        type="search"
                        placeholder="Поиск: транзакции, чек, вентилятор, TV shell…"
                        class="flex-1 bg-black border border-white/10 rounded-2xl px-5 py-4 text-sm text-white placeholder:text-white/20 outline-none focus:border-[#22c55e]/40"
                    />
                    <select
                        v-model="activeSection"
                        class="bg-black border border-white/10 rounded-2xl px-5 py-4 text-[11px] font-black uppercase tracking-widest text-white/70 outline-none focus:border-[#22c55e]/40"
                    >
                        <option value="all">Все разделы</option>
                        <option v-for="s in sections" :key="s.id" :value="s.id">{{ s.title }}</option>
                    </select>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="px-4 py-2 rounded-xl border border-white/10 text-[10px] font-black uppercase tracking-widest text-white/40 hover:text-white hover:border-white/25 transition-colors"
                        @click="expandAll"
                    >
                        Развернуть всё
                    </button>
                    <button
                        type="button"
                        class="px-4 py-2 rounded-xl border border-white/10 text-[10px] font-black uppercase tracking-widest text-white/40 hover:text-white hover:border-white/25 transition-colors"
                        @click="collapseAll"
                    >
                        Свернуть всё
                    </button>
                </div>
            </div>

            <div v-if="filtered.length === 0" class="py-16 text-center border border-dashed border-white/10 rounded-[1rem] text-white/30 text-xs uppercase tracking-widest">
                Ничего не найдено
            </div>

            <section
                v-for="section in filtered"
                :key="section.id"
                class="bg-[#0a0a0a] border border-white/5 rounded-[1rem] overflow-hidden"
            >
                <button
                    type="button"
                    class="w-full flex items-center gap-3 px-5 py-4 text-left hover:bg-white/[0.02] transition-colors"
                    :aria-expanded="isOpen(section.id)"
                    @click="toggle(section.id)"
                >
                    <span
                        class="text-[#22c55e] text-xs font-black w-4 tabular-nums transition-transform duration-200"
                        :class="isOpen(section.id) ? 'rotate-90' : ''"
                        aria-hidden="true"
                    >›</span>
                    <h2 class="text-[11px] font-black uppercase tracking-[0.35em] text-cyan-400/80 italic flex-1">
                        {{ section.title }}
                    </h2>
                    <span class="text-[9px] font-black text-white/25 tabular-nums">{{ section.items.length }}</span>
                </button>

                <div v-show="isOpen(section.id)" class="px-4 pb-4 space-y-3 border-t border-white/5 pt-4">
                    <article
                        v-for="item in section.items"
                        :key="item.title"
                        class="bg-black/40 border border-white/5 rounded-[0.75rem] p-6 hover:border-white/10 transition-colors"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-3 mb-3">
                            <h3 class="text-sm font-black uppercase italic tracking-tight text-white">
                                {{ item.title }}
                            </h3>
                            <span class="text-[9px] font-black uppercase tracking-widest px-3 py-1 rounded-full border border-white/10 text-white/35">
                                {{ item.audience }}
                            </span>
                        </div>
                        <p class="text-[12px] leading-relaxed text-white/45 whitespace-pre-line">
                            {{ item.description }}
                        </p>
                        <div v-if="item.path" class="mt-4">
                            <Link
                                v-if="item.path.startsWith('/admin') || item.path.startsWith('/legal') || item.path.startsWith('/account')"
                                :href="item.path"
                                class="inline-flex items-center gap-2 text-[10px] font-black uppercase tracking-widest text-[#22c55e]/80 hover:text-[#22c55e] transition-colors"
                            >
                                {{ item.path }}
                                <span aria-hidden="true">→</span>
                            </Link>
                            <span v-else class="text-[10px] font-mono text-white/25">{{ item.path }}</span>
                        </div>
                    </article>
                </div>
            </section>
        </div>
    </AdminLayout>
</template>
