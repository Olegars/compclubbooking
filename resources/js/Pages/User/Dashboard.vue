<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { usePage, Link, router } from '@inertiajs/vue3'
import axios from 'axios'
import MainLayout from '@/Layouts/MainLayout.vue'
import PaymentModal from '@/Components/PaymentModal.vue'

const page = usePage()

// --- ДАННЫЕ ПОЛЬЗОВАТЕЛЯ ---
const user = computed(() => page.props.auth?.user || page.props.user || { name: 'GUEST' })

const gizmo = ref({
    balance: (page.props.gizmo as any)?.balance || 0,
    spent_total: (page.props.gizmo as any)?.spent_total || 0
})

watch(() => page.props.gizmo, (newVal: any) => {
    if (newVal) {
        gizmo.value.balance = newVal.balance
        gizmo.value.spent_total = newVal.spent_total
    }
}, { deep: true })

// --- МОНИТОРИНГ СЕССИИ И АВТООБНОВЛЕНИЕ ---
const activeSession = ref({ isActive: false, pcName: '—', timeLeft: '00:00', zone: '—' })
let pollingInterval: ReturnType<typeof setInterval> | null = null

const fetchDashboardData = async () => {
    try {
        // 1. Проверяем сессию Gizmo
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

        // 2. Синхронизируем состояние заказов и баланса (чтобы выполненные заказы исчезали)
        router.reload({
            only: ['orders', 'transactions', 'active_bookings', 'gizmo'],
            preserveScroll: true
        })

    } catch (e) {
        console.error('REACTOR Core Link Offline')
    }
}

// --- ФИЛЬТРЫ ДАННЫХ ---
const activeOrders = computed(() => {
    const orders = (page.props.orders as any[]) || [];
    return orders.filter(o => o.status === 'pending' || o.status === 'cooking');
});

const transactions = computed(() => {
    const txs = (page.props.transactions as any[]) || [];
    return [...txs].sort((a, b) => (b.id || 0) - (a.id || 0));
})

// --- ТАЙМЕР И БРОНИ ---
const currentTime = ref(Date.now())
let timerInterval: ReturnType<typeof setInterval> | null = null

const activeBookings = computed(() => {
    const rawBookings = (page.props.active_bookings as any[]) || [];
    const nowTs = currentTime.value;

    return rawBookings.map(booking => {
        try {
            const cleanDateStr = String(booking.date).split('T')[0];
            let y=0, m=0, d=0;
            if (cleanDateStr.includes('-')) [y, m, d] = cleanDateStr.split('-').map(Number);
            else if (cleanDateStr.includes('.')) [d, m, y] = cleanDateStr.split('.').map(Number);

            const startTs = new Date(y, m - 1, d, 0, 0, 0).getTime() + (Number(booking.start_time) * 3600000);
            const endTs = startTs + (Number(booking.duration) * 3600000);
            return { ...booking, _endTs: endTs, _startTs: startTs };
        } catch (e) { return { ...booking, _endTs: Infinity, _startTs: 0 }; }
    }).filter(b => nowTs < b._endTs).sort((a, b) => b.id - a.id);
});

// --- ЛОГИКА ОШИБОК И ПЛАТЕЖЕЙ ---
const customError = ref({ show: false, text: '' })
const isTopUpInputOpen = ref(false)
const isQuickStartOpen = ref(false)
const isPaymentProcessing = ref(false)
const topUpAmount = ref(500)
const quickStartPc = ref('')
const quickStartMinutes = ref(60)
const quickStartCost = computed(() => (quickStartMinutes.value / 60) * 100)
const paymentData = ref<any>({})

const showError = (text: string) => {
    customError.value = { show: true, text }
    isPaymentProcessing.value = false
}

const handleMarketClick = (e: Event) => {
    if (!activeSession.value.isActive) {
        e.preventDefault();
        showError('Заказ в магазине доступен только во время активного сеанса.');
    }
}

const proceedToPayment = async () => {
    if (topUpAmount.value < 100) return showError('Минимальная сумма 100 руб.')
    isTopUpInputOpen.value = false
    isPaymentProcessing.value = true
    paymentData.value = { mode: 'topup', price: topUpAmount.value, date: new Date().toLocaleDateString('ru-RU') }
    try {
        await axios.post('/api/billing/topup', { amount: topUpAmount.value })
        router.reload({ only: ['transactions', 'gizmo'] })
    } catch (e: any) { showError('Сбой платежного шлюза') }
}

