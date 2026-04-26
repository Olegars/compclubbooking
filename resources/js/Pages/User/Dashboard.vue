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

// --- МОНИТОРИНГ СЕССИИ ---
const activeSession = ref({ isActive: false, pcName: '—', timeLeft: '00:00', zone: '—' })
let pollingInterval: ReturnType<typeof setInterval> | null = null

const fetchDashboardData = async () => {
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
        router.reload({
            only: ['orders', 'transactions', 'active_bookings', 'gizmo', 'latest_review'],
            preserveScroll: true
        })
    } catch (e) {
        console.error('REACTOR Core Link Offline')
    }
}

// --- ФИЛЬТРЫ ДАННЫХ ---
const activeOrders = computed(() => {
    const orders = (page.props.orders as any[]) || [];
    // Подхватываем все активные статусы
    return orders.filter(o => ['pending', 'cooking', 'new', 'waiting'].includes(o.status));
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
            const startTs = new Date(y, m - 1, d, 0, 0, 0).getTime() + (Number(booking.start_time) * 3600000);
            const endTs = startTs + (Number(booking.duration) * 3600000);
            return { ...booking, _endTs: endTs, _startTs: startTs };
        } catch (e) { return { ...booking, _endTs: Infinity, _startTs: 0 }; }
    }).filter(b => nowTs < b._endTs).sort((a, b) => b.id - a.id);
});

// --- ЛОГИКА ИСПРАВЛЕНИЙ (ФИКСЫ) ---

