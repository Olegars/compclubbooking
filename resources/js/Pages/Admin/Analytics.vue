<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

type UtilZone = {
    id: number
    name: string
    color?: string | null
    pc_count: number
    utilization_percent: number
    occupied_hours: number
    available_hours: number
}

type HeatCell = { weekday: number, hour: number, rate: number }
type HeatBlock = { zone_id: number, zone_name: string, cells: HeatCell[] }

const props = defineProps<{
    days: number
    zone_id: number | null
    utilization: {
        from: string
        to: string
        zones: UtilZone[]
        heatmap: HeatBlock[]
        weekday_labels: string[]
    }
    cohorts: {
        cohorts: Array<{
            month: string
            label: string
            new_users: number
            returned: number
            return_rate: number
            retention_m1: number
            retention_m2: number
            spend_total: number
            avg_ltv: number
        }>
        vip: Array<{
            user_id: number
            name: string
            phone?: string | null
            ltv: number
            cash_in: number
            share_percent: number
        }>
        summary: {
            total_spend: number
            vip_count: number
            vip_spend: number
            vip_share_percent: number
            users_in_window: number
        }
    }
    inventory: {
        from: string
        to: string
        products: Array<{
            product_id: number | null
            name: string
            revenue: number
            qty: number
            stock: number | null
            abc: string
            xyz: string
            class: string
            cv: number
            revenue_share: number
        }>
        summary: {
            total_revenue: number
            sku_count: number
            abc: Record<string, number>
            xyz: Record<string, number>
        }
    }
}>()

const tab = ref<'util' | 'cohorts' | 'inventory'>('util')
const selectedZoneId = ref<number | null>(
    props.zone_id
    ?? props.utilization.heatmap[0]?.zone_id
    ?? null
)

const dayPresets = [7, 30, 90]

const setDays = (days: number) => {
    router.get('/admin/analytics', {
        days,
        zone_id: selectedZoneId.value || undefined,
    }, { preserveState: true, replace: true })
}

const selectZone = (id: number) => {
    selectedZoneId.value = id
}

const activeHeat = computed(() =>
    props.utilization.heatmap.find(h => h.zone_id === selectedZoneId.value)
    ?? props.utilization.heatmap[0]
    ?? null
)

const heatMatrix = computed(() => {
    const cells = activeHeat.value?.cells ?? []
    const byKey = new Map(cells.map(c => [`${c.weekday}-${c.hour}`, c.rate]))
    const rows: number[][] = []
    for (let wd = 0; wd < 7; wd++) {
        const row: number[] = []
        for (let h = 0; h < 24; h++) {
            row.push(byKey.get(`${wd}-${h}`) ?? 0)
        }
        rows.push(row)
    }
    return rows
})

const heatColor = (rate: number) => {
    if (rate <= 0) return 'rgba(255,255,255,0.03)'
    if (rate < 0.2) return 'rgba(34,197,94,0.15)'
    if (rate < 0.4) return 'rgba(34,197,94,0.35)'
    if (rate < 0.6) return 'rgba(234,179,8,0.45)'
    if (rate < 0.8) return 'rgba(249,115,22,0.55)'
    return 'rgba(239,68,68,0.65)'
}

const abcColor = (abc: string) => ({
    A: 'text-emerald-400 border-emerald-500/40',
    B: 'text-amber-400 border-amber-500/40',
    C: 'text-white/40 border-white/15',
}[abc] || 'text-white/40 border-white/15')

const money = (n: number) =>
    new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 }).format(n) + ' ₽'
</script>

