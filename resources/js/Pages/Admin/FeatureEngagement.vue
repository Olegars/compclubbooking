<script setup lang="ts">
import { ref } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useClubName } from '@/Composables/useClubName'

const clubName = useClubName()

type Trend = { direction: 'up' | 'down' | 'flat', percent: number }
type Row = {
    key: string
    title: string
    icon: string
    category: string
    actions: number
    unique_users: number
    unique_stations: number
    reach_percent: number
    avg_per_session: number
    trend: Trend
    repeat_percent: number
    bar_percent?: number
}

const props = defineProps<{
    preset: string
    category: string
    search: string
    from: string
    to: string
    categories: Record<string, string>
    audience: number
    rows: Row[]
    top: Row[]
    retention: Array<{ key: string, title: string, icon: string, repeat_percent: number, unique_users: number }>
    dead: Array<{ key: string, title: string, icon: string, club_feature: string }>
}>()

const q = ref(props.search)
const customFrom = ref(props.from)
const customTo = ref(props.to)

const presets = [
    { id: 'today', label: 'Сегодня' },
    { id: 'yesterday', label: 'Вчера' },
    { id: '7d', label: '7 дней' },
    { id: '30d', label: '30 дней' },
]

const load = (extra: Record<string, string | undefined>) => {
    router.get('/admin/analytics/features', {
        preset: props.preset,
        category: props.category,
        q: q.value || undefined,
        from: props.preset === 'custom' ? customFrom.value : undefined,
        to: props.preset === 'custom' ? customTo.value : undefined,
        ...extra,
    }, { preserveState: true, replace: true })
}

const trendMark = (trend: Trend) => {
    if (trend.direction === 'up') return '↑'
    if (trend.direction === 'down') return '↓'
    return '→'
}

const trendClass = (trend: Trend) => {
    if (trend.direction === 'up') return 'text-emerald-400'
    if (trend.direction === 'down') return 'text-rose-400'
    return 'text-white/30'
}
</script>

