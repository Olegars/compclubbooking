<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { usePage, Link, router } from '@inertiajs/vue3'
import axios from 'axios'
import MainLayout from '@/Layouts/MainLayout.vue'
import PaymentModal from '@/Components/PaymentModal.vue'

const page = usePage()

// --- ДАННЫЕ ПОЛЬЗОВАТЕЛЯ И АВАТАР ---
const user = computed(() => page.props.auth?.user || page.props.user || { name: 'GUEST' })

// --- БАЛАНС (Синхронизация с Inertia) ---
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

// --- МОНИТОРИНГ СЕССИИ GIZMO ---
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
        console.error('Gizmo Link Offline')
    }
}

// --- АКТИВНЫЕ ЗАКАЗЫ ИЗ МАГАЗИНА (Новое!) ---
const activeOrders = computed(() => {
    const orders = page.props.orders || [];
    return orders.filter((o: any) => o.status === 'pending' || o.status === 'cooking');
});

// --- ТАЙМЕР И ФИЛЬТР БРОНИРОВАНИЙ ---
const currentTime = ref(Date.now())
let timerInterval: ReturnType<typeof setInterval> | null = null

const activeBookings = computed(() => {
    const rawBookings = page.props.active_bookings || [];
    const nowTs = currentTime.value;
    if (!Array.isArray(rawBookings)) return [];

    return rawBookings.map((booking: any) => {
        try {
            const cleanDateStr = String(booking.date).split('T')[0].split(' ')[0];
            let y = 0, m = 0, d = 0;
            if (cleanDateStr.includes('-')) [y, m, d] = cleanDateStr.split('-').map(Number);
            else if (cleanDateStr.includes('.')) [d, m, y] = cleanDateStr.split('.').map(Number);

            const startTs = new Date(y, m - 1, d, 0, 0, 0).getTime() + (Number(booking.start_time) * 3600000);
            const endTs = startTs + (Number(booking.duration) * 3600000);
            return { ...booking, _endTs: endTs, _startTs: startTs };
        } catch (e) { return { ...booking, _endTs: Infinity, _startTs: 0 }; }
    })
        .filter(b => nowTs < b._endTs)
        .sort((a, b) => (b.id || 0) - (a.id || 0));
});

// --- ИСТОРИЯ ТРАНЗАКЦИЙ ---
const transactions = computed(() => {
    const txs = page.props.transactions || [];
    return [...txs].sort((a: any, b: any) => (b.id || 0) - (a.id || 0));
})

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

const proceedToPayment = async () => {
    if (topUpAmount.value < 100) return showError('Минимальная сумма 100 руб.')
    isTopUpInputOpen.value = false
    isPaymentProcessing.value = true
    paymentData.value = { mode: 'topup', price: topUpAmount.value, date: new Date().toLocaleDateString('ru-RU') }
    try {
        const response = await axios.post('/api/billing/topup', { amount: topUpAmount.value })
        if (response.data?.new_balance !== undefined) gizmo.value.balance = response.data.new_balance
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
        const response = await axios.post('/api/billing/start-session', { hostId: parseInt(quickStartPc.value), minutes: quickStartMinutes.value, price: quickStartCost.value })
        if (response.data?.new_balance !== undefined) gizmo.value.balance = response.data.new_balance
        router.reload({ only: ['transactions', 'gizmo', 'active_bookings'] })
        fetchActiveSession()
    } catch (e: any) { showError('Ошибка запуска сессии') }
}

