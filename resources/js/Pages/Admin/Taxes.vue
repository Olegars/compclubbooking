<script setup lang="ts">
import { computed } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useClubName } from '@/Composables/useClubName'

type Quarter = {
    gross: number
    vat: number
    net: number
    cumulative_gross: number
    cumulative_net: number
    cumulative_vat: number
    usn_raw: number
    deduction: number
    deduction_cap: number
    deduction_pool: number
    usn_cumulative: number
    usn_advance: number
    payroll: {
        gross: number
        ndfl: number
        employer: number
        injury: number
        employer_total: number
    }
    deadline: string
    vat_deadline: string
}

type EmployeeRow = {
    id: number
    name: string
    role: string
    gross: number
    ndfl: number
    net: number
    employer: number
    injury: number
}

const props = defineProps<{
    year: number
    profile: {
        entity: string
        regime: string
        usn_rate_percent: number
        has_employees: boolean
        vat_mode: string
        injury_rate_percent: number
    }
    rates: {
        ip_fixed: number
        ip_extra_max: number
        vat_exempt: number
        vat_5_until: number
        vat_7_until: number
        usn_limit: number
        employer_base: number
        standard_vat: number
    }
    income: {
        gross: number
        net: number
        vat: number
        prior_year_gross: number
        months: Record<number, number>
    }
    vat: {
        exempt: boolean
        from_month: number | null
        rate: number
        mode: string
        input_vat_deductible: boolean
        year_vat: number
        year_net: number
        reason: string
    }
    quarters: Record<number, Quarter>
    premiums: {
        fixed: number
        extra: number
        total: number
        fixed_deadline: string
        extra_deadline: string
    }
    payroll: {
        employee_count: number
        employees: EmployeeRow[]
        year: {
            gross: number
            ndfl: number
            employer: number
            injury: number
            employer_total: number
        }
    }
    totals: {
        usn: number
        vat: number
        ip_premiums: number
        employer_contributions: number
        ndfl: number
        injury: number
        all: number
    }
    warnings: Array<{ level: string, text: string }>
}>()

const clubName = useClubName()

const formatRuble = (value: number) =>
    new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', maximumFractionDigits: 0 }).format(value || 0)

const formatDay = (iso: string) => {
    const [y, m, d] = iso.split('-')
    return `${d}.${m}.${y}`
}

