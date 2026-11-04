<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useClubName } from '@/Composables/useClubName'

type Point = { at: string, load_pct: number }

type MemoryRow = {
    name: string
    hostname?: string | null
    load_pct: number | null
    used_mb: number | null
    total_mb: number | null
    available_mb: number | null
    reported_at: string | null
    stale: boolean
    series: Point[]
}

type Station = MemoryRow & {
    id: number
    space: string | null
    power_state: string | null
}

type Summary = {
    pcs: number
    reporting: number
    hot: number
    avg_load: number | null
    peak_load: number | null
    peak_name: string | null
}

const props = defineProps<{
    location: { id: number, name: string, type: string | null } | null
    stations: Station[]
    host: MemoryRow | null
    summary: Summary
    updated_at: string
    chart_hours: number
    stale_seconds: number
}>()

const clubName = useClubName()
const selectedId = ref<number | null>(null)
let timer: ReturnType<typeof setInterval> | null = null

const selected = computed(() => props.stations.find((row) => row.id === selectedId.value) ?? props.stations[0] ?? null)

watch(() => props.stations, (rows) => {
    if (!rows.some((row) => row.id === selectedId.value))
        selectedId.value = rows[0]?.id ?? null
}, { immediate: true })

onMounted(() => {
    timer = setInterval(() => {
        router.reload({
            only: ['stations', 'host', 'summary', 'updated_at'],
            preserveState: true,
            preserveScroll: true,
        })
    }, 15000)
})

onUnmounted(() => {
    if (timer) clearInterval(timer)
})

const gb = (mb: number | null | undefined) => {
    if (mb == null) return '—'
    return (mb / 1024).toFixed(1) + ' ГБ'
}

const ago = (iso: string | null) => {
    if (!iso) return 'нет отчёта'
    const sec = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 1000))
    if (Number.isNaN(sec)) return 'нет отчёта'
    if (sec < 60) return `${sec} с назад`
    const min = Math.round(sec / 60)
    if (min < 60) return `${min} мин назад`
    return `${Math.round(min / 60)} ч назад`
}

const tone = (row: MemoryRow) => {
    if (row.load_pct == null || row.stale) return 'text-white/35'
    if (row.load_pct >= 90) return 'text-red-400'
    if (row.load_pct >= 75) return 'text-amber-300'
    return 'text-emerald-300'
}

const bar = (row: MemoryRow) => {
    if (row.load_pct == null || row.stale) return 'bg-white/15'
    if (row.load_pct >= 90) return 'bg-red-500'
    if (row.load_pct >= 75) return 'bg-amber-400'
    return 'bg-emerald-400'
}

const spark = (series: Point[], width: number, height: number) => {
    if (!series.length) return ''
    const yOf = (load: number) => height - (Math.max(0, Math.min(100, load)) / 100) * (height - 2) - 1
    if (series.length === 1) {
        const y = yOf(series[0].load_pct)
        return `M0,${y.toFixed(1)} L${width},${y.toFixed(1)}`
    }
    return series.map((point, index) => {
        const x = (index / (series.length - 1)) * width
        const y = yOf(point.load_pct)
        return `${index === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`
    }).join(' ')
}

const clock = (iso: string) => {
    const date = new Date(iso)
    if (Number.isNaN(date.getTime())) return ''
    return date.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
}
</script>

