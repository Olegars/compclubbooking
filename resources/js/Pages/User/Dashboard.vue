<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { usePage, Link, router } from '@inertiajs/vue3'
import axios from 'axios'
import MainLayout from '@/Layouts/MainLayout.vue'
import PaymentModal from '@/Components/PaymentModal.vue'

const page = usePage()

// --- ЛОГИКА БАЛАНСА ---
const getRawBalance = () => {
    const props = page.props as any;
    // gizmo (dashboard) → shared gizmo → auth.user.balance
    return parseFloat(String(
        props.gizmo?.balance ?? props.auth?.user?.balance ?? 0
    )) || 0;
}

const currentBalance = ref(getRawBalance())
const displayBalance = ref(getRawBalance())
let animationFrameId: number | null = null

const animateValue = (start: number, end: number) => {
    if (isNaN(start) || isNaN(end) || start === end) return;
    const duration = 1000;
    const startTime = performance.now();
    if (animationFrameId) cancelAnimationFrame(animationFrameId);

    const step = (currentTime: number) => {
        const elapsed = currentTime - startTime;
        const progress = Math.min(elapsed / duration, 1);
        const easeOut = 1 - Math.pow(1 - progress, 4);
        displayBalance.value = start + (end - start) * easeOut;
        if (progress < 1) animationFrameId = requestAnimationFrame(step);
        else displayBalance.value = end;
    };
    animationFrameId = requestAnimationFrame(step);
}

watch(() => page.props.gizmo, (newGizmo: any) => {
    const serverBalance = parseFloat(String(newGizmo?.balance ?? 0));
    if (serverBalance !== currentBalance.value) {
        const old = currentBalance.value;
        currentBalance.value = serverBalance;
        animateValue(old, serverBalance);
    }
}, { deep: true });


// --- ДАННЫЕ И ТАЙМЕРЫ ---
// Синхронизируем время с сервером (server_time), чтобы избежать проблем с часами на ПК
const currentTime = ref(
    page.props.server_time
        ? new Date(page.props.server_time as string).getTime()
        : Date.now()
)
let secInterval: ReturnType<typeof setInterval> | null = null

const formatStartTime = (timeH: number | string) => {
    const h = Number(timeH)
    if (isNaN(h)) return '--:--'
    const hours = Math.floor(h).toString().padStart(2, '0')
    const mins = Math.round((h % 1) * 60).toString().padStart(2, '0')
    return `${hours}:${mins}`
}

const getRemainingTime = (b: any) => {
    // Используем end_timestamp, который мы посчитали в контроллере
    const endMs = b.end_timestamp || 0;
    const now = currentTime.value

    // Если время старта еще не наступило (для будущих броней)
    const startH = Number(b.start_time)
    const baseDate = new Date(b.date)
    const startMs = new Date(baseDate.getFullYear(), baseDate.getMonth(), baseDate.getDate(), Math.floor(startH), Math.round((startH % 1) * 60)).getTime()

    if (now < startMs) return 'ОЖИДАНИЕ'

    const diff = endMs - now
    if (diff <= 0) return '00:00:00'

    const h = Math.floor(diff / 3600000).toString().padStart(2, '0')
    const m = Math.floor((diff / 60000) % 60).toString().padStart(2, '0')
    const s = Math.floor((diff / 1000) % 60).toString().padStart(2, '0')
    return `${h}:${m}:${s}`
}

const transactions = computed(() => {
    return (page.props.transactions as any[]) || [];
})

// Фильтруем брони: если время вышло — скрываем карточку в реальном времени
const activeBookings = computed(() => {
    const raw = (page.props.active_bookings as any[]) || [];
    return raw.filter(b => {
        const endMs = b.end_timestamp || 0;
        return currentTime.value < endMs;
    });
})

const fetchDashboardData = () => {
    router.reload({
        only: ['user', 'gizmo', 'transactions', 'active_bookings', 'orders', 'latest_review'],
        preserveScroll: true
    })
}

// --- МОДАЛКИ ---
const isTopUpInputOpen = ref(false)
const isQuickStartOpen = ref(false)
const isReviewModalOpen = ref(false)
const isPaymentProcessing = ref(false)
const topUpAmount = ref(500)
const quickStartPc = ref('')
const paymentData = ref<any>({})