const formatPcNumber = (pcIds: any) => {
    // Если в базе реально NULL или пусто
    if (pcIds === null || pcIds === undefined || pcIds === '' || pcIds === '[]') {
        return 'НЕ УКАЗАН';
    }

    try {
        // Если это JSON строка типа "[2]"
        if (typeof pcIds === 'string' && (pcIds.includes('[') || pcIds.includes('{'))) {
            const parsed = JSON.parse(pcIds);
            const val = Array.isArray(parsed) ? parsed[0] : parsed;
            return val || 'НЕ УКАЗАН';
        }
        // Если это просто число или строка
        return String(pcIds).replace(/[\[\]"\s]/g, '');
    } catch (e) {
        // Если произошла ошибка парсинга, выводим как есть
        return String(pcIds);
    }
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
        const ss = Math.floor((diff % 60000) / 1000);
        if (hh === 0 && mm === 0) return { text: `${ss}сек`, urgent: true };
        return { text: hh > 0 ? `${hh}ч ${mm}м` : `${mm}м`, urgent: hh === 0 && mm < 30 };
    } catch (e) { return { text: '—', urgent: false }; }
}

const isMarketAvailable = computed(() => {
    const timeToStartReached = activeBookings.value.some(b => currentTime.value >= b._startTs);
    return activeSession.value.isActive || timeToStartReached;
});

// --- ЛОГИКА ОШИБОК И ПЛАТЕЖЕЙ ---
const customError = ref({ show: false, text: '' })
const isTopUpInputOpen = ref(false)
const isQuickStartOpen = ref(false)
const isReviewModalOpen = ref(false)
const isPaymentProcessing = ref(false)
const topUpAmount = ref(500)
const reviewText = ref('')
const quickStartPc = ref('')
const quickStartMinutes = ref(60)
const quickStartCost = computed(() => (quickStartMinutes.value / 60) * 100)
const paymentData = ref<any>({})

const showError = (text: string) => {
    customError.value = { show: true, text }
    isPaymentProcessing.value = false
}

const submitReviewClaim = async () => {
    if (reviewText.value.length < 10) return showError('Текст отзыва слишком короткий.')
    try {
        await axios.post('/api/bonuses/review', { text: reviewText.value })
        isReviewModalOpen.value = false
        reviewText.value = ''
        router.reload({ only: ['latest_review'] })
    } catch (e: any) {
        showError(e.response?.data?.message || 'Ошибка отправки отзыва')
    }
}

const handleMarketClick = (e: Event) => {
    e.preventDefault();
    if (!isMarketAvailable.value) {
        showError('Заказ доступен только во время активного сеанса.');
    } else {
        router.get('/shop');
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
        <div class="max-w-7xl mx-auto w-full grid grid-cols-1 md:grid-cols-3 gap-8 animate-in zoom-in duration-500 font-mono pb-20 px-4 text-white">

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

                        <button @click="isReviewModalOpen = true"
                                :disabled="page.props.latest_review?.status === 'pending' || page.props.latest_review?.status === 'approved'"
                                :class="['px-8 py-5 border font-black rounded-2xl text-xs tracking-widest transition-all flex items-center gap-2 active:scale-95',
                                         (page.props.latest_review?.status === 'pending' || page.props.latest_review?.status === 'approved')
                                         ? 'opacity-30 grayscale cursor-default border-white/10 text-white/40'
                                         : 'bg-white/5 border-yellow-500/40 text-yellow-500 hover:bg-yellow-500/10']">
                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                            {{ page.props.latest_review?.status === 'pending' ? 'ОТЗЫВ НА ПРОВЕРКЕ' : 'БОНУС ЗА ОТЗЫВ' }}
                        </button>

                        <button @click="handleMarketClick"
                                :class="['px-8 py-5 rounded-2xl text-xs tracking-widest transition-all flex items-center gap-2 font-black border active:scale-95',
                                         isMarketAvailable ? 'bg-white/5 border-[#22c55e]/50 text-[#22c55e] hover:bg-[#22c55e]/10 cursor-pointer' : 'bg-white/5 border-white/5 text-white/20 cursor-default grayscale']">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
                            МАРКЕТ
                        </button>
                    </div>
                </div>

                <div v-if="activeOrders.length > 0" class="space-y-4">
                    <div v-for="order in activeOrders" :key="order.id"
                         class="bg-[#0a0a0a] border border-orange-500/30 rounded-[2.5rem] p-6 flex items-center justify-between relative shadow-[0_10px_30px_rgba(249,115,22,0.1)]">
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
                    </div>
                </div>

                <div v-if="activeBookings.length > 0" class="space-y-4">
                    <div v-for="booking in activeBookings" :key="booking.id"
                         class="bg-[#0a0a0a] border border-[#3b82f6]/40 rounded-[2.5rem] p-8 relative overflow-hidden shadow-[0_0_40px_rgba(59,130,246,0.1)]">
                        <div class="flex items-center gap-3 mb-6 relative z-10">
                            <span class="w-3 h-3 rounded-full bg-[#3b82f6] animate-pulse"></span>
                            <span class="text-[10px] uppercase text-[#3b82f6] tracking-[0.4em] font-black italic">Зарезервировано</span>
                        </div>
                        <div class="grid grid-cols-2 md:grid-cols-3 gap-8 relative z-10">
                            <div>
                                <span class="block text-[10px] text-white/30 uppercase font-black mb-2 italic tracking-widest">Объект</span>
                                <div class="text-3xl font-black text-white italic tracking-tighter">ПК №{{ formatPcNumber(booking.pc_ids) }}</div>
                            </div>
                            <div>
                                <span class="block text-[10px] text-white/30 uppercase font-black mb-2 italic tracking-widest">Время</span>
                                <div class="text-lg font-mono font-black text-white bg-white/5 px-4 py-2 rounded-xl border border-white/10 inline-block">
                                    {{ Math.floor(booking.start_time) }}:00 — {{ Math.floor(Number(booking.start_time) + Number(booking.duration)) }}:00
                                </div>
                            </div>
                            <div class="md:text-right">
                                <span class="block text-[10px] text-white/30 uppercase font-black mb-2 italic tracking-widest">До старта</span>
                                <div class="text-3xl font-mono font-black italic tracking-tighter"
                                     :class="getCountdown(booking.date, booking.start_time).urgent ? 'text-orange-500 animate-pulse' : 'text-[#3b82f6]'">
                                    {{ getCountdown(booking.date, booking.start_time).text }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="activeSession.isActive"
                     class="bg-[#0a0a0a] border border-[#22c55e]/50 shadow-[0_0_50px_rgba(34,197,94,0.15)] bg-gradient-to-br from-black to-[#22c55e]/5 rounded-[3rem] p-10 flex justify-between items-center relative transition-all">
                    <div>
                        <span class="text-[10px] uppercase text-white/30 tracking-[0.4em] font-black italic">Текущий узел</span>
                        <div class="mt-4 text-4xl font-black uppercase italic tracking-tighter text-[#22c55e]">
                            ПК №{{ activeSession.pcName }}
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-6xl font-mono font-black text-[#22c55e] tracking-tighter animate-pulse">
                            {{ activeSession.timeLeft }}
                        </div>
                        <span class="text-[10px] text-white/20 uppercase font-black italic block mt-2">Осталось</span>
                    </div>
                </div>

                <div class="bg-[#0a0a0a] border border-white/5 rounded-[3rem] p-10 shadow-xl">
                    <span class="text-[10px] uppercase text-white/40 tracking-[0.4em] font-black italic block mb-10">Лог транзакций</span>
                    <div v-if="transactions.length > 0" class="space-y-6">
                        <div v-for="tx in transactions" :key="tx.id" class="flex items-center justify-between group">
                            <div class="flex items-center gap-6">
                                <div class="w-12 h-12 rounded-2xl flex items-center justify-center border"
                                     :class="tx.amount > 0 ? 'bg-[#22c55e]/5 border-[#22c55e]/20 text-[#22c55e]' : 'bg-white/5 border-white/10 text-white/20'">
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
                </div>
            </div>

            <div class="space-y-8">
                <div class="bg-[#0a0a0a] border border-white/5 rounded-[3rem] p-10 flex flex-col items-center text-center shadow-2xl relative overflow-hidden group">

                    <div class="relative w-32 h-32 mb-6">
                        <div class="absolute inset-0 bg-[#22c55e]/10 rounded-full blur-xl"></div>
                        <div class="w-full h-full rounded-full bg-black flex items-center justify-center text-5xl font-black text-[#22c55e] italic overflow-hidden relative z-10 border-2 border-[#22c55e]/30 shadow-[0_0_30px_rgba(34,197,94,0.15)]">
                            <img v-if="user?.avatar" :src="`/images/avatars/${user.avatar}`" class="w-full h-full object-cover" />
                            <span v-else>{{ user?.name?.[0] }}</span>
                        </div>
                    </div>

                    <div class="relative z-10 w-full text-center">
                        <h3 class="text-3xl font-black uppercase italic tracking-tighter text-white">{{ user?.name }}</h3>

                        <div class="mt-4 inline-flex items-center gap-3 px-5 py-2 bg-[#22c55e]/10 border border-[#22c55e]/20 rounded-full text-[10px] text-[#22c55e] font-black tracking-[0.3em] uppercase italic">
                            <span>Ранг: Сталкер</span>
                        </div>
                    </div>

                    <div class="w-full mt-8 relative">
                        <div class="flex justify-between items-end mb-2 px-1">
                            <span class="text-[10px] uppercase font-black text-[#22c55e] tracking-widest italic">Опыт</span>
                            <span class="text-[10px] font-mono text-white/50">75 / 100</span>
                        </div>

                        <div class="h-2 w-full bg-white/5 rounded-full overflow-hidden relative">
                            <div class="absolute top-0 left-0 h-full bg-[#22c55e] transition-all duration-1000 ease-out shadow-[0_0_10px_#22c55e]"
                                 style="width: 75%;">
                            </div>
                        </div>
                    </div>
                </div>

                <nav class="flex flex-col gap-4">
                    <Link href="/account/profile" class="p-6 bg-[#0a0a0a] border border-white/5 rounded-[2rem] flex items-center justify-between hover:bg-white/5 transition-all group shadow-xl">
                        <span class="text-xs font-black uppercase tracking-[0.2em] text-white/40 group-hover:text-white transition-colors italic">Параметры профиля</span>
                        <svg class="w-6 h-6 text-[#22c55e]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M9 5l7 7-7 7"/></svg>
                    </Link>
                    <button @click="router.post('/logout')" class="p-6 bg-[#0a0a0a] border border-white/5 rounded-[2rem] flex items-center justify-between hover:bg-red-500/10 hover:border-red-500/20 transition-all group active:scale-95 shadow-xl">
                        <span class="text-xs font-black uppercase tracking-[0.2em] text-white/40 group-hover:text-red-500 transition-colors italic">Выход из системы</span>
                        <svg class="w-6 h-6 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M17 16l4-4m0 0l-4-4m4 4H7"/></svg>
                    </button>
                </nav>
            </div>
        </div>

        <Teleport to="body">
            <div v-if="customError.show" class="fixed inset-0 flex items-center justify-center z-[9999999] p-6">
                <div class="absolute inset-0 bg-black/90 backdrop-blur-xl" @click="customError.show = false"></div>
                <div class="relative w-full max-w-md bg-[#050505] border-2 border-red-500/30 rounded-[3.5rem] p-12 text-center">
                    <div class="w-20 h-20 bg-red-500/10 border border-red-500/30 rounded-full flex items-center justify-center mx-auto mb-8">
                        <svg class="w-10 h-10 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    </div>
                    <h2 class="text-red-500 text-3xl font-black uppercase italic mb-4 tracking-tighter">Ошибка протокола</h2>
                    <p class="text-white/60 text-sm mb-10">{{ customError.text }}</p>
                    <button @click="customError.show = false" class="w-full py-6 bg-red-500/20 text-red-500 rounded-2xl font-black uppercase italic tracking-widest">Принять</button>
                </div>
            </div>

            <div v-if="isTopUpInputOpen" class="fixed inset-0 flex items-center justify-center z-[9999900] p-6">
                <div class="absolute inset-0 bg-black/95 backdrop-blur-2xl" @click="isTopUpInputOpen = false"></div>
                <div class="relative w-full max-w-md bg-[#0a0a0a] border border-[#22c55e]/30 rounded-[3.5rem] p-12 text-center shadow-[0_0_120px_rgba(34,197,94,0.2)]">
                    <h2 class="text-[#22c55e] text-4xl font-black uppercase italic mb-10 tracking-tighter">Reactor Pay</h2>
                    <div class="grid grid-cols-3 gap-3 mb-8">
                        <button v-for="amount in [100, 500, 1000, 2000, 3000, 5000]" :key="amount" @click="topUpAmount = amount"
                                class="py-4 bg-white/5 border border-white/10 rounded-2xl text-white font-black hover:bg-[#22c55e]/20"
                                :class="topUpAmount === amount ? 'bg-[#22c55e]/20 border-[#22c55e]/50 !text-[#22c55e]' : ''">
                            {{ amount }}
                        </button>
                    </div>
                    <input v-model="topUpAmount" type="number" class="no-spinners w-full bg-black border-2 border-white/5 rounded-[2.5rem] py-8 text-6xl font-black text-center text-white mb-10 outline-none" />
                    <button @click="proceedToPayment" class="w-full py-7 bg-[#22c55e] text-black font-black uppercase tracking-[0.3em] rounded-[2.5rem] italic">Подтвердить</button>
                </div>
            </div>

            <div v-if="isQuickStartOpen" class="fixed inset-0 flex items-center justify-center z-[9999900] p-6">
                <div class="absolute inset-0 bg-black/95 backdrop-blur-2xl" @click="isQuickStartOpen = false"></div>
                <div class="relative w-full max-w-md bg-[#0a0a0a] border border-[#22c55e]/30 rounded-[3.5rem] p-12 text-center shadow-[0_0_120px_rgba(34,197,94,0.2)]">
                    <h2 class="text-[#22c55e] text-4xl font-black uppercase italic mb-4 tracking-tighter">Связь с узлом</h2>
                    <input v-model="quickStartPc" type="number" placeholder="№ ПК" class="no-spinners w-full bg-black border-2 border-white/5 rounded-[2.5rem] py-10 text-7xl font-black text-center text-[#22c55e] mb-12 outline-none shadow-inner" />
                    <button @click="proceedToQuickStart" class="w-full py-7 bg-[#22c55e] text-black font-black uppercase italic tracking-[0.3em] rounded-[2.5rem]">Вход в систему</button>
                </div>
            </div>

            <div v-if="isReviewModalOpen" class="fixed inset-0 flex items-center justify-center z-[9999900] p-6">
                <div class="absolute inset-0 bg-black/95 backdrop-blur-2xl" @click="isReviewModalOpen = false"></div>
                <div class="relative w-full max-w-lg bg-[#0a0a0a] border border-yellow-500/30 rounded-[3.5rem] p-12 shadow-[0_0_120px_rgba(234,179,8,0.2)]">
                    <h2 class="text-yellow-500 text-4xl font-black uppercase italic mb-6 tracking-tighter text-center">Reputation</h2>
                    <textarea v-model="reviewText" placeholder="Вставьте текст отзыва..." class="w-full h-40 bg-black border-2 border-white/5 rounded-[2rem] p-6 text-white text-sm outline-none resize-none mb-8"></textarea>
                    <button @click="submitReviewClaim" class="w-full py-6 bg-yellow-500 rounded-[2.5rem] text-black font-black uppercase tracking-[0.3em] italic">Отправить</button>
                </div>
            </div>

            <PaymentModal v-if="isPaymentProcessing" :is-open="isPaymentProcessing" :mode="paymentData.mode" :data="paymentData" @close="isPaymentProcessing = false" />
        </Teleport>
    </MainLayout>
</template>