const getCountdown = (dateStr: string, startH: any) => {
    try {
        const cleanDateStr = String(dateStr).split('T')[0].split(' ')[0];
        let y=0, m=0, d=0;
        if (cleanDateStr.includes('-')) [y, m, d] = cleanDateStr.split('-').map(Number);
        else if (cleanDateStr.includes('.')) [d, m, y] = cleanDateStr.split('.').map(Number);
        const target = new Date(y, m - 1, d, 0, 0, 0).getTime() + (Number(startH) * 3600000);
        const diff = target - currentTime.value;
        if (diff <= 0) return { text: 'СЕАНС НАЧАТ', urgent: true };
        const hh = Math.floor(diff / 3600000);
        const mm = Math.floor((diff % 3600000) / 60000);
        return { text: hh > 0 ? `${hh}ч ${mm}м` : `${mm}м`, urgent: hh === 0 && mm < 30 };
    } catch (e) { return { text: '—', urgent: false }; }
}

onMounted(() => {
    fetchActiveSession()
    pollingInterval = setInterval(fetchActiveSession, 30000)
    timerInterval = setInterval(() => { currentTime.value = Date.now() }, 1000)
})

onUnmounted(() => {
    if (pollingInterval) clearInterval(pollingInterval)
    if (timerInterval) clearInterval(timerInterval)
})
</script>