const proceedToQuickStart = async () => {
    if (!quickStartPc.value) return showError('Укажите номер терминала.')
    if (gizmo.value.balance < quickStartCost.value) return showError('Недостаточно средств!')
    isQuickStartOpen.value = false
    isPaymentProcessing.value = true
    paymentData.value = { mode: 'booking', pcNumber: `ПК №${quickStartPc.value}`, price: quickStartCost.value, date: new Date().toLocaleDateString('ru-RU') }
    try {
        await axios.post('/api/billing/start-session', { hostId: parseInt(quickStartPc.value), minutes: quickStartMinutes.value, price: quickStartCost.value })
        fetchDashboardData()
    } catch (e: any) { showError('Ошибка запуска сессии') }
}

const getCountdown = (dateStr: string, startH: any) => {
    try {
        const cleanDateStr = String(dateStr).split('T')[0];
        let y=0, m=0, d=0;
        if (cleanDateStr.includes('-')) [y, m, d] = cleanDateStr.split('-').map(Number);
        const target = new Date(y, m - 1, d, 0, 0, 0).getTime() + (Number(startH) * 3600000);
        const diff = target - currentTime.value;
        if (diff <= 0) return { text: 'СЕАНС НАЧАТ', urgent: true };
        const hh = Math.floor(diff / 3600000);
        const mm = Math.floor((diff % 3600000) / 60000);
        return { text: hh > 0 ? `${hh}ч ${mm}м` : `${mm}м`, urgent: hh === 0 && mm < 30 };
    } catch (e) { return { text: '—', urgent: false }; }
}

onMounted(() => {
    fetchDashboardData()
    pollingInterval = setInterval(fetchDashboardData, 15000)
    timerInterval = setInterval(() => { currentTime.value = Date.now() }, 1000)
})

onUnmounted(() => {
    if (pollingInterval) clearInterval(pollingInterval)
    if (timerInterval) clearInterval(timerInterval)
})
</script>

