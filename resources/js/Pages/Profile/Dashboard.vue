<script setup lang="ts">
import { ref, computed } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import SmsModal from '@/Components/SmsModal.vue'
import PaymentModal from '@/Components/PaymentModal.vue'

const props = defineProps<{
    user: { id: number; name: string; phone: string; gizmo_pin?: string }
    gizmo: { balance: number; bonus: number; current_pc: string; spent_time: number }
}>()

const isPaymentOpen = ref(false)
const paymentData = ref({ price: 0, date: '', pcNumber: 'REACTOR_REFILL' })

// Состояние видимости ПИН-кода
const isPinVisible = ref(false)

const openTopUp = (amount: number) => {
    paymentData.value = {
        price: amount,
        date: new Date().toLocaleDateString('ru-RU'),
        pcNumber: 'CORE_DEPOSIT'
    }
    isPaymentOpen.value = true
}

const formattedTime = computed(() => {
    const h = Math.floor(props.gizmo.spent_time / 3600)
    const m = Math.floor((props.gizmo.spent_time % 3600) / 60)
    return `${h}Ч ${m}М`
})

// Функция для выхода из системы
const logout = () => {
    router.post('/logout')
}
</script>

<template>
    <Head title="REACTOR | SYSTEM_DASHBOARD" />

    <div class="min-h-screen bg-[#020202] text-white p-4 md:p-10 relative overflow-hidden font-mono">
        <div class="fixed inset-0 pointer-events-none z-50 crt-overlay opacity-20"></div>

        <div class="max-w-6xl mx-auto space-y-10 relative z-10">

            <header class="flex flex-col md:flex-row justify-between items-start md:items-end gap-6 border-b-2 border-reactor/20 pb-8">
                <div class="space-y-2">
                    <div class="flex items-center gap-3">
                        <div class="w-3 h-3 bg-reactor rounded-full animate-ping"></div>
                        <span class="text-[10px] text-reactor font-black uppercase tracking-[0.4em] font-sans">Protocol_Active</span>
                    </div>
                    <h1 class="text-6xl font-bomber italic uppercase tracking-tighter text-glow">{{ user.name }}</h1>
                </div>

                <div class="flex flex-col items-end text-right">
                    <div class="text-[10px] text-white/30 uppercase tracking-widest font-sans font-black">Access_Token: {{ user.phone }}</div>
                    <div class="text-2xl font-black text-white/80 italic mb-2">#{{ user.id.toString().padStart(6, '0') }}</div>

                    <div class="flex items-center gap-3 bg-white/[0.03] border border-white/10 rounded-xl px-4 py-2 mt-1 group hover:border-[#22c55e]/50 transition-all cursor-pointer select-none shadow-lg"
                         @mouseenter="isPinVisible = true"
                         @mouseleave="isPinVisible = false"
                         @touchstart="isPinVisible = !isPinVisible">

                        <span class="text-[9px] uppercase tracking-widest text-slate-500 font-black italic">
                            PC LOGIN PIN:
                        </span>

                        <div class="font-bomber text-2xl tracking-[0.2em] w-[70px] text-center transition-all"
                             :class="isPinVisible ? 'text-[#22c55e] drop-shadow-[0_0_10px_rgba(34,197,94,0.5)]' : 'text-white/20'">
                            {{ isPinVisible ? (user.gizmo_pin || '----') : '••••' }}
                        </div>

                        <svg class="w-4 h-4 text-white/20 group-hover:text-[#22c55e] transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path v-if="!isPinVisible" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path v-if="!isPinVisible" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                            <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                        </svg>
                    </div>
                </div>
            </header>

            <div class="grid grid-cols-1 md:grid-cols-12 gap-8">

                <div class="md:col-span-8 bg-white/[0.02] border border-white/10 rounded-[3rem] p-10 relative overflow-hidden group border-glow">
                    <div class="absolute -right-16 -top-16 text-[15rem] font-bomber italic opacity-[0.02] pointer-events-none group-hover:opacity-[0.05] transition-opacity">CASH</div>

                    <div class="relative z-10">
                        <span class="text-[10px] uppercase text-reactor/60 tracking-[0.3em] font-black italic font-sans">// Текущий баланс</span>
                        <div class="flex items-baseline gap-4 mt-4">
                            <span class="text-9xl font-bomber italic text-reactor drop-shadow-[0_0_20px_rgba(34,197,94,0.4)]">{{ gizmo.balance }}</span>
                            <span class="text-3xl font-black text-white/20 italic">RUB</span>
                        </div>

                        <div class="mt-4 inline-block bg-reactor/10 border border-reactor/20 px-4 py-1 rounded-full">
                            <span class="text-[10px] text-reactor font-black uppercase font-sans">+ {{ gizmo.bonus }} БОНУС-КОИНОВ</span>
                        </div>

                        <div class="mt-12 grid grid-cols-2 lg:grid-cols-4 gap-4">
                            <button v-for="sum in [500, 1000, 2000, 5000]" :key="sum" @click="openTopUp(sum)"
                                    class="py-5 bg-white/5 border border-white/10 rounded-2xl text-xs font-black uppercase italic hover:bg-reactor hover:text-black transition-all hover:scale-105 active:scale-95 shadow-lg font-sans">
                                +{{ sum }} ₽
                            </button>
                        </div>
                    </div>
                </div>

                <div class="md:col-span-4 bg-white/[0.02] border border-white/10 rounded-[3rem] p-10 flex flex-col justify-between border-glow relative">
                    <div class="space-y-8">
                        <span class="text-[10px] uppercase text-white/30 tracking-[0.3em] font-black italic font-sans">// Активный узел</span>
                        <div class="space-y-2">
                            <div class="text-7xl font-bomber italic text-white/90 leading-none">{{ gizmo.current_pc }}</div>
                            <div class="text-[10px] text-reactor font-bold uppercase tracking-widest font-sans">Status: Pro_Zone_Member</div>
                        </div>
                    </div>

                    <div class="pt-8 border-t border-white/5 space-y-4">
                        <div class="flex justify-between items-end">
                            <span class="text-[9px] uppercase text-white/40 italic font-black font-sans">Время в сети</span>
                            <span class="text-2xl font-black italic text-reactor">{{ formattedTime }}</span>
                        </div>
                        <div class="h-1.5 bg-white/5 rounded-full overflow-hidden">
                            <div class="h-full bg-reactor w-2/3 shadow-[0_0_10px_#22c55e]"></div>
                        </div>
                    </div>
                </div>

                <div class="md:col-span-12 grid grid-cols-1 md:grid-cols-3 gap-6">
                    <button class="flex items-center justify-between p-8 bg-white/5 border border-white/10 rounded-[2rem] hover:border-reactor/40 transition-all group">
                        <span class="text-sm font-black uppercase italic tracking-widest font-sans">История транзакций</span>
                        <span class="text-2xl text-reactor group-hover:translate-x-2 transition-transform font-sans">→</span>
                    </button>
                    <button class="flex items-center justify-between p-8 bg-white/5 border border-white/10 rounded-[2rem] hover:border-reactor/40 transition-all group">
                        <span class="text-sm font-black uppercase italic tracking-widest font-sans">Сменить тариф</span>
                        <span class="text-2xl text-reactor group-hover:translate-x-2 transition-transform font-sans">→</span>
                    </button>

                    <button @click="logout" class="flex items-center justify-between p-8 bg-red-500/5 border border-red-500/20 rounded-[2rem] hover:bg-red-500 hover:text-white transition-all group cursor-pointer">
                        <span class="text-sm font-black uppercase italic tracking-widest text-red-500 group-hover:text-white font-sans">Выход из системы</span>
                        <span class="text-2xl group-hover:translate-x-2 transition-transform font-sans">⏻</span>
                    </button>
                </div>
            </div>
        </div>

        <PaymentModal :is-open="isPaymentOpen" mode="topup" :data="paymentData" @close="isPaymentOpen = false" />
    </div>
</template>

<style>
.font-bomber { font-family: 'BomberEscort', sans-serif; }
::-webkit-scrollbar { width: 5px; }
::-webkit-scrollbar-track { background: #020202; }
::-webkit-scrollbar-thumb { background: rgba(34, 197, 94, 0.2); border-radius: 10px; }
::-webkit-scrollbar-thumb:hover { background: #22c55e; }
</style>