<template>
    <MainLayout>
        <div class="max-w-5xl mx-auto w-full grid grid-cols-1 md:grid-cols-3 gap-6 animate-in zoom-in duration-500">

            <div class="md:col-span-2 space-y-6">
                <div class="bg-[#0a0a0a] border border-[#22c55e]/20 rounded-[2.5rem] p-8 relative overflow-hidden group shadow-2xl">
                    <span class="text-[10px] uppercase text-[#22c55e] tracking-[0.4em] font-black italic">Доступные средства</span>
                    <div class="mt-2 flex items-baseline gap-3">
                        <span class="text-7xl font-black italic tracking-tighter text-white drop-shadow-[0_0_15px_rgba(34,197,94,0.3)]">
                            {{ Number(gizmo.balance).toFixed(0) }}
                        </span>
                        <span class="text-2xl font-bold text-[#22c55e] uppercase">RUB</span>
                    </div>
                    <div class="mt-8 flex flex-wrap gap-3">
                        <button @click="isTopUpInputOpen = true" class="px-6 py-4 bg-[#22c55e] text-black font-black rounded-2xl text-xs tracking-widest hover:bg-[#1ea34d] transition-all active:scale-95 shadow-[0_0_20px_rgba(34,197,94,0.3)]">ПОПОЛНИТЬ</button>
                        <button @click="isQuickStartOpen = true" class="px-6 py-4 bg-white/5 border border-[#22c55e]/40 text-[#22c55e] font-black rounded-2xl text-xs tracking-widest hover:bg-[#22c55e]/10 transition-all flex items-center gap-3 active:scale-95">СЕСТЬ ЗА ПК</button>
                        <Link href="/booking" class="px-6 py-4 bg-white/5 border border-white/10 text-white font-black rounded-2xl text-xs tracking-widest hover:bg-white/10 transition-all active:scale-95">БРОНЬ</Link>
                        <Link href="/shop" class="px-6 py-4 bg-white/5 border border-white/10 text-white font-black rounded-2xl text-xs tracking-widest hover:border-[#22c55e]/50 hover:text-[#22c55e] transition-all flex items-center gap-2 active:scale-95">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
                            МАРКЕТ
                        </Link>
                    </div>
                </div>

                <div v-if="activeOrders.length > 0" class="space-y-3">
                    <div v-for="order in activeOrders" :key="order.id"
                         class="bg-[#0a0a0a] border border-orange-500/30 rounded-[2rem] p-5 flex items-center justify-between relative overflow-hidden shadow-[0_0_20px_rgba(249,115,22,0.1)]">
                        <div class="flex items-center gap-4 relative z-10">
                            <div class="w-10 h-10 rounded-xl bg-orange-500/10 border border-orange-500/20 flex items-center justify-center">
                                <svg class="w-5 h-5 text-orange-500 animate-bounce" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                            </div>
                            <div>
                                <div class="text-[10px] uppercase text-orange-500 font-black tracking-widest italic">Заказ готовится</div>
                                <div class="text-sm font-black text-white uppercase">{{ order.product_name }}</div>
                            </div>
                        </div>
                        <div class="text-right z-10">
                            <div class="px-3 py-1 bg-orange-500/10 border border-orange-500/20 rounded-full text-[9px] font-black text-orange-500 uppercase tracking-widest">В ОЧЕРЕДИ</div>
                        </div>
                        <div class="absolute inset-y-0 right-0 w-32 bg-gradient-to-l from-orange-500/5 to-transparent"></div>
                    </div>
                </div>

                <div v-if="activeBookings.length > 0" class="space-y-4">
                    <div v-for="booking in activeBookings" :key="booking.id" class="bg-[#0a0a0a] border border-[#3b82f6]/40 rounded-[2.5rem] p-8 relative overflow-hidden shadow-[0_0_30px_rgba(59,130,246,0.1)]">
                        <div class="flex items-center gap-3 mb-6 z-10 relative">
                            <span class="w-3 h-3 rounded-full bg-[#3b82f6] animate-pulse"></span>
                            <span class="text-[10px] uppercase text-[#3b82f6] tracking-[0.4em] font-black italic">Узел зарезервирован</span>
                        </div>
                        <div class="grid grid-cols-2 md:grid-cols-3 gap-6 z-10 relative">
                            <div>
                                <span class="block text-[9px] text-white/30 uppercase font-black mb-1 italic">Объект</span>
                                <div class="text-2xl font-black text-white">ПК №{{ typeof booking.pc_ids === 'string' ? JSON.parse(booking.pc_ids).join(', ') : booking.pc_ids }}</div>
                            </div>
                            <div>
                                <span class="block text-[9px] text-white/30 uppercase font-black mb-1 italic">Интервал</span>
                                <div class="text-lg font-mono font-black text-white bg-white/5 px-3 py-1 rounded-lg border border-white/10 inline-block">
                                    {{ Math.floor(booking.start_time) }}:00 — {{ Math.floor(Number(booking.start_time) + Number(booking.duration)) }}:00
                                </div>
                            </div>
                            <div class="md:text-right">
                                <span class="block text-[9px] text-white/30 uppercase font-black mb-1 italic">До активации</span>
                                <div class="text-2xl font-mono font-black italic tracking-tighter" :class="getCountdown(booking.date, booking.start_time).urgent ? 'text-red-500 animate-pulse' : 'text-[#3b82f6]'">
                                    {{ getCountdown(booking.date, booking.start_time).text }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="activeSession.isActive || activeBookings.length === 0"
                     class="bg-[#0a0a0a] border border-white/5 rounded-[2.5rem] p-8 flex justify-between items-center relative transition-all duration-700"
                     :class="{'border-[#22c55e]/50 shadow-[0_0_40px_rgba(34,197,94,0.15)] bg-gradient-to-r from-black to-[#22c55e]/5': activeSession.isActive}">
                    <div class="z-10">
                        <span class="text-[10px] uppercase text-white/30 tracking-[0.4em] font-black italic">Статус узла</span>
                        <div class="mt-2 text-3xl font-black uppercase italic" :class="activeSession.isActive ? 'text-[#22c55e]' : 'text-white/60'">
                            {{ activeSession.isActive ? `ПК №${activeSession.pcName}` : 'Нет активных сессий' }}
                        </div>
                        <div v-if="activeSession.isActive" class="mt-2 inline-flex items-center px-3 py-1 bg-[#22c55e]/10 border border-[#22c55e]/20 rounded-full text-[10px] font-black text-[#22c55e] uppercase tracking-widest">{{ activeSession.zone }}</div>
                    </div>
                    <div v-if="activeSession.isActive" class="text-right z-10">
                        <div class="text-5xl font-mono font-black text-[#22c55e] tracking-tighter animate-pulse">{{ activeSession.timeLeft }}</div>
                        <span class="text-[9px] text-white/20 uppercase tracking-[0.2em] font-black italic">Остаточное время</span>
                    </div>
                </div>

                <div class="bg-[#0a0a0a] border border-white/5 rounded-[2.5rem] p-8 shadow-xl">
                    <span class="text-[10px] uppercase text-white/40 tracking-[0.4em] font-black italic block mb-8">История операций</span>
                    <div v-if="transactions.length > 0" class="space-y-5">
                        <div v-for="tx in transactions" :key="tx.id" class="flex items-center justify-between group">
                            <div class="flex items-center gap-5">
                                <div class="w-10 h-10 rounded-xl flex items-center justify-center border" :class="tx.amount > 0 ? 'bg-[#22c55e]/5 border-[#22c55e]/20 text-[#22c55e]' : 'bg-white/5 border-white/10 text-white/30'">
                                    <svg v-if="tx.amount > 0" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 6v12m6-6H6"/></svg>
                                    <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M18 12H6"/></svg>
                                </div>
                                <div>
                                    <div class="text-xs font-black text-white uppercase italic tracking-tight">{{ tx.description }}</div>
                                    <div class="text-[9px] text-white/20 font-mono mt-0.5">{{ tx.date }}</div>
                                </div>
                            </div>
                            <div class="text-right font-black italic font-mono" :class="tx.amount > 0 ? 'text-[#22c55e]' : 'text-white/50'">
                                {{ tx.amount > 0 ? '+' : '' }}{{ tx.amount }} ₽
                            </div>
                        </div>
                    </div>
                    <div v-else class="py-12 text-center border border-dashed border-white/5 rounded-[2rem] text-white/10 uppercase text-[10px] font-black tracking-[0.4em] italic">История пуста</div>
                </div>
            </div>

            <div class="space-y-6">
                <div class="bg-[#0a0a0a] border border-white/5 rounded-[2.5rem] p-8 flex flex-col items-center text-center shadow-xl">
                    <div class="w-28 h-28 rounded-full border-2 border-[#22c55e] p-1.5 mb-5 shadow-[0_0_25px_rgba(34,197,94,0.2)] overflow-hidden">
                        <div class="w-full h-full rounded-full bg-black flex items-center justify-center text-4xl font-black text-[#22c55e] italic relative">
                            <img v-if="user?.avatar" :src="`/images/avatars/${user.avatar}`" class="w-full h-full object-cover" />
                            <span v-else>{{ user?.name?.[0] }}</span>
                        </div>
                    </div>
                    <h3 class="text-2xl font-black uppercase italic tracking-tight text-white">{{ user?.name }}</h3>
                    <span class="text-[10px] text-[#22c55e] font-black tracking-[0.3em] uppercase mt-2 px-3 py-1 bg-[#22c55e]/10 rounded-full italic">Ранг: Сталкер</span>
                    <div class="w-full h-1.5 bg-white/5 rounded-full mt-8 overflow-hidden"><div class="w-2/3 h-full bg-[#22c55e] shadow-[0_0_15px_#22c55e]"></div></div>
                    <div class="flex justify-between w-full mt-3 text-[9px] text-white/20 uppercase font-black italic"><span>Lvl 11</span><span>2450 / 3000 XP</span></div>
                </div>
                <nav class="flex flex-col gap-3">
                    <Link href="/account/profile" class="p-5 bg-white/5 border border-white/5 rounded-3xl flex items-center justify-between hover:bg-white/10 transition-all group">
                        <span class="text-xs font-black uppercase tracking-widest text-white/50 group-hover:text-white transition-colors">Настройки</span>
                        <svg class="w-5 h-5 text-[#22c55e]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                    </Link>
                    <button class="p-5 bg-white/5 border border-white/5 rounded-3xl flex items-center justify-between hover:bg-red-500/10 transition-all group active:scale-95">
                        <span class="text-xs font-black uppercase tracking-widest text-white/50 group-hover:text-red-500 transition-colors">Завершить всё</span>
                        <svg class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    </button>
                </nav>
            </div>
        </div>

        <Teleport to="body">
            <div v-if="customError.show" class="fixed inset-0 flex items-center justify-center z-[9999999] p-4">
                <div class="absolute inset-0 bg-black/90 backdrop-blur-md" @click="customError.show = false"></div>
                <div class="relative w-full max-w-md bg-[#050505] border-2 border-red-500/30 rounded-[3rem] p-10 text-center animate-in zoom-in duration-300">
                    <h2 class="text-red-500 text-2xl font-black uppercase italic mb-2">Ошибка Системы</h2>
                    <p class="text-white/70 text-sm font-mono mb-8">{{ customError.text }}</p>
                    <button @click="customError.show = false" class="w-full py-5 bg-red-500/20 hover:bg-red-500 border border-red-500/50 hover:text-black rounded-2xl text-red-500 font-black uppercase transition-all italic">Принять</button>
                </div>
            </div>

            <div v-if="isTopUpInputOpen" class="fixed inset-0 flex items-center justify-center z-[9999900] p-4">
                <div class="absolute inset-0 bg-black/95 backdrop-blur-2xl" @click="isTopUpInputOpen = false"></div>
                <div class="relative w-full max-w-md bg-[#0a0a0a] border border-[#22c55e]/30 rounded-[3rem] p-10 shadow-[0_0_100px_rgba(34,197,94,0.2)] text-center">
                    <h2 class="text-[#22c55e] text-3xl font-black uppercase italic mb-10">Reactor Pay</h2>
                    <input v-model="topUpAmount" type="number" class="w-full bg-black border-2 border-white/5 rounded-[2rem] py-8 text-5xl font-black text-center text-white mb-10 outline-none focus:border-[#22c55e] transition-all" />
                    <button @click="proceedToPayment" class="w-full py-6 bg-[#22c55e] hover:bg-[#2ae06d] rounded-[2rem] text-black font-black uppercase tracking-widest shadow-[0_0_30px_rgba(34,197,94,0.3)] italic">Подтвердить</button>
                </div>
            </div>

            <div v-if="isQuickStartOpen" class="fixed inset-0 flex items-center justify-center z-[9999900] p-4">
                <div class="absolute inset-0 bg-black/95 backdrop-blur-2xl" @click="isQuickStartOpen = false"></div>
                <div class="relative w-full max-w-md bg-[#0a0a0a] border border-[#22c55e]/30 rounded-[3rem] p-10 shadow-[0_0_100px_rgba(34,197,94,0.2)] text-center">
                    <h2 class="text-[#22c55e] text-3xl font-black uppercase italic mb-8">Выбор терминала</h2>
                    <input v-model="quickStartPc" type="number" placeholder="№ ПК" class="w-full bg-black border-2 border-white/5 rounded-[2rem] py-8 text-6xl font-black text-center text-[#22c55e] mb-10 outline-none" />
                    <button @click="proceedToQuickStart" class="w-full py-6 bg-[#22c55e] hover:bg-[#2ae06d] rounded-[2rem] text-black font-black uppercase italic tracking-widest shadow-[0_0_30px_rgba(34,197,94,0.3)]">Активировать</button>
                </div>
            </div>

            <PaymentModal v-if="isPaymentProcessing" :is-open="isPaymentProcessing" :mode="paymentData.mode" :data="paymentData" @close="isPaymentProcessing = false" />
        </Teleport>
    </MainLayout>
</template>

<style scoped>
@reference "../../../css/app.css";
.animate-in { animation: zoom-in 0.5s cubic-bezier(0.16, 1, 0.3, 1); }
@keyframes zoom-in { from { opacity: 0; transform: scale(0.95) translateY(20px); } to { opacity: 1; transform: scale(1) translateY(0); } }
input[type=number]::-webkit-inner-spin-button, input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
</style>