const monthNames = ['янв', 'фев', 'мар', 'апр', 'май', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек']

const vatLabel = computed(() => {
    if (props.vat.exempt) return 'освобождение'
    return `${props.vat.rate} %`
})

const setYear = (year: number) => {
    router.get('/admin/taxes', { year }, { preserveState: false, preserveScroll: true })
}
</script>

<template>
    <Head :title="`${clubName} | Налоги`" />
    <AdminLayout>
        <div class="max-w-7xl mx-auto space-y-8 animate-in fade-in duration-500 font-mono pb-20 px-4">

            <div class="flex flex-col md:flex-row md:justify-between md:items-end gap-6 border-b border-white/10 pb-6">
                <div>
                    <h1 class="text-3xl font-black uppercase italic text-white tracking-tighter">
                        Tax <span class="text-indigo-500">Engine</span>
                    </h1>
                    <p class="text-white/20 text-[10px] uppercase tracking-[0.4em] font-black mt-2 italic">
                        {{ profile.entity }} · {{ profile.regime }} {{ profile.usn_rate_percent }}% · наёмные · НДС
                    </p>
                    <div class="flex flex-wrap gap-2 mt-4">
                        <span class="px-3 py-1 rounded-full border border-white/10 text-[10px] uppercase tracking-widest text-white/50">ИП</span>
                        <span class="px-3 py-1 rounded-full border border-indigo-500/30 text-[10px] uppercase tracking-widest text-indigo-400">УСН 6%</span>
                        <span class="px-3 py-1 rounded-full border border-amber-500/30 text-[10px] uppercase tracking-widest text-amber-400">Вычет взносов ≤ 50%</span>
                        <span class="px-3 py-1 rounded-full border border-cyan-500/30 text-[10px] uppercase tracking-widest text-cyan-400">НДС {{ vatLabel }}</span>
                    </div>
                </div>
                <div class="flex items-center gap-4">
                    <button
                        type="button"
                        class="px-4 py-3 rounded-2xl border border-white/10 text-white/60 hover:text-white hover:border-white/30 text-xs font-black"
                        @click="setYear(year - 1)"
                    >←</button>
                    <div class="text-4xl font-black text-white italic">
                        FY <span class="text-indigo-500">{{ year }}</span>
                    </div>
                    <button
                        type="button"
                        class="px-4 py-3 rounded-2xl border border-white/10 text-white/60 hover:text-white hover:border-white/30 text-xs font-black"
                        @click="setYear(year + 1)"
                    >→</button>
                </div>
            </div>

            <div v-if="warnings.length" class="space-y-2">
                <div
                    v-for="(item, idx) in warnings"
                    :key="idx"
                    class="rounded-2xl px-5 py-4 text-xs leading-relaxed"
                    :class="item.level === 'warn' ? 'bg-amber-500/10 border border-amber-500/30 text-amber-200' : 'bg-cyan-500/10 border border-cyan-500/20 text-cyan-100/80'"
                >
                    {{ item.text }}
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
                <div class="bg-[#050505] border border-white/5 p-6 rounded-[0.875rem]">
                    <div class="text-[10px] text-white/30 uppercase font-black tracking-widest">Валовая выручка</div>
                    <div class="text-3xl font-black text-white tracking-tighter mt-2">{{ formatRuble(income.gross) }}</div>
                    <div class="text-[10px] text-white/30 mt-2">Касса / СБП / карта, без бонусов</div>
                </div>
                <div class="bg-[#050505] border border-white/5 p-6 rounded-[0.875rem]">
                    <div class="text-[10px] text-white/30 uppercase font-black tracking-widest">База УСН (без НДС)</div>
                    <div class="text-3xl font-black text-white tracking-tighter mt-2">{{ formatRuble(income.net) }}</div>
                    <div class="text-[10px] text-white/30 mt-2">НДС из базы УСН исключён</div>
                </div>
                <div class="bg-[#050505] border border-cyan-500/20 p-6 rounded-[0.875rem]">
                    <div class="text-[10px] text-cyan-400 uppercase font-black tracking-widest">НДС к уплате</div>
                    <div class="text-3xl font-black text-cyan-400 tracking-tighter mt-2">{{ formatRuble(totals.vat) }}</div>
                    <div class="text-[10px] text-white/30 mt-2">{{ vat.reason }}</div>
                </div>
                <div class="bg-indigo-500 p-6 rounded-[0.875rem] text-black">
                    <div class="text-[10px] uppercase font-black tracking-widest opacity-70">УСН к уплате</div>
                    <div class="text-3xl font-black tracking-tighter mt-2">{{ formatRuble(totals.usn) }}</div>
                    <div class="text-[10px] mt-2 opacity-70">После вычета взносов, не меньше 50%</div>
                </div>
            </div>

            <div class="bg-[#0a0a0a] border border-white/5 rounded-[1.125rem] p-8 shadow-2xl">
                <h3 class="text-sm text-white/40 uppercase font-black tracking-[0.2em] mb-6 italic">Кварталы · нарастающий итог</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
                    <div v-for="q in 4" :key="q" class="bg-[#050505] border border-white/5 p-6 rounded-2xl">
                        <div class="flex justify-between items-center mb-4">
                            <span class="text-xs font-black uppercase text-white/50 tracking-widest">Q{{ q }}</span>
                            <span class="w-2 h-2 rounded-full" :class="quarters[q].gross > 0 ? 'bg-indigo-500' : 'bg-white/10'"></span>
                        </div>
                        <div class="text-white font-black text-xl mb-3">{{ formatRuble(quarters[q].gross) }}</div>
                        <dl class="space-y-1.5 text-[10px] uppercase tracking-widest text-white/35">
                            <div class="flex justify-between gap-3"><dt>НДС</dt><dd class="text-cyan-400">{{ formatRuble(quarters[q].vat) }}</dd></div>
                            <div class="flex justify-between gap-3"><dt>База УСН</dt><dd class="text-white">{{ formatRuble(quarters[q].net) }}</dd></div>
                            <div class="flex justify-between gap-3"><dt>УСН 6% (нараст.)</dt><dd class="text-white">{{ formatRuble(quarters[q].usn_raw) }}</dd></div>
                            <div class="flex justify-between gap-3"><dt>Вычет взносов</dt><dd class="text-indigo-300">{{ formatRuble(quarters[q].deduction) }}</dd></div>
                            <div class="flex justify-between gap-3"><dt>Аванс к доплате</dt><dd class="text-white font-black">{{ formatRuble(quarters[q].usn_advance) }}</dd></div>
                        </dl>
                        <div class="mt-4 pt-3 border-t border-white/5 text-[10px] text-white/30 uppercase tracking-widest">
                            УСН до {{ formatDay(quarters[q].deadline) }}
                            <span v-if="quarters[q].vat > 0"> · НДС до {{ formatDay(quarters[q].vat_deadline) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="bg-[#0a0a0a] border border-white/5 rounded-[1.125rem] p-8">
                    <h3 class="text-sm text-white/40 uppercase font-black tracking-[0.2em] mb-6 italic">НДС {{ year }}</h3>
                    <dl class="space-y-3 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-white/40">Выручка прошлого года</dt>
                            <dd class="text-white font-black">{{ formatRuble(income.prior_year_gross) }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-white/40">Порог освобождения</dt>
                            <dd class="text-white font-black">{{ formatRuble(rates.vat_exempt) }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-white/40">Ставка</dt>
                            <dd class="text-cyan-400 font-black uppercase">{{ vat.exempt ? 'не платится' : vat.rate + '%' }}</dd>
                        </div>
                        <div v-if="vat.from_month" class="flex justify-between gap-4">
                            <dt class="text-white/40">НДС с месяца</dt>
                            <dd class="text-white font-black">{{ monthNames[vat.from_month - 1] }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-white/40">5% до / 7% до</dt>
                            <dd class="text-white/70 text-right">{{ formatRuble(rates.vat_5_until) }} / {{ formatRuble(rates.vat_7_until) }}</dd>
                        </div>
                    </dl>
                    <p class="text-[11px] text-white/35 leading-relaxed mt-6">
                        Спецставки 5% и 7% — без вычета входного НДС. Общая {{ rates.standard_vat }}% с вычетом имеет смысл, если входной НДС по закупкам магазина большой.
                        Порог освобождения считается по выручке прошлого года; если в этом году он превышен — НДС со следующего месяца.
                    </p>
                </div>

                <div class="bg-[#0a0a0a] border border-white/5 rounded-[1.125rem] p-8">
                    <h3 class="text-sm text-white/40 uppercase font-black tracking-[0.2em] mb-6 italic">Взносы ИП за себя</h3>
                    <dl class="space-y-3 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-white/40">Фикс {{ year }}</dt>
                            <dd class="text-indigo-400 font-black">{{ formatRuble(premiums.fixed) }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-white/40">1% свыше 300 тыс.</dt>
                            <dd class="text-indigo-400 font-black">{{ formatRuble(premiums.extra) }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-white/40">Всего за себя</dt>
                            <dd class="text-white font-black">{{ formatRuble(premiums.total) }}</dd>
                        </div>
                    </dl>
                    <div class="mt-6 space-y-1 text-[11px] text-white/35 uppercase tracking-widest">
                        <div>Фикс до {{ formatDay(premiums.fixed_deadline) }}</div>
                        <div>1% до {{ formatDay(premiums.extra_deadline) }}</div>
                    </div>
                    <p class="text-[11px] text-white/35 leading-relaxed mt-6">
                        Фикс и 1% уменьшают УСН в том году, за который они подлежат уплате. С наёмными вычет вместе со взносами за сотрудников — не больше половины налога.
                    </p>
                </div>
            </div>

            <div class="bg-[#0a0a0a] border border-white/5 rounded-[1.125rem] p-8">
                <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-3 mb-6">
                    <h3 class="text-sm text-white/40 uppercase font-black tracking-[0.2em] italic">Наёмные · ТК РФ</h3>
                    <p class="text-[11px] text-white/30">
                        {{ payroll.employee_count }} чел. · травматизм {{ profile.injury_rate_percent }}% · предельная база {{ formatRuble(rates.employer_base) }}
                    </p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
                    <div class="bg-[#050505] border border-white/5 p-5 rounded-2xl">
                        <div class="text-[10px] text-white/30 uppercase tracking-widest">Начислено</div>
                        <div class="text-xl font-black text-white mt-2">{{ formatRuble(payroll.year.gross) }}</div>
                    </div>
                    <div class="bg-[#050505] border border-white/5 p-5 rounded-2xl">
                        <div class="text-[10px] text-white/30 uppercase tracking-widest">НДФЛ (агент)</div>
                        <div class="text-xl font-black text-white mt-2">{{ formatRuble(payroll.year.ndfl) }}</div>
                    </div>
                    <div class="bg-[#050505] border border-white/5 p-5 rounded-2xl">
                        <div class="text-[10px] text-white/30 uppercase tracking-widest">Взносы 30 / 15,1%</div>
                        <div class="text-xl font-black text-indigo-400 mt-2">{{ formatRuble(payroll.year.employer) }}</div>
                    </div>
                    <div class="bg-[#050505] border border-white/5 p-5 rounded-2xl">
                        <div class="text-[10px] text-white/30 uppercase tracking-widest">Травматизм</div>
                        <div class="text-xl font-black text-indigo-400 mt-2">{{ formatRuble(payroll.year.injury) }}</div>
                    </div>
                </div>
                <p class="text-[11px] text-white/35 leading-relaxed mb-6">
                    НДФЛ удерживается из зарплаты, в вычет УСН не идёт. В вычет идут единый тариф и взносы на травматизм по сотрудникам с отметкой «ТК РФ» и начислениями в зарплатном журнале. Неофициальные ставки в налог не попадают.
                </p>
                <div v-if="payroll.employees.length" class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="text-[10px] uppercase tracking-widest text-white/30">
                            <tr>
                                <th class="pb-3 font-black">Сотрудник</th>
                                <th class="pb-3 font-black text-right">Начислено</th>
                                <th class="pb-3 font-black text-right">НДФЛ</th>
                                <th class="pb-3 font-black text-right">На руки</th>
                                <th class="pb-3 font-black text-right">Взносы + НС</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="person in payroll.employees" :key="person.id" class="border-t border-white/5">
                                <td class="py-3 text-white font-bold">{{ person.name }}</td>
                                <td class="py-3 text-right text-white/80">{{ formatRuble(person.gross) }}</td>
                                <td class="py-3 text-right text-white/80">{{ formatRuble(person.ndfl) }}</td>
                                <td class="py-3 text-right text-white/80">{{ formatRuble(person.net) }}</td>
                                <td class="py-3 text-right text-indigo-300">{{ formatRuble(person.employer + person.injury) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="text-white/30 text-sm">Нет сотрудников с отметкой ТК РФ за этот год.</p>
            </div>

            <div class="bg-[#0a0a0a] border border-indigo-500/20 rounded-[1.125rem] p-8">
                <h3 class="text-sm text-white/40 uppercase font-black tracking-[0.2em] mb-6 italic">Нагрузка за год</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="flex justify-between md:block">
                        <div class="text-[10px] text-white/30 uppercase tracking-widest">УСН 6%</div>
                        <div class="text-2xl font-black text-white mt-1">{{ formatRuble(totals.usn) }}</div>
                    </div>
                    <div class="flex justify-between md:block">
                        <div class="text-[10px] text-white/30 uppercase tracking-widest">НДС</div>
                        <div class="text-2xl font-black text-cyan-400 mt-1">{{ formatRuble(totals.vat) }}</div>
                    </div>
                    <div class="flex justify-between md:block">
                        <div class="text-[10px] text-white/30 uppercase tracking-widest">ИП за себя</div>
                        <div class="text-2xl font-black text-indigo-400 mt-1">{{ formatRuble(totals.ip_premiums) }}</div>
                    </div>
                    <div class="flex justify-between md:block">
                        <div class="text-[10px] text-white/30 uppercase tracking-widest">Взносы за штат</div>
                        <div class="text-2xl font-black text-white mt-1">{{ formatRuble(totals.employer_contributions) }}</div>
                    </div>
                    <div class="flex justify-between md:block">
                        <div class="text-[10px] text-white/30 uppercase tracking-widest">НДФЛ за штат</div>
                        <div class="text-2xl font-black text-white mt-1">{{ formatRuble(totals.ndfl) }}</div>
                    </div>
                    <div class="flex justify-between md:block">
                        <div class="text-[10px] text-white/30 uppercase tracking-widest">Всего к бюджету</div>
                        <div class="text-2xl font-black text-indigo-300 mt-1">{{ formatRuble(totals.all) }}</div>
                    </div>
                </div>
            </div>

        </div>
    </AdminLayout>
</template>

<style scoped>
.animate-in { animation: fade-in 0.4s ease-out forwards; }
@keyframes fade-in {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>