const proceedToPayment = async () => {
    if (topUpAmount.value < 100) return;
    isTopUpInputOpen.value = false;
    isPaymentProcessing.value = true;
    paymentData.value = { mode: 'topup', price: topUpAmount.value, date: new Date().toLocaleDateString('ru-RU') };

    try {
        // Payment stub: fake success UI + real deposit_balance credit
        const { data } = await axios.post('/api/billing/topup', { amount: topUpAmount.value, method: 'system' });
        const next = parseFloat(String(data.new_balance ?? data.deposit_balance ?? data.balance ?? 0));
        if (!isNaN(next) && next !== currentBalance.value) {
            const old = currentBalance.value;
            currentBalance.value = next;
            animateValue(old, next);
        }
        fetchDashboardData();
    } catch (e) {
        isPaymentProcessing.value = false;
        alert('Сбой транзакции пополнения');
    }
}

onMounted(() => {
    const b = getRawBalance();
    currentBalance.value = b;
    displayBalance.value = b;

    // Тикаем серверным временем
    secInterval = setInterval(() => {
        currentTime.value += 1000;
    }, 1000);

    const interval = setInterval(fetchDashboardData, 30000);

    onUnmounted(() => {
        clearInterval(interval);
        if (secInterval) clearInterval(secInterval);
    });
})
</script>

