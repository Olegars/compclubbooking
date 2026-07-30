<script setup lang="ts">
import { computed, ref } from 'vue'
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
</script>

<template>
    <Head title="REACTOR | О системе" />
    <AdminLayout>
        <div class="max-w-5xl mx-auto space-y-8 animate-in fade-in duration-500 font-mono pb-20 px-4">

            <div class="bg-[#0a0a0a] border border-white/5 p-8 rounded-[2.5rem] shadow-2xl space-y-6">
                <div>
                    <h1 class="text-3xl font-black uppercase italic text-white tracking-tighter">
                        О <span class="text-[#22c55e]">системе</span>
                    </h1>
                    <p class="text-white/25 text-[10px] uppercase tracking-[0.35em] font-black mt-2 italic">
                        Справочник функций Reactor · {{ totalItems }} модулей
                    </p>
                </div>

                <div class="flex flex-col md:flex-row gap-3">
                    <input
                        v-model="query"
                        type="search"
                        placeholder="Поиск: магазин, сессия, SOS…"
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
            </div>

            <div v-if="filtered.length === 0" class="py-16 text-center border border-dashed border-white/10 rounded-[2.5rem] text-white/30 text-xs uppercase tracking-widest">
                Ничего не найдено
            </div>

            <section v-for="section in filtered" :key="section.id" class="space-y-4">
                <div class="flex items-center gap-3 px-2">
                    <h2 class="text-[11px] font-black uppercase tracking-[0.35em] text-cyan-400/80 italic">
                        {{ section.title }}
                    </h2>
                    <div class="h-px flex-1 bg-white/5"></div>
                    <span class="text-[9px] font-black text-white/20 tabular-nums">{{ section.items.length }}</span>
                </div>

                <div class="space-y-3">
                    <article
                        v-for="item in section.items"
                        :key="item.title"
                        class="bg-[#0a0a0a] border border-white/5 rounded-[1.75rem] p-6 hover:border-white/10 transition-colors"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-3 mb-3">
                            <h3 class="text-sm font-black uppercase italic tracking-tight text-white">
                                {{ item.title }}
                            </h3>
                            <span class="text-[9px] font-black uppercase tracking-widest px-3 py-1 rounded-full border border-white/10 text-white/35">
                                {{ item.audience }}
                            </span>
                        </div>
                        <p class="text-[12px] leading-relaxed text-white/45">
                            {{ item.description }}
                        </p>
                        <div v-if="item.path" class="mt-4">
                            <Link
                                v-if="item.path.startsWith('/admin')"
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