<template>
    <Head :title="`${clubName} | Использование фич`" />
    <AdminLayout>
        <div class="max-w-7xl mx-auto space-y-8 animate-in fade-in duration-500 font-mono pb-20 px-4">
            <div class="bg-[#0a0a0a] border border-white/5 p-8 rounded-[1rem] shadow-2xl flex flex-col gap-6">
                <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-6">
                    <div>
                        <h1 class="text-3xl font-black uppercase italic tracking-tighter text-white">
                            Использование <span class="text-[#22c55e]">фич</span>
                        </h1>
                        <p class="text-white/25 text-[10px] uppercase tracking-[0.35em] font-black mt-2 italic">
                            Только осознанные действия гостя · {{ from }} — {{ to }} · гостей в зале {{ audience }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="p in presets" :key="p.id" type="button"
                            @click="load({ preset: p.id, from: undefined, to: undefined })"
                            class="px-5 py-3 rounded-xl text-[10px] font-black uppercase tracking-widest border transition-all"
                            :class="preset === p.id
                                ? 'bg-[#22c55e]/20 border-[#22c55e] text-[#22c55e]'
                                : 'bg-black border-white/10 text-white/40 hover:border-white/30'"
                        >
                            {{ p.label }}
                        </button>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <input v-model="customFrom" type="date"
                           class="bg-black border border-white/10 rounded-xl px-3 py-2 text-[11px] text-white/70" />
                    <input v-model="customTo" type="date"
                           class="bg-black border border-white/10 rounded-xl px-3 py-2 text-[11px] text-white/70" />
                    <button type="button" @click="load({ preset: 'custom', from: customFrom, to: customTo })"
                            class="px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest border border-white/10 text-white/50 hover:border-white/30">
                        Диапазон
                    </button>
                    <input v-model="q" type="search" placeholder="Поиск фичи"
                           class="bg-black border border-white/10 rounded-xl px-3 py-2 text-[11px] text-white/80 min-w-[180px]"
                           @keyup.enter="load({})" />
                    <button type="button" @click="load({})"
                            class="px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest border border-white/10 text-white/50 hover:border-white/30">
                        Найти
                    </button>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <Link href="/admin/analytics"
                      class="px-5 py-3 rounded-xl text-[10px] font-black uppercase tracking-widest border bg-black border-white/10 text-white/40">
                    Бизнес-аналитика
                </Link>
                <button
                    v-for="(label, id) in categories" :key="id" type="button"
                    @click="load({ category: id })"
                    class="px-5 py-3 rounded-xl text-[10px] font-black uppercase tracking-widest border transition-all"
                    :class="category === id ? 'bg-cyan-500/20 border-cyan-500 text-cyan-300' : 'bg-black border-white/10 text-white/40'"
                >
                    {{ label }}
                </button>
            </div>

            <div v-if="dead.length" class="bg-rose-500/5 border border-rose-500/20 rounded-[0.875rem] p-6">
                <h2 class="text-[11px] font-black uppercase tracking-[0.3em] text-rose-300 italic">
                    Мёртвые зоны · 14 дней без кликов
                </h2>
                <p class="text-[10px] text-white/35 mt-2 uppercase tracking-widest">
                    Фича включена в конфигурации, гости её не трогали
                </p>
                <div class="mt-4 flex flex-wrap gap-2">
                    <span v-for="item in dead" :key="item.key"
                          class="px-3 py-2 rounded-xl border border-rose-500/30 text-[11px] text-rose-200">
                        {{ item.icon }} {{ item.title }}
                    </span>
                </div>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
                <div class="bg-[#0a0a0a] border border-white/5 rounded-[0.875rem] p-6">
                    <h2 class="text-[11px] font-black uppercase tracking-[0.3em] text-cyan-400/80 italic mb-4">
                        Топ-5 по использованиям
                    </h2>
                    <div v-if="top.length" class="space-y-3">
                        <div v-for="row in top" :key="row.key">
                            <div class="flex items-center justify-between text-[11px] mb-1">
                                <span class="text-white/80">{{ row.icon }} {{ row.title }}</span>
                                <span class="text-white/40 tabular-nums">{{ row.actions }}</span>
                            </div>
                            <div class="h-1.5 rounded-full bg-white/5 overflow-hidden">
                                <div class="h-full rounded-full bg-[#22c55e]" :style="{ width: `${row.bar_percent || 0}%` }"></div>
                            </div>
                        </div>
                    </div>
                    <div v-else class="text-[11px] text-white/30 uppercase tracking-widest">Пока нет действий</div>
                </div>
                <div class="bg-[#0a0a0a] border border-white/5 rounded-[0.875rem] p-6">
                    <h2 class="text-[11px] font-black uppercase tracking-[0.3em] text-cyan-400/80 italic mb-4">
                        Повтор в другие дни
                    </h2>
                    <p class="text-[10px] text-white/30 uppercase tracking-widest mb-4">
                        Доля гостей, которые вернулись к фиче в другой день периода
                    </p>
                    <div v-if="retention.length" class="space-y-3">
                        <div v-for="row in retention" :key="row.key">
                            <div class="flex items-center justify-between text-[11px] mb-1">
                                <span class="text-white/80">{{ row.icon }} {{ row.title }}</span>
                                <span class="text-white/40 tabular-nums">{{ row.repeat_percent }}% · {{ row.unique_users }} гостей</span>
                            </div>
                            <div class="h-1.5 rounded-full bg-white/5 overflow-hidden">
                                <div class="h-full rounded-full bg-cyan-400" :style="{ width: `${Math.min(100, row.repeat_percent)}%` }"></div>
                            </div>
                        </div>
                    </div>
                    <div v-else class="text-[11px] text-white/30 uppercase tracking-widest">Нет данных за период</div>
                </div>
            </div>

            <div class="bg-[#0a0a0a] border border-white/5 rounded-[0.875rem] overflow-x-auto">
                <table class="w-full min-w-[760px] text-left">
                    <thead>
                        <tr class="text-[9px] uppercase tracking-widest text-white/30 border-b border-white/5">
                            <th class="px-5 py-4 font-black">Функционал</th>
                            <th class="px-3 py-4 font-black">Использований</th>
                            <th class="px-3 py-4 font-black">Охват</th>
                            <th class="px-3 py-4 font-black">На сессию</th>
                            <th class="px-3 py-4 font-black">Тренд</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.key" class="border-b border-white/5 text-[12px]">
                            <td class="px-5 py-4 text-white">
                                <span class="mr-2">{{ row.icon }}</span>{{ row.title }}
                                <div class="text-[9px] text-white/25 uppercase tracking-widest mt-1">
                                    {{ row.unique_stations }} мест
                                </div>
                            </td>
                            <td class="px-3 py-4 tabular-nums text-white/80">{{ row.actions }}</td>
                            <td class="px-3 py-4 tabular-nums text-white/80">
                                {{ row.unique_users }} из {{ audience }}
                                <span class="text-white/35">({{ row.reach_percent }}%)</span>
                            </td>
                            <td class="px-3 py-4 tabular-nums text-white/80">{{ row.avg_per_session }}</td>
                            <td class="px-3 py-4 tabular-nums font-black" :class="trendClass(row.trend)">
                                {{ trendMark(row.trend) }}
                                <span v-if="row.trend.direction !== 'flat'" class="font-medium">{{ row.trend.percent }}%</span>
                            </td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td colspan="5" class="px-5 py-10 text-center text-[11px] uppercase tracking-widest text-white/30">
                                Ничего не найдено
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AdminLayout>
</template>