<template>
    <MainLayout>
        <div class="max-w-7xl mx-auto w-full grid grid-cols-1 md:grid-cols-3 gap-8 font-mono pb-20 px-4 text-white animate-in fade-in duration-700">

            <div class="md:col-span-2 space-y-8">

                <div class="bg-[#0a0a0a] border border-[#22c55e]/20 rounded-[3rem] p-10 relative overflow-hidden shadow-2xl shadow-[#22c55e]/5">
                    <div class="absolute top-0 right-0 p-8 opacity-10">
                        <svg class="w-32 h-32 text-[#22c55e]" fill="currentColor" viewBox="0 0 24 24"><path d="M21 18l-3-3h-5l-2 2h-3l-2-2H4l-3 3V5l3-3h5l2 2h3l2-2h5l3 3v13z"/></svg>
                    </div>

                    <span class="text-[10px] uppercase text-[#22c55e] tracking-[0.4em] font-black italic">Лицевой счет REACTOR</span>
                    <div class="mt-4 flex items-baseline gap-4">
                        <span class="text-8xl font-black italic tracking-tighter text-white drop-shadow-[0_0_25px_rgba(34,197,94,0.4)]">
                            {{ Math.floor(displayBalance) }}
                        </span>
                        <span class="text-3xl font-bold text-[#22c55e] uppercase italic">RUB</span>
                    </div>

                    <div class="mt-10 grid grid-cols-2 sm:grid-cols-5 gap-3 relative z-10">
                        <button @click="isTopUpInputOpen = true" class="py-4 bg-[#22c55e] text-black font-black rounded-xl text-[9px] tracking-widest hover:scale-105 transition-all uppercase italic">Пополнить</button>
                        <button @click="isQuickStartOpen = true" class="py-4 bg-white/5 border border-[#22c55e]/40 text-[#22c55e] font-black rounded-xl text-[9px] tracking-widest hover:bg-[#22c55e]/10 transition-all uppercase italic">Сесть за ПК</button>
                        <Link href="/booking" class="py-4 bg-white/5 border border-white/10 text-white font-black rounded-xl text-[9px] flex items-center justify-center tracking-widest hover:bg-white/10 transition-all uppercase italic">Бронь</Link>
                        <Link href="/shop" class="py-4 bg-white/5 border border-white/10 text-white font-black rounded-xl text-[9px] flex items-center justify-center tracking-widest hover:bg-white/10 transition-all uppercase italic">Маркет</Link>
                        <button @click="isReviewModalOpen = true" class="py-4 bg-white/5 border border-yellow-500/40 text-yellow-500 font-black rounded-xl text-[9px] tracking-widest hover:bg-yellow-500/10 transition-all uppercase italic">Бонус</button>
                    </div>
                </div>

                <div v-if="activeBookings.length > 0" class="space-y-4">
                    <div v-for="b in activeBookings" :key="b.id" class="bg-[#0a0a0a] border border-[#3b82f6]/40 rounded-[2.5rem] p-8 relative overflow-hidden group hover:border-[#3b82f6] transition-colors">
                        <div class="absolute inset-0 bg-gradient-to-r from-[#3b82f6]/5 to-transparent pointer-events-none"></div>

                        <div class="grid grid-cols-2 md:grid-cols-4 gap-6 relative z-10">
                            <div>
                                <span class="text-[10px] text-white/30 uppercase block mb-2 font-bold italic">Объект</span>
                                <div class="text-3xl font-black italic text-white uppercase">ПК №{{ b.formatted_pc }}</div>
                            </div>

                            <div>
                                <span class="text-[10px] text-white/30 uppercase block mb-2 font-bold italic">PIN-КОД</span>
                                <div class="text-3xl font-mono font-black text-[#22c55e] tracking-widest drop-shadow-[0_0_10px_rgba(34,197,94,0.2)]">
                                    {{ b.pin_code || '—' }}
                                </div>
                            </div>

                            <div class="text-right">
                                <span class="text-[10px] text-white/30 uppercase block mb-2 font-bold italic">Старт</span>
                                <div class="text-2xl font-black text-[#3b82f6] font-mono">{{ formatStartTime(b.start_time) }}</div>
                            </div>

                            <div class="text-right">
                                <span class="text-[10px] text-[#22c55e] uppercase block mb-2 font-bold italic border-b border-[#22c55e]/20 pb-1">Осталось времени</span>
                                <div class="text-3xl font-black font-mono tracking-tighter"
                                     :class="getRemainingTime(b) === 'ОЖИДАНИЕ' ? 'text-yellow-500 text-xl mt-2' : (getRemainingTime(b) === '00:00:00' ? 'text-red-500' : 'text-[#22c55e]')">
                                    {{ getRemainingTime(b) }}
                                </div>
                            </div>
                        </div>

                        <div v-if="b.games_label" class="mt-6 pt-5 border-t border-white/5 relative z-10 flex flex-wrap items-center gap-x-3 gap-y-2">
                            <span class="text-[10px] text-white/30 uppercase font-bold italic tracking-widest">Игры</span>
                            <span class="text-sm font-black uppercase italic text-white/90 tracking-tight">{{ b.games_label }}</span>
                        </div>
                    </div>
                </div>

                <div class="bg-[#0a0a0a] border border-white/5 rounded-[3rem] p-10 shadow-xl">
                    <span class="text-[10px] uppercase text-white/40 tracking-[0.4em] font-black italic block mb-10">Лог транзакций</span>
                    <div v-if="transactions.length > 0" class="space-y-6">
                        <div v-for="tx in transactions" :key="tx.id" class="flex items-center justify-between group transition-all">
                            <div class="flex items-center gap-6">
                                <div :class="['w-12 h-12 rounded-2xl flex items-center justify-center border transition-colors', tx.amount > 0 ? 'bg-[#22c55e]/5 border-[#22c55e]/20 text-[#22c55e]' : 'bg-white/5 border-white/10 text-white/20 group-hover:border-white/20']">
                                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path :d="tx.amount > 0 ? 'M12 6v12m6-6H6' : 'M18 12H6'" stroke-width="2.5" stroke-linecap="round"/></svg>
                                </div>
                                <div>
                                    <div class="text-sm font-black uppercase italic tracking-tight group-hover:text-[#22c55e] transition-colors">{{ tx.description }}</div>
                                    <div class="text-[10px] text-white/20 font-mono mt-1 italic">{{ tx.date }}</div>
                                </div>
                            </div>
                            <div :class="['text-xl font-black italic font-mono tracking-tighter', tx.amount > 0 ? 'text-[#22c55e]' : 'text-white/40']">
                                {{ tx.amount > 0 ? '+' : '' }}{{ tx.amount }} ₽
                            </div>
                        </div>
                    </div>
                    <div v-else class="text-center py-10 text-white/10 italic uppercase text-[10px] tracking-widest border border-dashed border-white/5 rounded-2xl">История пуста</div>
                </div>
            </div>

            <div class="space-y-8">
                <div class="bg-[#0a0a0a] border border-white/5 rounded-[3rem] p-10 flex flex-col items-center shadow-xl">
                    <div class="w-32 h-32 rounded-full bg-black flex items-center justify-center text-5xl font-black text-[#22c55e] italic border-2 border-[#22c55e]/30 mb-6 overflow-hidden shadow-[0_0_40px_rgba(34,197,94,0.1)]">
                        <img v-if="page.props.user?.avatar" :src="`/images/avatars/${page.props.user.avatar}`" class="w-full h-full object-cover" />
                        <span v-else>{{ (page.props.user?.name || 'S')[0] }}</span>
                    </div>
                    <h3 class="text-3xl font-black uppercase italic tracking-tighter text-white text-center">{{ page.props.user?.name }}</h3>
                    <div class="mt-4 px-6 py-2 bg-[#22c55e]/10 border border-[#22c55e]/20 rounded-full text-[10px] text-[#22c55e] font-black uppercase italic tracking-widest">СТАЛКЕР</div>
                </div>

                <nav class="flex flex-col gap-4">
                    <Link href="/account/profile" class="p-6 bg-[#0a0a0a] border border-white/5 rounded-[2rem] flex items-center justify-between hover:bg-white/5 transition-all group">
                        <span class="text-[10px] font-black uppercase text-white/40 group-hover:text-white transition-colors italic tracking-widest">Настройки профиля</span>
                        <svg class="w-6 h-6 text-[#22c55e] transform group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M9 5l7 7-7 7"/></svg>
                    </Link>
                    <button @click="router.post('/logout')" class="p-6 bg-[#0a0a0a] border border-white/5 rounded-[2rem] flex items-center justify-between hover:bg-red-500/10 transition-all group">
                        <span class="text-[10px] font-black uppercase text-white/40 group-hover:text-red-500 transition-colors italic tracking-widest">Завершить рейд</span>
                        <svg class="w-6 h-6 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M17 16l4-4m0 0l-4-4m4 4H7"/></svg>
                    </button>
                </nav>
            </div>
        </div>

        <Teleport to="body">
            <div v-if="isTopUpInputOpen" class="fixed inset-0 flex items-center justify-center z-[9998] p-6 animate-in fade-in duration-300">
                <div class="absolute inset-0 bg-black/95 backdrop-blur-xl" @click="isTopUpInputOpen = false"></div>
                <div class="relative max-w-md w-full bg-[#0a0a0a] border border-[#22c55e]/30 rounded-[3.5rem] p-12 text-center shadow-[0_0_120px_rgba(34,197,94,0.2)]">
                    <h2 class="text-[#22c55e] text-4xl font-black uppercase italic mb-10 tracking-tighter">Reactor Pay</h2>
                    <div class="grid grid-cols-3 gap-3 mb-8">
                        <button v-for="amount in [500, 1000, 2000]" :key="amount" @click="topUpAmount = amount"
                                :class="['py-4 rounded-2xl font-black transition-all italic text-[12px]', topUpAmount === amount ? 'bg-[#22c55e] text-black' : 'bg-white/5 text-white border border-white/10']">
                            {{ amount }}
                        </button>
                    </div>
                    <input v-model="topUpAmount" type="number" class="w-full bg-black border-2 border-white/5 rounded-[2.5rem] py-8 text-6xl font-black text-center text-white mb-10 outline-none focus:border-[#22c55e]/50 transition-colors" />
                    <button @click="proceedToPayment" class="w-full py-7 bg-[#22c55e] text-black font-black uppercase rounded-[2.5rem] italic hover:bg-[#2ae06d] transition-colors shadow-[0_0_20px_rgba(34,197,94,0.3)]">Подтвердить</button>
                </div>
            </div>

            <div v-if="isQuickStartOpen" class="fixed inset-0 flex items-center justify-center z-[9998] p-6 animate-in zoom-in duration-300">
                <div class="absolute inset-0 bg-black/95 backdrop-blur-xl" @click="isQuickStartOpen = false"></div>
                <div class="relative max-w-md w-full bg-[#0a0a0a] border border-[#22c55e]/30 rounded-[3.5rem] p-12 text-center shadow-[0_0_80px_rgba(34,197,94,0.1)]">
                    <h2 class="text-[#22c55e] text-4xl font-black uppercase italic mb-10 tracking-tighter">Вход в узел</h2>
                    <input v-model="quickStartPc" type="number" placeholder="№ ПК" class="w-full bg-black border-2 border-white/5 rounded-[2.5rem] py-10 text-7xl font-black text-center text-[#22c55e] mb-12 outline-none focus:border-[#22c55e]/50 transition-colors" />
                    <button @click="isQuickStartOpen = false" class="w-full py-7 bg-[#22c55e] text-black font-black uppercase rounded-[2.5rem] italic shadow-[0_0_20px_rgba(34,197,94,0.2)]">Подключиться</button>
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
    from { opacity: 0; transform: scale(0.98); }
    to { opacity: 1; transform: scale(1); }
}
</style>