<template>
    <MainLayout>
        <div class="max-w-6xl mx-auto w-full grid grid-cols-1 md:grid-cols-3 gap-8 animate-in zoom-in duration-500 font-mono pb-20">

            <div class="md:col-span-2 space-y-8">

                <div class="bg-[#0a0a0a] border border-[#22c55e]/20 rounded-[3rem] p-10 relative overflow-hidden group shadow-2xl">
                    <span class="text-[10px] uppercase text-[#22c55e] tracking-[0.4em] font-black italic">Доступные средства</span>
                    <div class="mt-4 flex items-baseline gap-4">
                        <span class="text-8xl font-black italic tracking-tighter text-white drop-shadow-[0_0_20px_rgba(34,197,94,0.3)]">
                            {{ Number(gizmo.balance).toFixed(0) }}
                        </span>
                        <span class="text-3xl font-bold text-[#22c55e] uppercase italic">RUB</span>
                    </div>

                    <div class="mt-10 flex flex-wrap gap-4">
                        <button @click="isTopUpInputOpen = true" class="px-8 py-5 bg-[#22c55e] text-black font-black rounded-2xl text-xs tracking-widest hover:bg-[#1ea34d] transition-all active:scale-95 shadow-[0_0_30px_rgba(34,197,94,0.3)]">ПОПОЛНИТЬ</button>
                        <button @click="isQuickStartOpen = true" class="px-8 py-5 bg-white/5 border border-[#22c55e]/40 text-[#22c55e] font-black rounded-2xl text-xs tracking-widest hover:bg-[#22c55e]/10 transition-all active:scale-95">СЕСТЬ ЗА ПК</button>
                        <Link href="/booking" class="px-8 py-5 bg-white/5 border border-white/10 text-white font-black rounded-2xl text-xs tracking-widest hover:bg-white/10 transition-all active:scale-95">БРОНЬ</Link>

                        <div class="relative group/market">
                            <div v-if="!activeSession.isActive"
                                 class="absolute bottom-full left-1/2 -translate-x-1/2 mb-4 w-64 p-4 bg-[#050505] border border-red-500/50 rounded-2xl shadow-[0_0_30px_rgba(239,68,68,0.2)] opacity-0 group-hover/market:opacity-100 pointer-events-none transition-all duration-300 translate-y-2 group-hover/market:translate-y-0 z-50">
                                <div class="absolute top-full left-1/2 -translate-x-1/2 border-8 border-transparent border-t-[#050505]"></div>
                                <div class="flex items-start gap-3">
                                    <div class="w-8 h-8 shrink-0 bg-red-500/10 rounded-lg border border-red-500/30 flex items-center justify-center">
                                        <svg class="w-4 h-4 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                    </div>
                                    <div class="text-[9px] uppercase font-black tracking-widest text-red-500 italic leading-relaxed">Заказ в магазине доступен только во время сеанса</div>
                                </div>
                            </div>
                            <Link href="/shop" @click="handleMarketClick"
                                  :class="['px-8 py-5 rounded-2xl text-xs tracking-widest transition-all flex items-center gap-2 active:scale-95 font-black border',
                                           activeSession.isActive ? 'bg-white/5 border-[#22c55e]/50 text-[#22c55e] hover:bg-[#22c55e]/10' : 'bg-white/5 border-white/5 text-white/20 cursor-default grayscale']">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
                                МАРКЕТ
                            </Link>
                        </div>
                    </div>
                </div>

                <div v-if="activeOrders.length > 0" class="space-y-4">
                    <div v-for="order in activeOrders" :key="order.id"
                         class="bg-[#0a0a0a] border border-orange-500/30 rounded-[2.5rem] p-6 flex items-center justify-between relative overflow-hidden shadow-[0_10px_30px_rgba(249,115,22,0.1)]">
                        <div class="flex items-center gap-5 z-10 relative">
                            <div class="w-12 h-12 rounded-2xl bg-orange-500/10 border border-orange-500/20 flex items-center justify-center">
                                <svg class="w-6 h-6 text-orange-500 animate-bounce" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                            </div>
                            <div>
                                <div class="text-[10px] uppercase text-orange-500 font-black tracking-widest italic">Снаряжение в пути</div>
                                <div class="text-lg font-black text-white uppercase tracking-tight">{{ order.product_name }}</div>
                            </div>
                        </div>
                        <div class="px-5 py-2 bg-orange-500/10 border border-orange-500/20 rounded-full text-[10px] font-black text-orange-500 uppercase tracking-[0.2em] z-10">В ОЧЕРЕДИ</div>
                        <div class="absolute inset-y-0 right-0 w-48 bg-gradient-to-l from-orange-500/5 to-transparent"></div>
                    </div>
                </div>

                <div v-if="activeBookings.length > 0" class="space-y-4">
                    <div v-for="booking in activeBookings" :key="booking.id"
                         class="bg-[#0a0a0a] border border-[#3b82f6]/40 rounded-[2.5rem] p-8 relative overflow-hidden shadow-[0_0_40px_rgba(59,130,246,0.1)]">
                        <div class="flex items-center gap-3 mb-6 relative z-10">
                            <span class="w-3 h-3 rounded-full bg-[#3b82f6] animate-pulse shadow-[0_0_10px_#3b82f6]"></span>
                            <span class="text-[10px] uppercase text-[#3b82f6] tracking-[0.4em] font-black italic">Узел зарезервирован</span>
                        </div>
                        <div class="grid grid-cols-2 md:grid-cols-3 gap-8 relative z-10">
                            <div>
                                <span class="block text-[10px] text-white/30 uppercase font-black mb-2 italic tracking-widest">Объект</span>
                                <div class="text-3xl font-black text-white italic tracking-tighter">ПК №{{ typeof booking.pc_ids === 'string' ? JSON.parse(booking.pc_ids).join(', ') : booking.pc_ids }}</div>
                            </div>
                            <div>
                                <span class="block text-[10px] text-white/30 uppercase font-black mb-2 italic tracking-widest">Интервал</span>
                                <div class="text-lg font-mono font-black text-white bg-white/5 px-4 py-2 rounded-xl border border-white/10 inline-block">
                                    {{ Math.floor(booking.start_time) }}:00 — {{ Math.floor(Number(booking.start_time) + Number(booking.duration)) }}:00
                                </div>
                            </div>
                            <div class="md:text-right">
                                <span class="block text-[10px] text-white/30 uppercase font-black mb-2 italic tracking-widest">До активации</span>
                                <div class="text-3xl font-mono font-black italic tracking-tighter" :class="getCountdown(booking.date, booking.start_time).urgent ? 'text-red-500 animate-pulse' : 'text-[#3b82f6]'">
                                    {{ getCountdown(booking.date, booking.start_time).text }}
                                </div>
                            </div>
                        </div>
                        <div class="absolute bottom-0 right-0 p-8 opacity-5">
                            <svg class="w-24 h-24 text-white" fill="currentColor" viewBox="0 0 24 24"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        </div>
                    </div>
                </div>

                <div v-if="activeSession.isActive || activeBookings.length === 0"
                     class="bg-[#0a0a0a] border border-white/5 rounded-[3rem] p-10 flex justify-between items-center relative transition-all duration-1000"
                     :class="{'border-[#22c55e]/50 shadow-[0_0_50px_rgba(34,197,94,0.15)] bg-gradient-to-br from-black via-black to-[#22c55e]/5': activeSession.isActive}">
                    <div class="z-10">
                        <span class="text-[10px] uppercase text-white/30 tracking-[0.4em] font-black italic">Статус текущего узла</span>
                        <div class="mt-4 text-4xl font-black uppercase italic tracking-tighter" :class="activeSession.isActive ? 'text-[#22c55e]' : 'text-white/40'">
                            {{ activeSession.isActive ? `ПК №${activeSession.pcName}` : 'Нет активных сессий' }}
                        </div>
                        <div v-if="activeSession.isActive" class="mt-4 inline-flex items-center px-4 py-2 bg-[#22c55e]/10 border border-[#22c55e]/20 rounded-full text-[10px] font-black text-[#22c55e] uppercase tracking-[0.3em] italic">{{ activeSession.zone }}</div>
                    </div>
                    <div v-if="activeSession.isActive" class="text-right z-10">
                        <div class="text-6xl font-mono font-black text-[#22c55e] tracking-tighter animate-pulse drop-shadow-[0_0_15px_rgba(34,197,94,0.4)]">
                            {{ activeSession.timeLeft }}
                        </div>
                        <span class="text-[10px] text-white/20 uppercase tracking-[0.3em] font-black italic block mt-2">Остаточное время</span>
                    </div>
                </div>

                <div class="bg-[#0a0a0a] border border-white/5 rounded-[3rem] p-10 shadow-xl">
                    <span class="text-[10px] uppercase text-white/40 tracking-[0.4em] font-black italic block mb-10">Лог финансовых операций</span>
                    <div v-if="transactions.length > 0" class="space-y-6">
                        <div v-for="tx in transactions" :key="tx.id" class="flex items-center justify-between group">
                            <div class="flex items-center gap-6">
                                <div class="w-12 h-12 rounded-2xl flex items-center justify-center border transition-all"
                                     :class="tx.amount > 0 ? 'bg-[#22c55e]/5 border-[#22c55e]/20 text-[#22c55e]' : 'bg-white/5 border-white/10 text-white/20 group-hover:text-white/40'">
                                    <svg v-if="tx.amount > 0" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 6v12m6-6H6"/></svg>
                                    <svg v-else class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M18 12H6"/></svg>
                                </div>
                                <div>
                                    <div class="text-sm font-black text-white uppercase italic tracking-tight">{{ tx.description }}</div>
                                    <div class="text-[10px] text-white/20 font-mono mt-1">{{ tx.date }}</div>
                                </div>
                            </div>
                            <div class="text-xl font-black italic font-mono" :class="tx.amount > 0 ? 'text-[#22c55e]' : 'text-white/40'">
                                {{ tx.amount > 0 ? '+' : '' }}{{ tx.amount }} ₽
                            </div>
                        </div>
                    </div>
                    <div v-else class="py-20 text-center border border-dashed border-white/5 rounded-[2.5rem] text-white/10 uppercase text-[10px] font-black tracking-[0.5em] italic">Данные транзакций отсутствуют</div>
                </div>
            </div>

            <div class="space-y-8">
                <div class="bg-[#0a0a0a] border border-white/5 rounded-[3rem] p-10 flex flex-col items-center text-center shadow-2xl relative overflow-hidden group">
                    <div class="absolute inset-0 bg-gradient-to-b from-[#22c55e]/5 to-transparent opacity-0 group-hover:opacity-100 transition-opacity"></div>

                    <div class="w-32 h-32 rounded-full border-2 border-[#22c55e] p-2 mb-6 shadow-[0_0_30px_rgba(34,197,94,0.3)] overflow-hidden relative z-10">
                        <div class="w-full h-full rounded-full bg-black flex items-center justify-center text-5xl font-black text-[#22c55e] italic overflow-hidden">
                            <img v-if="user?.avatar" :src="`/images/avatars/${user.avatar}`" class="w-full h-full object-cover" />
                            <span v-else>{{ user?.name?.[0] }}</span>
                        </div>
                    </div>

                    <div class="relative z-10">
                        <h3 class="text-3xl font-black uppercase italic tracking-tighter text-white">{{ user?.name }}</h3>
                        <div class="mt-3 px-4 py-1.5 bg-[#22c55e]/10 border border-[#22c55e]/20 rounded-full text-[10px] text-[#22c55e] font-black tracking-[0.3em] uppercase italic">Ранг: Сталкер</div>

                        <div class="mt-10 w-full space-y-3">
                            <div class="w-full h-2 bg-white/5 rounded-full overflow-hidden border border-white/5">
                                <div class="w-2/3 h-full bg-[#22c55e] shadow-[0_0_20px_#22c55e]"></div>
                            </div>
                            <div class="flex justify-between text-[10px] text-white/20 uppercase font-black italic tracking-widest">
                                <span>Lvl 11</span>
                                <span>2450 / 3000 XP</span>
                            </div>
                        </div>
                    </div>
                </div>

                <nav class="flex flex-col gap-4 relative z-10">
                    <Link href="/account/profile" class="p-6 bg-[#0a0a0a] border border-white/5 rounded-[2rem] flex items-center justify-between hover:bg-white/5 transition-all group shadow-xl">
                        <span class="text-xs font-black uppercase tracking-[0.2em] text-white/40 group-hover:text-white transition-colors italic">Параметры системы</span>
                        <svg class="w-6 h-6 text-[#22c55e] group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M9 5l7 7-7 7"/></svg>
                    </Link>
                    <button class="p-6 bg-[#0a0a0a] border border-white/5 rounded-[2rem] flex items-center justify-between hover:bg-red-500/10 hover:border-red-500/20 transition-all group active:scale-95 shadow-xl">
                        <span class="text-xs font-black uppercase tracking-[0.2em] text-white/40 group-hover:text-red-500 transition-colors italic">Завершить все сеансы</span>
                        <svg class="w-6 h-6 text-red-500 group-hover:rotate-12 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    </button>
                </nav>
            </div>
        </div>

        <Teleport to="body">
            <div v-if="customError.show" class="fixed inset-0 flex items-center justify-center z-[9999999] p-6">
                <div class="absolute inset-0 bg-black/90 backdrop-blur-xl" @click="customError.show = false"></div>
                <div class="relative w-full max-w-md bg-[#050505] border-2 border-red-500/30 rounded-[3.5rem] p-12 text-center animate-in zoom-in duration-300">
                    <div class="w-20 h-20 bg-red-500/10 border border-red-500/30 rounded-full flex items-center justify-center mx-auto mb-8 shadow-[0_0_30px_rgba(239,68,68,0.2)]">
                        <svg class="w-10 h-10 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    </div>
                    <h2 class="text-red-500 text-3xl font-black uppercase italic mb-4 tracking-tighter">Ошибка протокола</h2>
                    <p class="text-white/60 text-sm font-mono mb-10 leading-relaxed">{{ customError.text }}</p>
                    <button @click="customError.show = false" class="w-full py-6 bg-red-500/20 hover:bg-red-500 border border-red-500/50 hover:text-black rounded-2xl text-red-500 font-black uppercase transition-all italic tracking-widest shadow-xl">Принять</button>
                </div>
            </div>

            <div v-if="isTopUpInputOpen" class="fixed inset-0 flex items-center justify-center z-[9999900] p-6">
                <div class="absolute inset-0 bg-black/95 backdrop-blur-2xl" @click="isTopUpInputOpen = false"></div>
                <div class="relative w-full max-w-md bg-[#0a0a0a] border border-[#22c55e]/30 rounded-[3.5rem] p-12 shadow-[0_0_120px_rgba(34,197,94,0.2)] text-center animate-in zoom-in duration-300">
                    <h2 class="text-[#22c55e] text-4xl font-black uppercase italic mb-10 tracking-tighter">Reactor Pay</h2>
                    <div class="grid grid-cols-3 gap-3 mb-8">
                        <button v-for="amount in [100, 500, 1000, 2000, 3000, 5000]" :key="amount"
                                @click="topUpAmount = amount"
                                class="py-4 bg-white/5 border border-white/10 rounded-2xl text-white font-black hover:bg-[#22c55e]/20 hover:border-[#22c55e]/50 hover:text-[#22c55e] transition-all"
                                :class="topUpAmount === amount ? 'bg-[#22c55e]/20 border-[#22c55e]/50 !text-[#22c55e] shadow-[0_0_15px_rgba(34,197,94,0.3)]' : ''">
                            {{ amount }}
                        </button>
                    </div>
                    <input v-model="topUpAmount" type="number" class="no-spinners w-full bg-black border-2 border-white/5 rounded-[2.5rem] py-8 text-6xl font-black text-center text-white mb-10 outline-none focus:border-[#22c55e] transition-all shadow-inner" />
                    <button @click="proceedToPayment" class="w-full py-7 bg-[#22c55e] hover:bg-[#2ae06d] rounded-[2.5rem] text-black font-black uppercase tracking-[0.3em] shadow-[0_15px_40px_rgba(34,197,94,0.4)] italic active:scale-95 transition-all">Подтвердить платеж</button>
                </div>
            </div>

            <div v-if="isQuickStartOpen" class="fixed inset-0 flex items-center justify-center z-[9999900] p-6">
                <div class="absolute inset-0 bg-black/95 backdrop-blur-2xl" @click="isQuickStartOpen = false"></div>
                <div class="relative w-full max-w-md bg-[#0a0a0a] border border-[#22c55e]/30 rounded-[3.5rem] p-12 shadow-[0_0_120px_rgba(34,197,94,0.2)] text-center animate-in zoom-in duration-300">
                    <h2 class="text-[#22c55e] text-4xl font-black uppercase italic mb-4 tracking-tighter">Связь с узлом</h2>
                    <p class="text-white/30 text-[10px] uppercase font-black tracking-widest mb-10">Введите номер терминала для активации</p>
                    <input v-model="quickStartPc" type="number" placeholder="№ ПК" class="no-spinners w-full bg-black border-2 border-white/5 rounded-[2.5rem] py-10 text-7xl font-black text-center text-[#22c55e] mb-12 outline-none shadow-inner" />
                    <button @click="proceedToQuickStart" class="w-full py-7 bg-[#22c55e] hover:bg-[#2ae06d] rounded-[2.5rem] text-black font-black uppercase italic tracking-[0.3em] shadow-[0_15px_40px_rgba(34,197,94,0.4)] active:scale-95 transition-all">Вход в систему</button>
                </div>
            </div>

            <PaymentModal v-if="isPaymentProcessing" :is-open="isPaymentProcessing" :mode="paymentData.mode" :data="paymentData" @close="isPaymentProcessing = false" />
        </Teleport>
    </MainLayout>
</template>

<style scoped>
@reference "../../../css/app.css";

.animate-in { animation: zoom-in 0.4s cubic-bezier(0.16, 1, 0.3, 1); }
@keyframes zoom-in {
    from { opacity: 0; transform: scale(0.95) translateY(30px); }
    to { opacity: 1; transform: scale(1) translateY(0); }
}

.no-spinners::-webkit-outer-spin-button,
.no-spinners::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}
.no-spinners {
    -moz-appearance: textfield;
}

::-webkit-scrollbar { width: 5px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: rgba(34, 197, 94, 0.1); border-radius: 10px; }
::-webkit-scrollbar-thumb:hover { background: rgba(34, 197, 94, 0.3); }
</style>