<template>
    <Head title="REACTOR | Аналитика" />
    <AdminLayout>
        <div class="max-w-7xl mx-auto space-y-8 animate-in fade-in duration-500 font-mono pb-20 px-4">

            <div class="bg-[#0a0a0a] border border-white/5 p-8 rounded-[2.5rem] shadow-2xl flex flex-col lg:flex-row lg:items-center justify-between gap-6">
                <div>
                    <h1 class="text-3xl font-black uppercase italic tracking-tighter text-white">
                        Аналитика <span class="text-[#22c55e]">бизнеса</span>
                    </h1>
                    <p class="text-white/25 text-[10px] uppercase tracking-[0.35em] font-black mt-2 italic">
                        Утилизация · LTV / когорты · ABC/XYZ склада
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="d in dayPresets" :key="d" type="button" @click="setDays(d)"
                        class="px-5 py-3 rounded-xl text-[10px] font-black uppercase tracking-widest border transition-all"
                        :class="days === d
                            ? 'bg-[#22c55e]/20 border-[#22c55e] text-[#22c55e]'
                            : 'bg-black border-white/10 text-white/40 hover:border-white/30'"
                    >
                        {{ d }} дней
                    </button>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <button type="button" @click="tab = 'util'"
                        class="px-5 py-3 rounded-xl text-[10px] font-black uppercase tracking-widest border transition-all"
                        :class="tab === 'util' ? 'bg-cyan-500/20 border-cyan-500 text-cyan-300' : 'bg-black border-white/10 text-white/40'">
                    Утилизация залов
                </button>
                <button type="button" @click="tab = 'cohorts'"
                        class="px-5 py-3 rounded-xl text-[10px] font-black uppercase tracking-widest border transition-all"
                        :class="tab === 'cohorts' ? 'bg-cyan-500/20 border-cyan-500 text-cyan-300' : 'bg-black border-white/10 text-white/40'">
                    Игроки · LTV
                </button>
                <button type="button" @click="tab = 'inventory'"
                        class="px-5 py-3 rounded-xl text-[10px] font-black uppercase tracking-widest border transition-all"
                        :class="tab === 'inventory' ? 'bg-cyan-500/20 border-cyan-500 text-cyan-300' : 'bg-black border-white/10 text-white/40'">
                    Склад ABC/XYZ
                </button>
            </div>

            <!-- UTILIZATION -->
            <div v-show="tab === 'util'" class="space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                    <button
                        v-for="z in utilization.zones" :key="z.id" type="button"
                        @click="selectZone(z.id)"
                        class="text-left bg-[#0a0a0a] border rounded-[1.75rem] p-6 transition-colors"
                        :class="selectedZoneId === z.id ? 'border-cyan-500/50' : 'border-white/5 hover:border-white/15'"
                    >
                        <div class="flex items-center justify-between gap-3 mb-3">
                            <div class="text-sm font-black uppercase italic text-white truncate">{{ z.name }}</div>
                            <div class="text-2xl font-black italic tabular-nums"
                                 :class="z.utilization_percent >= 60 ? 'text-amber-400' : 'text-[#22c55e]'">
                                {{ z.utilization_percent }}%
                            </div>
                        </div>
                        <div class="text-[10px] text-white/30 uppercase tracking-widest">
                            {{ z.pc_count }} ПК · {{ Math.round(z.occupied_hours) }} ч занято
                        </div>
                        <div class="mt-3 h-1.5 rounded-full bg-white/5 overflow-hidden">
                            <div class="h-full rounded-full bg-[#22c55e]" :style="{ width: `${Math.min(100, z.utilization_percent)}%` }"></div>
                        </div>
                    </button>
                </div>

                <div v-if="activeHeat" class="bg-[#0a0a0a] border border-white/5 rounded-[2rem] p-6 overflow-x-auto">
                    <div class="flex items-center justify-between mb-4 gap-4">
                        <h2 class="text-[11px] font-black uppercase tracking-[0.35em] text-cyan-400/80 italic">
                            Heatmap · {{ activeHeat.zone_name }}
                        </h2>
                        <div class="text-[9px] text-white/25 uppercase tracking-widest">
                            {{ utilization.from }} — {{ utilization.to }}
                        </div>
                    </div>
                    <div class="min-w-[720px]">
                        <div class="grid gap-0.5" style="grid-template-columns: 36px repeat(24, minmax(0, 1fr));">
                            <div></div>
                            <div v-for="h in 24" :key="'h'+h" class="text-center text-[8px] text-white/25 font-bold py-1">
                                {{ h - 1 }}
                            </div>
                            <template v-for="(row, wd) in heatMatrix" :key="'wd'+wd">
                                <div class="text-[9px] text-white/40 font-black flex items-center">
                                    {{ utilization.weekday_labels[wd] }}
                                </div>
                                <div
                                    v-for="(rate, h) in row" :key="wd+'-'+h"
                                    class="aspect-square rounded-sm"
                                    :style="{ background: heatColor(rate) }"
                                    :title="`${utilization.weekday_labels[wd]} ${h}:00 — ${Math.round(rate * 100)}%`"
                                ></div>
                            </template>
                        </div>
                        <div class="mt-4 flex items-center gap-3 text-[9px] text-white/30 uppercase tracking-widest">
                            <span>Низкая</span>
                            <div class="flex gap-0.5">
                                <div v-for="r in [0, 0.15, 0.35, 0.55, 0.75, 0.95]" :key="r"
                                     class="w-4 h-3 rounded-sm" :style="{ background: heatColor(r) }"></div>
                            </div>
                            <span>Высокая</span>
                        </div>
                    </div>
                </div>
                <div v-else class="py-16 text-center border border-dashed border-white/10 rounded-[2rem] text-white/30 text-xs uppercase tracking-widest">
                    Нет данных по зонам (привяжите ПК к spaces)
                </div>
            </div>

            <!-- COHORTS -->
            <div v-show="tab === 'cohorts'" class="space-y-6">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="bg-[#0a0a0a] border border-white/5 rounded-[1.75rem] p-5">
                        <div class="text-[9px] uppercase text-white/30 tracking-widest font-black">Spend всего</div>
                        <div class="text-2xl font-black italic text-white mt-2">{{ money(cohorts.summary.total_spend) }}</div>
                    </div>
                    <div class="bg-[#0a0a0a] border border-white/5 rounded-[1.75rem] p-5">
                        <div class="text-[9px] uppercase text-white/30 tracking-widest font-black">VIP (топ 20%)</div>
                        <div class="text-2xl font-black italic text-amber-400 mt-2">{{ cohorts.summary.vip_count }}</div>
                    </div>
                    <div class="bg-[#0a0a0a] border border-white/5 rounded-[1.75rem] p-5">
                        <div class="text-[9px] uppercase text-white/30 tracking-widest font-black">VIP доля выручки</div>
                        <div class="text-2xl font-black italic text-[#22c55e] mt-2">{{ cohorts.summary.vip_share_percent }}%</div>
                    </div>
                    <div class="bg-[#0a0a0a] border border-white/5 rounded-[1.75rem] p-5">
                        <div class="text-[9px] uppercase text-white/30 tracking-widest font-black">Новые (6 мес)</div>
                        <div class="text-2xl font-black italic text-cyan-400 mt-2">{{ cohorts.summary.users_in_window }}</div>
                    </div>
                </div>

                <div class="bg-[#0a0a0a] border border-white/5 rounded-[2rem] overflow-hidden">
                    <div class="px-6 py-4 border-b border-white/5 text-[11px] font-black uppercase tracking-[0.3em] text-white/40 italic">
                        Когорты по месяцу регистрации
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="text-[9px] uppercase tracking-widest text-white/30">
                                <tr>
                                    <th class="px-6 py-3 font-black">Месяц</th>
                                    <th class="px-4 py-3 font-black">Новые</th>
                                    <th class="px-4 py-3 font-black">Вернулись</th>
                                    <th class="px-4 py-3 font-black">Return %</th>
                                    <th class="px-4 py-3 font-black">Ret M1</th>
                                    <th class="px-4 py-3 font-black">Ret M2</th>
                                    <th class="px-4 py-3 font-black">Avg LTV</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="c in cohorts.cohorts" :key="c.month" class="border-t border-white/5">
                                    <td class="px-6 py-4 font-black uppercase italic text-white">{{ c.label }}</td>
                                    <td class="px-4 py-4 tabular-nums text-white/70">{{ c.new_users }}</td>
                                    <td class="px-4 py-4 tabular-nums text-white/70">{{ c.returned }}</td>
                                    <td class="px-4 py-4 tabular-nums text-cyan-400">{{ c.return_rate }}%</td>
                                    <td class="px-4 py-4 tabular-nums text-white/50">{{ c.retention_m1 }}%</td>
                                    <td class="px-4 py-4 tabular-nums text-white/50">{{ c.retention_m2 }}%</td>
                                    <td class="px-4 py-4 tabular-nums text-[#22c55e]">{{ money(c.avg_ltv) }}</td>
                                </tr>
                                <tr v-if="cohorts.cohorts.length === 0">
                                    <td colspan="7" class="px-6 py-12 text-center text-white/25 uppercase tracking-widest text-[10px]">Нет регистраций за период</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="bg-[#0a0a0a] border border-white/5 rounded-[2rem] overflow-hidden">
                    <div class="px-6 py-4 border-b border-white/5 text-[11px] font-black uppercase tracking-[0.3em] text-amber-400/80 italic">
                        VIP · топ 20% LTV (бронь + магазин)
                    </div>
                    <div v-for="v in cohorts.vip" :key="v.user_id"
                         class="flex flex-wrap items-center justify-between gap-3 px-6 py-4 border-b border-white/5 last:border-0">
                        <div>
                            <div class="text-sm font-black uppercase italic text-white">{{ v.name }}</div>
                            <div class="text-[10px] text-white/30 mt-1">{{ v.phone || '—' }} · доля {{ v.share_percent }}%</div>
                        </div>
                        <div class="text-right">
                            <div class="text-lg font-black italic text-amber-400">{{ money(v.ltv) }}</div>
                            <div class="text-[9px] text-white/25 uppercase tracking-widest">cash-in {{ money(v.cash_in) }}</div>
                        </div>
                    </div>
                    <div v-if="cohorts.vip.length === 0" class="py-12 text-center text-white/25 text-[10px] uppercase tracking-widest">
                        Нет трат
                    </div>
                </div>
            </div>

            <!-- INVENTORY -->
            <div v-show="tab === 'inventory'" class="space-y-6">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="bg-[#0a0a0a] border border-white/5 rounded-[1.75rem] p-5">
                        <div class="text-[9px] uppercase text-white/30 tracking-widest font-black">Выручка бара</div>
                        <div class="text-2xl font-black italic text-white mt-2">{{ money(inventory.summary.total_revenue) }}</div>
                    </div>
                    <div class="bg-[#0a0a0a] border border-white/5 rounded-[1.75rem] p-5">
                        <div class="text-[9px] uppercase text-white/30 tracking-widest font-black">SKU</div>
                        <div class="text-2xl font-black italic text-cyan-400 mt-2">{{ inventory.summary.sku_count }}</div>
                    </div>
                    <div class="bg-[#0a0a0a] border border-white/5 rounded-[1.75rem] p-5">
                        <div class="text-[9px] uppercase text-white/30 tracking-widest font-black">ABC</div>
                        <div class="text-sm font-black italic text-white/70 mt-2">
                            A{{ inventory.summary.abc.A }} · B{{ inventory.summary.abc.B }} · C{{ inventory.summary.abc.C }}
                        </div>
                    </div>
                    <div class="bg-[#0a0a0a] border border-white/5 rounded-[1.75rem] p-5">
                        <div class="text-[9px] uppercase text-white/30 tracking-widest font-black">XYZ</div>
                        <div class="text-sm font-black italic text-white/70 mt-2">
                            X{{ inventory.summary.xyz.X }} · Y{{ inventory.summary.xyz.Y }} · Z{{ inventory.summary.xyz.Z }}
                        </div>
                    </div>
                </div>

                <div class="bg-[#0a0a0a] border border-white/5 rounded-[2rem] overflow-hidden">
                    <div class="px-6 py-4 border-b border-white/5 text-[11px] font-black uppercase tracking-[0.3em] text-white/40 italic">
                        Товары · {{ inventory.from }} — {{ inventory.to }}
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="text-[9px] uppercase tracking-widest text-white/30">
                                <tr>
                                    <th class="px-6 py-3 font-black">Товар</th>
                                    <th class="px-4 py-3 font-black">Класс</th>
                                    <th class="px-4 py-3 font-black">Выручка</th>
                                    <th class="px-4 py-3 font-black">Шт</th>
                                    <th class="px-4 py-3 font-black">Сток</th>
                                    <th class="px-4 py-3 font-black">Доля</th>
                                    <th class="px-4 py-3 font-black">CV</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="p in inventory.products" :key="(p.product_id ?? p.name)" class="border-t border-white/5">
                                    <td class="px-6 py-4 font-black uppercase italic text-white max-w-[220px] truncate">{{ p.name }}</td>
                                    <td class="px-4 py-4">
                                        <span class="px-2 py-1 rounded-lg border text-[10px] font-black" :class="abcColor(p.abc)">
                                            {{ p.class }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-4 tabular-nums text-[#22c55e]">{{ money(p.revenue) }}</td>
                                    <td class="px-4 py-4 tabular-nums text-white/60">{{ p.qty }}</td>
                                    <td class="px-4 py-4 tabular-nums text-white/40">{{ p.stock ?? '—' }}</td>
                                    <td class="px-4 py-4 tabular-nums text-white/50">{{ p.revenue_share }}%</td>
                                    <td class="px-4 py-4 tabular-nums text-white/30">{{ p.cv }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
