<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { usePage, Link, router } from '@inertiajs/vue3'
import axios from 'axios'
import MainLayout from '@/Layouts/MainLayout.vue'
import PaymentModal from '@/Components/PaymentModal.vue'

const page = usePage()

// Данные из пропсов Inertia (источник истины)
const user = computed(() => page.props.auth?.user)
const transactions = computed(() => page.props.transactions || [])

// Локальный реф для баланса, чтобы обновлять его мгновенно через axios
const gizmo = ref((page.props.gizmo as any) || { balance: 0, spent_total: 0 })

// Мониторинг активной сессии
const activeSession = ref({ isActive: false, pcName: '—', timeLeft: '00:00', zone: '—' })
let pollingInterval: ReturnType<typeof setInterval> | null = null

const fetchActiveSession = async () => {
    try {
        const { data } = await axios.get('/api/gizmo/profile')
        if (data && data.active_session) {
            activeSession.value = {
                isActive: true,
                pcName: data.active_session.host_name || '—',
                timeLeft: data.active_session.time_left || '00:00',
                zone: data.active_session.group_name || '—'
            }
        } else {
            activeSession.value.isActive = false
        }
    } catch (e) {
        console.error('Ошибка мониторинга сессии:', e)
    }
}

// --- ЛОГИКА ПОПОЛНЕНИЯ БАЛАНСА ---
const isTopUpInputOpen = ref(false)
const isPaymentProcessing = ref(false)
const topUpAmount = ref(500)
const paymentData = ref({})
const quickAmounts = [300, 500, 1000, 2000, 5000]

const startTopUp = () => {
    topUpAmount.value = 500
    isTopUpInputOpen.value = true
}

const proceedToPayment = async () => {
    if (topUpAmount.value < 100) return alert('Минимальная сумма 100 руб.')

    isTopUpInputOpen.value = false
    isPaymentProcessing.value = true
    paymentData.value = {
        mode: 'topup',
        price: topUpAmount.value,
        date: new Date().toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' })
    }

    try {
        const response = await axios.post('/api/billing/topup', { amount: topUpAmount.value })

        // Обновляем баланс в UI
        gizmo.value.balance = response.data.new_balance

        // СИНХРОНИЗАЦИЯ: Обновляем только список транзакций через Inertia
        router.reload({ only: ['transactions'] })

    } catch (e) {
        console.error('Ошибка платежа:', e)
        alert('Сбой транзакции.')
        isPaymentProcessing.value = false
    }
}

// --- ЛОГИКА БЫСТРОГО СТАРТА ---
const isQuickStartOpen = ref(false)
const quickStartPc = ref('')
const quickStartMinutes = ref(60)
const quickStartCost = computed(() => (quickStartMinutes.value / 60) * 100)

const proceedToQuickStart = async () => {
    if (!quickStartPc.value) return alert('Укажите номер ПК')
    if (gizmo.value.balance < quickStartCost.value) return alert('Недостаточно средств!')

    isQuickStartOpen.value = false
    isPaymentProcessing.value = true
    paymentData.value = {
        mode: 'booking',
        pcNumber: `ПК №${quickStartPc.value}`,
        price: quickStartCost.value,
        date: new Date().toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' })
    }

    try {
        const response = await axios.post('/api/billing/start-session', {
            hostId: parseInt(quickStartPc.value),
            minutes: quickStartMinutes.value,
            price: quickStartCost.value
        })

        gizmo.value.balance = response.data.new_balance

        // СИНХРОНИЗАЦИЯ: Обновляем историю операций и данные баланса
        router.reload({ only: ['transactions', 'gizmo'] })

        fetchActiveSession()
    } catch (e: any) {
        alert(e.response?.data?.message || 'Ошибка запуска.')
        isPaymentProcessing.value = false
    }
}

onMounted(() => {
    fetchActiveSession()
    pollingInterval = setInterval(fetchActiveSession, 60000)
})

onUnmounted(() => { if (pollingInterval) clearInterval(pollingInterval) })
</script>

