<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const props = defineProps<{
    year: number,
    total_income: number,
    quarters: Record<number, any>,
    premiums: { fixed: number, extra: number, total: number }
}>()

// Функция форматирования валюты
const formatRuble = (value: number) => {
    return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', maximumFractionDigits: 0 }).format(value);
}

// Расчет итогового налога (Налог УСН минус страховые взносы)
const totalTaxRaw = props.total_income * 0.06;
const taxToPay = Math.max(0, totalTaxRaw - props.premiums.total);
</script>

<template>
    <Head title="REACTOR | Налоги" />
    <AdminLayout>
        <div class="max-w-7xl mx-auto space-y-8 animate-in fade-in duration-500 font-mono pb-20 px-4">

            <div class="flex justify-between items-end mb-10 border-b border-white/10 pb-6">
                <div>
                    <h1 class="text-3xl font-black uppercase italic text-white tracking-tighter">Tax <span class="text-indigo-500">Engine</span></h1>
                    <p class="text-white/20 text-[10px] uppercase tracking-[0.4em] font-black mt-2 italic">Налоговый калькулятор УСН 6%</p>
                </div>
                <div class="text-4xl font-black text-white italic">
                    FY <span class="text-indigo-500">{{ year }}</span>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="bg-[#050505] border border-white/5 p-8 rounded-[2rem] shadow-xl relative overflow-hidden">
                    <div class="text-[10px] text-white/30 uppercase font-black tracking-widest mb-2">Валовый доход</div>
                    <div class="text-4xl font-black text-white tracking-tighter">{{ formatRuble(total_income) }}</div>
                </div>

                <div class="bg-[#050505] border border-indigo-500/20 p-8 rounded-[2rem] shadow-xl relative overflow-hidden">
                    <div class="text-[10px] text-indigo-500 uppercase font-black tracking-widest mb-2">Страховые взносы (Фикс + 1%)</div>
                    <div class="text-4xl font-black text-indigo-500 tracking-tighter">{{ formatRuble(premiums.total) }}</div>
                    <div class="text-xs text-indigo-500/50 mt-2">Вычитаются из налога УСН</div>
                </div>

                <div class="bg-indigo-500 p-8 rounded-[2rem] shadow-[0_0_40px_rgba(99,102,241,0.3)] relative overflow-hidden text-black">
                    <div class="text-[10px] uppercase font-black tracking-widest mb-2 opacity-70">Налог к уплате (С учетом вычетов)</div>
                    <div class="text-4xl font-black tracking-tighter">{{ formatRuble(taxToPay) }}</div>
                </div>
            </div>

            <div class="bg-[#0a0a0a] border border-white/5 rounded-[3rem] p-10 mt-8 shadow-2xl">
                <h3 class="text-sm text-white/40 uppercase font-black tracking-[0.2em] mb-8 italic">Квартальные авансы</h3>

                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div v-for="q in 4" :key="q" class="bg-[#050505] border border-white/5 p-6 rounded-2xl group hover:border-white/20 transition-all">
                        <div class="flex justify-between items-center mb-4">
                            <span class="text-xs font-black uppercase text-white/50 tracking-widest">Q{{ q }}</span>
                            <span class="w-2 h-2 rounded-full" :class="quarters[q].income > 0 ? 'bg-indigo-500' : 'bg-white/10'"></span>
                        </div>
                        <div class="text-white font-black text-xl mb-1">{{ formatRuble(quarters[q].income) }}</div>
                        <div class="text-[10px] text-white/30 uppercase tracking-widest">Налог 6%: <span class="text-white">{{ formatRuble(quarters[q].tax_raw) }}</span></div>
                    </div>
                </div>

                <div class="mt-10 pt-10 border-t border-white/5 text-right">
                    <button class="px-8 py-5 bg-white/5 hover:bg-indigo-500 hover:text-black border border-white/10 hover:border-indigo-500 text-white rounded-2xl text-xs font-black uppercase tracking-[0.2em] transition-all italic active:scale-95">
                        Сгенерировать КУДиР (.xlsx)
                    </button>
                </div>
            </div>

        </div>
    </AdminLayout>
</template>
