<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const props = defineProps<{
    staff: any[]
}>()

const formatMoney = (val: number | string | null) => {
    if (!val) return '—'
    return Number(val).toLocaleString('ru-RU') + ' ₽'
}

const roleClass = (role: string) => {
    if (role === 'owner') return 'bg-purple-500/10 text-purple-500 border-purple-500/30'
    if (role === 'supervisor') return 'bg-blue-500/10 text-blue-500 border-blue-500/30'
    if (role === 'admin') return 'bg-[#22c55e]/10 text-[#22c55e] border-[#22c55e]/30'
    if (role === 'senior_manager') return 'bg-amber-500/10 text-amber-400 border-amber-500/30'
    if (role === 'store_manager') return 'bg-orange-500/10 text-orange-400 border-orange-500/30'
    if (role === 'assembler') return 'bg-cyan-500/10 text-cyan-400 border-cyan-500/30'
    return 'bg-white/5 text-white/50 border-white/10'
}
</script>

<template>
    <Head title="REACTOR | Штат" />
    <AdminLayout>
        <div class="max-w-7xl mx-auto space-y-8 animate-in fade-in duration-500 font-mono pb-20 px-4">

            <div class="flex justify-between items-end mb-10 border-b border-white/10 pb-6">
                <div>
                    <h1 class="text-3xl font-black uppercase italic text-white tracking-tighter">Staff <span class="text-purple-500">Directory</span></h1>
                    <p class="text-white/20 text-[10px] uppercase tracking-[0.4em] font-black mt-2 italic">Управление персоналом и ставками</p>
                </div>
                <button disabled
                        title="Найм через панель пока недоступен — сотрудники добавляются через сидер / БД"
                        class="px-6 py-4 bg-white/5 border border-white/10 text-white/20 font-black uppercase tracking-widest text-[10px] rounded-2xl cursor-not-allowed">
                    + Нанять сотрудника <span class="opacity-60">(скоро)</span>
                </button>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <div v-for="person in staff" :key="person.id"
                     class="bg-[#050505] border border-white/5 rounded-[0.875rem] p-8 relative group hover:border-purple-500/30 transition-all shadow-xl">

                    <div class="absolute top-6 right-6 text-[9px] uppercase font-black tracking-widest px-3 py-1 rounded-full border"
                         :class="roleClass(person.role)">
                        {{ person.role }}
                    </div>

                    <div class="flex items-center gap-4 mb-8">
                        <div class="w-14 h-14 rounded-2xl bg-white/5 border border-white/10 flex items-center justify-center text-xl font-black text-white group-hover:bg-purple-500/20 group-hover:text-purple-500 transition-colors">
                            {{ person.name.charAt(0) }}
                        </div>
                        <div>
                            <div class="text-white font-black uppercase tracking-tight text-lg">{{ person.name }}</div>
                            <div class="text-[10px] text-white/30 mt-1">{{ person.email }}</div>
                        </div>
                    </div>

                    <div class="space-y-4 pt-6 border-t border-white/5">
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-white/30 uppercase font-black tracking-widest text-[9px]">Локация</span>
                            <span class="text-white font-bold text-[11px]">{{ person.club_name || (person.role === 'owner' ? 'Все' : '—') }}</span>
                        </div>
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-white/30 uppercase font-black tracking-widest text-[9px]">Оформление</span>
                            <span class="text-white font-bold text-[11px]">{{ person.is_official_employee ? 'ТК РФ' : 'ИП / Неофициально' }}</span>
                        </div>
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-white/30 uppercase font-black tracking-widest text-[9px]">Ставка</span>
                            <span class="text-white font-black">{{ formatMoney(person.base_rate) }}</span>
                        </div>
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-white/30 uppercase font-black tracking-widest text-[9px]">Тип оплаты</span>
                            <span class="text-white font-black uppercase text-[11px]">
                                {{ person.pay_type === 'shift' ? 'За смену' : (person.pay_type === 'monthly' ? 'Оклад' : '—') }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </AdminLayout>
</template>