<template>
    <Head :title="`${clubName} | Память`" />
    <AdminLayout>
        <div class="max-w-7xl mx-auto space-y-8 animate-in fade-in duration-500 font-mono pb-20 px-4">
            <div class="flex flex-col lg:flex-row lg:items-end justify-between gap-6 border-b border-white/10 pb-6">
                <div>
                    <h1 class="text-3xl font-black uppercase italic text-white tracking-tighter">
                        Память <span class="text-yellow-400">ПК и сервера</span>
                    </h1>
                    <p class="text-white/20 text-[10px] uppercase tracking-[0.4em] font-black mt-2 italic">
                        Только владелец · текущая локация
                        <span v-if="location"> · {{ location.name }}</span>
                    </p>
                    <p class="text-white/40 text-xs font-bold mt-3 max-w-2xl">
                        Шелл снимает RAM игровых ПК, служба на Windows Server — RAM самого сервера. Интервал около 30 секунд.
                        График — последние {{ chart_hours }} часа, старше {{ stale_seconds }} секунд считаем устаревшим.
                        История хранится сутки.
                    </p>
                </div>
                <div class="text-[10px] uppercase tracking-widest text-white/30 font-black">
                    Обновлено {{ ago(updated_at) }}
                </div>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div class="bg-[#0a0a0a] border border-white/10 rounded-2xl px-5 py-4">
                    <div class="text-[10px] text-white/30 uppercase font-black tracking-widest">Мест</div>
                    <div class="text-white text-2xl font-black mt-1">{{ summary.pcs }}</div>
                </div>
                <div class="bg-[#0a0a0a] border border-emerald-500/20 rounded-2xl px-5 py-4">
                    <div class="text-[10px] text-emerald-500/70 uppercase font-black tracking-widest">На связи</div>
                    <div class="text-emerald-300 text-2xl font-black mt-1">{{ summary.reporting }}</div>
                </div>
                <div class="bg-[#0a0a0a] border border-white/10 rounded-2xl px-5 py-4">
                    <div class="text-[10px] text-white/30 uppercase font-black tracking-widest">Средняя</div>
                    <div class="text-white text-2xl font-black mt-1">{{ summary.avg_load == null ? '—' : summary.avg_load + '%' }}</div>
                </div>
                <div class="bg-[#0a0a0a] border border-red-500/20 rounded-2xl px-5 py-4">
                    <div class="text-[10px] text-red-400/70 uppercase font-black tracking-widest">Пик · ≥85%</div>
                    <div class="text-red-300 text-2xl font-black mt-1">{{ summary.hot }}</div>
                    <div class="text-[10px] text-white/35 mt-1 font-bold truncate">
                        {{ summary.peak_name ? `${summary.peak_name} · ${summary.peak_load}%` : 'нет свежих отчётов' }}
                    </div>
                </div>
            </div>

            <section class="bg-[#0a0a0a] border rounded-2xl px-5 py-4"
                     :class="host && !host.stale ? 'border-yellow-500/40' : 'border-white/10'">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="text-[10px] text-yellow-400/80 uppercase font-black tracking-widest">Windows Server</div>
                        <div class="text-white font-black uppercase tracking-wide mt-1">Сервер</div>
                        <div class="text-[10px] text-white/35 uppercase tracking-widest mt-1 truncate">
                            {{ host?.hostname || 'служба ещё не писала в базу' }}
                            <span v-if="host?.stale"> · нет свежих данных</span>
                        </div>
                    </div>
                    <div class="text-3xl font-black tabular-nums" :class="host ? tone(host) : 'text-white/35'">
                        {{ host?.load_pct == null ? '—' : host.load_pct + '%' }}
                    </div>
                </div>
                <div class="mt-3 h-1.5 rounded-full bg-white/5 overflow-hidden">
                    <div class="h-full rounded-full" :class="host ? bar(host) : 'bg-white/15'" :style="{ width: (host?.load_pct ?? 0) + '%' }"></div>
                </div>
                <div class="mt-3 flex items-end justify-between gap-3">
                    <div class="text-[11px] text-white/45">
                        {{ gb(host?.used_mb) }} / {{ gb(host?.total_mb) }}
                        <div class="text-[10px] text-white/25 mt-1">свободно {{ gb(host?.available_mb) }} · {{ ago(host?.reported_at ?? null) }}</div>
                    </div>
                    <svg v-if="host && host.series.length" viewBox="0 0 220 36" class="w-56 h-9 text-yellow-300/80">
                        <path :d="spark(host.series, 220, 36)" fill="none" stroke="currentColor" stroke-width="1.6" />
                    </svg>
                </div>
            </section>

            <div v-if="stations.length === 0" class="bg-[#0a0a0a] border border-white/10 rounded-2xl px-6 py-10 text-white/40 text-sm">
                В этой локации нет игровых ПК.
            </div>

            <div v-else class="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_22rem] gap-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <button v-for="row in stations"
                            :key="row.id"
                            type="button"
                            class="text-left bg-[#0a0a0a] border rounded-2xl px-5 py-4 transition-colors"
                            :class="selected?.id === row.id ? 'border-yellow-500/50' : 'border-white/10 hover:border-white/25'"
                            @click="selectedId = row.id">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="text-white font-black uppercase tracking-wide truncate">{{ row.name }}</div>
                                <div class="text-[10px] text-white/35 uppercase tracking-widest mt-1 truncate">
                                    {{ row.space || 'без комнаты' }}
                                    <span v-if="row.stale"> · нет свежих данных</span>
                                </div>
                            </div>
                            <div class="text-2xl font-black tabular-nums" :class="tone(row)">
                                {{ row.load_pct == null ? '—' : row.load_pct + '%' }}
                            </div>
                        </div>
                        <div class="mt-3 h-1.5 rounded-full bg-white/5 overflow-hidden">
                            <div class="h-full rounded-full" :class="bar(row)" :style="{ width: (row.load_pct ?? 0) + '%' }"></div>
                        </div>
                        <div class="mt-3 flex items-end justify-between gap-3">
                            <div class="text-[11px] text-white/45">
                                {{ gb(row.used_mb) }} / {{ gb(row.total_mb) }}
                                <div class="text-[10px] text-white/25 mt-1">{{ ago(row.reported_at) }}</div>
                            </div>
                            <svg v-if="row.series.length" viewBox="0 0 140 36" class="w-36 h-9 text-yellow-300/80">
                                <path :d="spark(row.series, 140, 36)" fill="none" stroke="currentColor" stroke-width="1.6" />
                            </svg>
                        </div>
                    </button>
                </div>

                <aside v-if="selected" class="bg-[#0a0a0a] border border-white/10 rounded-2xl p-5 h-fit xl:sticky xl:top-6">
                    <div class="text-[10px] text-white/30 uppercase font-black tracking-widest">Выбрано</div>
                    <div class="text-white text-xl font-black uppercase mt-1">{{ selected.name }}</div>
                    <div class="text-[11px] text-white/40 mt-1">{{ selected.space || 'без комнаты' }}</div>
                    <div class="text-5xl font-black mt-4 tabular-nums" :class="tone(selected)">
                        {{ selected.load_pct == null ? '—' : selected.load_pct + '%' }}
                    </div>
                    <div class="mt-4 space-y-1 text-xs text-white/55">
                        <div>Занято {{ gb(selected.used_mb) }}</div>
                        <div>Свободно {{ gb(selected.available_mb) }}</div>
                        <div>Всего {{ gb(selected.total_mb) }}</div>
                        <div class="text-white/30">{{ ago(selected.reported_at) }}</div>
                    </div>
                    <svg v-if="selected.series.length" viewBox="0 0 320 110" class="w-full h-28 mt-5 text-yellow-300">
                        <path :d="spark(selected.series, 320, 110)" fill="none" stroke="currentColor" stroke-width="2" />
                    </svg>
                    <div v-else class="text-white/30 text-xs mt-5">За последние {{ chart_hours }} часа точек нет.</div>
                    <div v-if="selected.series.length >= 2" class="flex justify-between text-[10px] text-white/25 mt-1">
                        <span>{{ clock(selected.series[0].at) }}</span>
                        <span>{{ clock(selected.series[selected.series.length - 1].at) }}</span>
                    </div>
                </aside>
            </div>
        </div>
    </AdminLayout>
</template>