<template>
    <MainLayout>
        <div class="max-w-5xl mx-auto w-full grid grid-cols-1 md:grid-cols-3 gap-6 animate-in zoom-in duration-500">

            <div class="md:col-span-2 space-y-6">

                <div class="bg-[#0a0a0a] border border-[#22c55e]/20 rounded-[2.5rem] p-8 relative overflow-hidden group shadow-2xl">
                    <div class="absolute top-0 right-0 p-4 opacity-5 group-hover:opacity-10 transition-opacity">
                        <svg class="w-32 h-32 text-[#22c55e]" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 14h-2v-2h2v2zm0-4h-2V7h2v5z"/></svg>
                    </div>

                    <span class="text-[10px] uppercase text-[#22c55e] tracking-[0.4em] font-black italic">Доступные средства</span>
                    <div class="mt-2 flex items-baseline gap-3">
                        <span class="text-7xl font-black italic tracking-tighter text-white drop-shadow-[0_0_15px_rgba(34,197,94,0.3)]">
                            {{ Number(gizmo.balance).toFixed(0) }}
                        </span>
                        <span class="text-2xl font-bold text-[#22c55e] uppercase">RUB</span>
                    </div>

                    <div class="mt-8 flex flex-wrap gap-4">
                        <button @click="startTopUp" class="px-8 py-4 bg-[#22c55e] text-black font-black rounded-2xl uppercase text-xs tracking-widest hover:bg-[#1ea34d] transition-all shadow-[0_0_20px_rgba(34,197,94,0.3)] active:scale-95 cursor-pointer">
                            Пополнить
                        </button>

                        <button @click="isQuickStartOpen = true" class="px-8 py-4 bg-white/5 border border-[#22c55e]/40 text-[#22c55e] font-black rounded-2xl uppercase text-xs tracking-widest hover:bg-[#22c55e]/10 transition-all flex items-center gap-3 active:scale-95 cursor-pointer">
                            <span class="flex h-2 w-2 relative">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-[#22c55e] opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-2 w-2 bg-[#22c55e]"></span>
                            </span>
                            Сесть за ПК
                        </button>

                        <Link href="/booking" class="px-8 py-4 bg-white/5 border border-white/10 text-white font-black rounded-2xl uppercase text-xs tracking-widest hover:bg-white/10 transition-all active:scale-95">
                            Бронь
                        </Link>
                    </div>
                </div>

                <div class="bg-[#0a0a0a] border border-white/5 rounded-[2.5rem] p-8 flex justify-between items-center relative overflow-hidden transition-all duration-700"
                     :class="{'border-[#22c55e]/50 shadow-[0_0_40px_rgba(34,197,94,0.15)] bg-gradient-to-r from-black to-[#22c55e]/5': activeSession.isActive}">
                    <div class="z-10">
                        <span class="text-[10px] uppercase text-white/30 tracking-[0.4em] font-black italic">Статус узла</span>
                        <div class="mt-2 text-3xl font-black uppercase italic" :class="activeSession.isActive ? 'text-[#22c55e]' : 'text-white/60'">
                            {{ activeSession.isActive ? `ПК №${activeSession.pcName}` : 'Нет активных сессий' }}
                        </div>
                        <div v-if="activeSession.isActive" class="mt-2 inline-flex items-center px-3 py-1 bg-[#22c55e]/10 border border-[#22c55e]/20 rounded-full text-[10px] font-black text-[#22c55e] uppercase tracking-widest">
                            {{ activeSession.zone }}
                        </div>
                    </div>

                    <div v-if="activeSession.isActive" class="text-right z-10">
                        <div class="text-5xl font-mono font-black text-[#22c55e] tracking-tighter animate-pulse">{{ activeSession.timeLeft }}</div>
                        <span class="text-[9px] text-white/20 uppercase tracking-[0.2em] font-black italic">Остаточное время</span>
                    </div>

                    <div class="absolute inset-0 opacity-[0.03] pointer-events-none bg-[url('https://www.transparenttextures.com/patterns/carbon-fibre.png')]"></div>
                </div>

                <div class="bg-[#0a0a0a] border border-white/5 rounded-[2.5rem] p-8 relative overflow-hidden shadow-xl">
                    <div class="flex justify-between items-center mb-8">
                        <span class="text-[10px] uppercase text-white/40 tracking-[0.4em] font-black italic">История транзакций</span>
                        <div class="h-[1px] flex-grow mx-6 bg-white/5"></div>
                    </div>

                    <div v-if="transactions.length > 0" class="space-y-5">
                        <div v-for="tx in transactions" :key="tx.id" class="flex items-center justify-between group transition-all">
                            <div class="flex items-center gap-5">
                                <div class="w-10 h-10 rounded-xl flex items-center justify-center border transition-all duration-300"
                                     :class="tx.amount > 0 ? 'bg-[#22c55e]/5 border-[#22c55e]/20 text-[#22c55e] shadow-[0_0_15px_rgba(34,197,94,0.1)]' : 'bg-white/5 border-white/10 text-white/30'">
                                    <svg v-if="tx.amount > 0" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 6v12m6-6H6"/></svg>
                                    <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M18 12H6"/></svg>
                                </div>

                                <div>
                                    <div class="text-xs font-black text-white uppercase tracking-tight italic">{{ tx.description }}</div>
                                    <div class="text-[9px] text-white/20 font-mono mt-0.5">{{ tx.date }}</div>
                                </div>
                            </div>

                            <div class="text-right">
                                <div class="text-base font-black italic font-mono tracking-tighter"
                                     :class="tx.amount > 0 ? 'text-[#22c55e]' : 'text-white/50'">
                                    {{ tx.amount > 0 ? '+' : '' }}{{ tx.amount }} ₽
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-else class="py-12 text-center border border-dashed border-white/5 rounded-[2rem]">
                        <p class="text-[10px] text-white/10 uppercase tracking-[0.4em] font-black italic">История пуста</p>
                    </div>

                    <div class="absolute -bottom-6 -right-6 text-[80px] font-black text-white/[0.02] select-none pointer-events-none italic uppercase">
                        Protocol
                    </div>
                </div>
            </div>

            <div class="space-y-6">
                <div class="bg-[#0a0a0a] border border-white/5 rounded-[2.5rem] p-8 flex flex-col items-center text-center shadow-xl">
                    <div class="w-28 h-28 rounded-full border-2 border-[#22c55e] p-1.5 mb-5 shadow-[0_0_25px_rgba(34,197,94,0.2)]">
                        <div class="w-full h-full rounded-full bg-gradient-to-br from-[#22c55e]/30 to-black flex items-center justify-center text-4xl font-black text-[#22c55e] italic">
                            {{ user?.name?.[0]?.toUpperCase() || 'R' }}
                        </div>
                    </div>
                    <h3 class="text-2xl font-black uppercase italic tracking-tight text-white">{{ user?.name }}</h3>
                    <span class="text-[10px] text-[#22c55e] font-black tracking-[0.3em] uppercase mt-2 px-3 py-1 bg-[#22c55e]/10 rounded-full italic">Ранг: Сталкер</span>

                    <div class="w-full h-1.5 bg-white/5 rounded-full mt-8 overflow-hidden">
                        <div class="w-2/3 h-full bg-[#22c55e] shadow-[0_0_15px_#22c55e]"></div>
                    </div>
                    <div class="flex justify-between w-full mt-3">
                        <span class="text-[9px] text-white/20 uppercase font-black italic">Lvl 11</span>
                        <span class="text-[9px] text-white/20 uppercase font-black italic">2450 / 3000 XP</span>
                    </div>
                </div>

                <nav class="flex flex-col gap-3">
                    <Link href="/account/profile" class="p-5 bg-white/5 border border-white/5 rounded-3xl flex items-center justify-between hover:bg-white/10 transition-all group cursor-pointer">
                        <span class="text-xs font-black uppercase tracking-widest text-white/50 group-hover:text-white transition-colors">Настройки аккаунта</span>
                        <svg class="w-5 h-5 text-[#22c55e]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                    </Link>
                    <button class="p-5 bg-white/5 border border-white/5 rounded-3xl flex items-center justify-between hover:bg-red-500/10 transition-all group text-left cursor-pointer active:scale-95">
                        <span class="text-xs font-black uppercase tracking-widest text-white/50 group-hover:text-red-500 transition-colors">Завершить все сеансы</span>
                        <svg class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    </button>
                </nav>
            </div>
        </div>

        <Teleport to="body">
            <div v-if="isTopUpInputOpen" class="fixed inset-0 flex items-center justify-center z-[9999900] p-4">
                <div class="absolute inset-0 bg-black/95 backdrop-blur-2xl" @click="isTopUpInputOpen = false"></div>
                <div class="relative w-full max-w-md bg-[#0a0a0a] border border-[#22c55e]/30 rounded-[3rem] p-10 shadow-[0_0_100px_rgba(34,197,94,0.2)]">
                    <h2 class="text-[#22c55e] text-3xl font-black uppercase italic tracking-tighter mb-2 text-center">Reactor Pay</h2>
                    <p class="text-white/30 text-[10px] uppercase tracking-[0.3em] text-center mb-10 italic">Авторизация пополнения</p>

                    <div class="relative mb-8">
                        <input v-model="topUpAmount" type="number" min="100" class="w-full bg-black border-2 border-white/5 rounded-[2rem] py-8 px-6 text-5xl font-black font-mono text-white text-center focus:border-[#22c55e] outline-none transition-all shadow-inner" />
                        <span class="absolute right-8 top-1/2 -translate-y-1/2 text-3xl font-black text-[#22c55e] italic">₽</span>
                    </div>

                    <div class="grid grid-cols-3 gap-3 mb-10">
                        <button v-for="amt in quickAmounts" :key="amt" @click="topUpAmount = amt"
                                class="py-4 bg-white/5 border border-white/10 rounded-2xl text-white/60 font-black uppercase text-[11px] tracking-widest hover:bg-[#22c55e]/10 hover:text-[#22c55e] hover:border-[#22c55e]/40 transition-all active:scale-95">
                            {{ amt }}
                        </button>
                    </div>

                    <button @click="proceedToPayment" class="w-full py-6 bg-[#22c55e] hover:bg-[#2ae06d] rounded-[2rem] text-black font-black uppercase text-sm tracking-widest active:scale-95 transition-all shadow-[0_0_30px_rgba(34,197,94,0.3)] italic">
                        Подтвердить платеж
                    </button>
                    <button @click="isTopUpInputOpen = false" class="w-full py-5 text-white/20 hover:text-white uppercase text-[10px] font-black tracking-[0.5em] transition-all mt-4 italic">
                        [ Отмена ]
                    </button>
                </div>
            </div>

            <div v-if="isQuickStartOpen" class="fixed inset-0 flex items-center justify-center z-[9999900] p-4">
                <div class="absolute inset-0 bg-black/95 backdrop-blur-2xl" @click="isQuickStartOpen = false"></div>
                <div class="relative w-full max-w-md bg-[#0a0a0a] border border-[#22c55e]/30 rounded-[3rem] p-10 shadow-[0_0_100px_rgba(34,197,94,0.2)]">
                    <h2 class="text-[#22c55e] text-3xl font-black uppercase italic tracking-tighter mb-2 text-center leading-none">Подключение<br>к узлу</h2>
                    <p class="text-white/30 text-[10px] uppercase tracking-[0.3em] text-center mb-8 italic">Выбор терминала</p>

                    <div class="relative mb-8 text-center">
                        <span class="text-[10px] text-white/20 font-black uppercase mb-2 block tracking-widest italic">Номер компьютера</span>
                        <input v-model="quickStartPc" type="number" placeholder="42" class="w-full bg-black border-2 border-white/5 rounded-[2rem] py-8 px-6 text-6xl font-black font-mono text-center text-[#22c55e] focus:border-[#22c55e] outline-none transition-all placeholder:text-white/5" />
                    </div>

                    <div class="flex bg-black border border-white/5 rounded-[2rem] p-2 mb-8">
                        <button v-for="m in [60, 120, 180, 300]" :key="m" @click="quickStartMinutes = m"
                                class="flex-1 py-4 rounded-2xl font-black text-xs transition-all italic uppercase tracking-tighter"
                                :class="quickStartMinutes === m ? 'bg-[#22c55e] text-black shadow-[0_0_20px_rgba(34,197,94,0.4)]' : 'text-white/30 hover:text-white'">
                            {{ m }} <span class="text-[8px]">мин</span>
                        </button>
                    </div>

                    <div class="flex justify-between items-center mb-10 px-4 border-t border-white/5 pt-6">
                        <span class="text-[11px] text-white/40 uppercase tracking-widest font-black italic">Стоимость аренды:</span>
                        <span class="text-2xl font-black text-[#22c55e] italic">{{ quickStartCost }} ₽</span>
                    </div>

                    <button @click="proceedToQuickStart" class="w-full py-6 bg-[#22c55e] hover:bg-[#2ae06d] rounded-[2rem] text-black font-black uppercase text-sm tracking-widest active:scale-95 transition-all shadow-[0_0_30px_rgba(34,197,94,0.3)] italic">
                        Активировать ПК
                    </button>
                    <button @click="isQuickStartOpen = false" class="w-full py-5 text-white/20 hover:text-white uppercase text-[10px] font-black tracking-[0.5em] transition-all mt-4 italic">
                        [ Отмена ]
                    </button>
                </div>
            </div>

            <PaymentModal
                v-if="isPaymentProcessing"
                :is-open="isPaymentProcessing"
                :mode="paymentData.mode"
                :data="paymentData"
                @close="isPaymentProcessing = false"
            />
        </Teleport>
    </MainLayout>
</template>

<style scoped>
@reference "../../../css/app.css";
.animate-in { animation: zoom-in 0.5s cubic-bezier(0.16, 1, 0.3, 1); }
@keyframes zoom-in {
    from { opacity: 0; transform: scale(0.95) translateY(20px); }
    to { opacity: 1; transform: scale(1) translateY(0); }
}
input[type=number]::-webkit-inner-spin-button,
input[type=number]::-webkit-outer-spin-button {
    -webkit-appearance: none;
    margin: 0;
}
</style>
