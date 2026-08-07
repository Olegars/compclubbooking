<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { usePage, Link, router } from '@inertiajs/vue3'
import axios from 'axios'
import MainLayout from '@/Layouts/MainLayout.vue'
import YooKassaWidgetModal from '@/Components/YooKassaWidgetModal.vue'
import PaymentReceiptConsent from '@/Components/PaymentReceiptConsent.vue'
import FiscalReceiptModal from '@/Components/FiscalReceiptModal.vue'

const page = usePage()

// --- ЛОГИКА БАЛАНСА ---
const getRawBalance = () => {
    const props = page.props as any;
    return parseFloat(String(props.auth?.user?.balance ?? 0)) || 0;
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

watch(() => (page.props as any).auth?.user?.balance, (newBalance: any) => {
    const serverBalance = parseFloat(String(newBalance ?? 0));
    if (serverBalance !== currentBalance.value) {
        const old = currentBalance.value;
        currentBalance.value = serverBalance;
        animateValue(old, serverBalance);
    }
});


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

const resolveBookingPhase = (b: any) => {
    if (b.is_started || b.phase === 'active') return 'active'
    const now = currentTime.value
    const startMs = b.start_timestamp || 0
    const billingMs = b.billing_start_timestamp || startMs
    if (startMs && now < startMs) return 'waiting'
    if (billingMs && now < billingMs) return 'late_waiting'
    return 'late_billing'
}

const getRemainingTime = (b: any) => {
    const endMs = b.end_timestamp || 0
    const phase = resolveBookingPhase(b)

    // До старта и в окне мягкого ожидания (grace) — статус ОЖИДАНИЕ, не «просрочено».
    if (phase === 'waiting' || phase === 'late_waiting') {
        return 'ОЖИДАНИЕ'
    }

    const diff = endMs - currentTime.value
    if (diff <= 0) return '00:00:00'

    const h = Math.floor(diff / 3600000).toString().padStart(2, '0')
    const m = Math.floor((diff / 60000) % 60).toString().padStart(2, '0')
    const s = Math.floor((diff / 1000) % 60).toString().padStart(2, '0')
    return `${h}:${m}:${s}`
}

const bookingTimeClass = (b: any) => {
    const label = getRemainingTime(b)
    const phase = resolveBookingPhase(b)
    if (label === 'ОЖИДАНИЕ') {
        return phase === 'late_waiting'
            ? 'text-red-500 text-xl mt-2'
            : 'text-yellow-500 text-xl mt-2'
    }
    if (label === '00:00:00') return 'text-red-500 text-xl mt-2'
    return 'text-[#22c55e]'
}

const transactions = computed(() => {
    return (page.props.transactions as any[]) || [];
})

// Фильтруем брони: скрываем, когда эффективное время истекло
const activeBookings = computed(() => {
    const raw = (page.props.active_bookings as any[]) || [];
    return raw.filter(b => {
        const endMs = b.end_timestamp || 0
        const startMs = b.start_timestamp || 0
        if (startMs && currentTime.value < startMs) return true
        return currentTime.value < endMs
    });
})

const cancelError = ref('')
const cancellingBookingId = ref<number | null>(null)

const cancelBooking = async (b: any) => {
    if (!b?.booking_group_id || !b.can_cancel || cancellingBookingId.value) return
    if (!confirm('Отменить бронь? Средства вернутся на баланс.')) return

    cancellingBookingId.value = b.id
    cancelError.value = ''
    try {
        await axios.post(`/api/booking/${b.booking_group_id}/cancel`)
        fetchDashboardData()
    } catch (e: any) {
        const msg = e.response?.data?.message
            || e.response?.data?.errors?.booking?.[0]
            || 'Не удалось отменить бронь'
        cancelError.value = msg
    } finally {
        cancellingBookingId.value = null
    }
}

const fetchDashboardData = () => {
    router.reload({
        only: ['user', 'auth', 'transactions', 'active_bookings', 'orders', 'latest_review', 'review_meta', 'achievements'],
        preserveScroll: true
    })
}

const achievements = computed(() => {
    return (page.props.achievements as any[]) || []
})

const rewardSuffix = (type: string) => type === 'bonus_balance' ? 'фантиков' : '₽'

const latestReview = computed(() => (page.props.latest_review as any) || null)
const reviewMeta = computed(() => (page.props.review_meta as any) || {})
const reviewStatus = computed(() => latestReview.value?.status as string | undefined)
const canSubmitReview = computed(() => reviewStatus.value !== 'pending')
const bonusAmount = computed(() => Math.floor(Number(reviewMeta.value.bonus_amount ?? 100)))
const minReviewLength = computed(() => Number(reviewMeta.value.min_text_length ?? 40))

// --- МОДАЛКИ ---
const isTopUpInputOpen = ref(false)
const isQuickStartOpen = ref(false)
const isReviewModalOpen = ref(false)
const isGameRequestOpen = ref(false)
const isPaymentProcessing = ref(false)
const isPaymentWidgetOpen = ref(false)
const paymentToken = ref('')
const paymentId = ref('')
const sendReceipt = ref(false)
const isReceiptModalOpen = ref(false)
const receiptModalUrl = ref<string | null>(null)
const receiptModalAmount = ref<number | null>(null)
const receiptPaymentId = ref<string | null>(null)
const receiptFiscalStatus = ref<string | null>(null)
const receiptIsStub = ref(false)
const isReviewSubmitting = ref(false)
const isGameRequestSubmitting = ref(false)
const topUpAmount = ref(500)
const quickStartPc = ref('')
const reviewText = ref('')
const reviewError = ref('')
const gameRequestTitle = ref('')
const gameRequestComment = ref('')
const gameRequestError = ref('')
const gameRequestSuccess = ref('')
const openReviewModal = () => {
    reviewError.value = ''
    reviewText.value = ''
    isReviewModalOpen.value = true
}

const openGameRequestModal = () => {
    gameRequestError.value = ''
    gameRequestSuccess.value = ''
    gameRequestTitle.value = ''
    gameRequestComment.value = ''
    isGameRequestOpen.value = true
}

const submitGameRequest = async () => {
    if (isGameRequestSubmitting.value) return
    const title = gameRequestTitle.value.trim()
    if (!title) {
        gameRequestError.value = 'Укажите название игры'
        return
    }
    isGameRequestSubmitting.value = true
    gameRequestError.value = ''
    try {
        const { data } = await axios.post('/api/game-requests', {
            title,
            comment: gameRequestComment.value.trim() || null,
        })
        gameRequestSuccess.value = data.message || 'Заявка принята'
        gameRequestTitle.value = ''
        gameRequestComment.value = ''
        setTimeout(() => { isGameRequestOpen.value = false }, 1200)
    } catch (e: any) {
        gameRequestError.value = e.response?.data?.message
            || e.response?.data?.errors?.title?.[0]
            || 'Не удалось отправить заявку'
    } finally {
        isGameRequestSubmitting.value = false
    }
}

const submitReview = async () => {
    if (!canSubmitReview.value || isReviewSubmitting.value) return
    const text = reviewText.value.trim()
    if (!text) {
        reviewError.value = 'Вставьте текст отзыва'
        return
    }
    if (text.length < minReviewLength.value) {
        reviewError.value = `Текст слишком короткий (минимум ${minReviewLength.value} символов)`
        return
    }

    isReviewSubmitting.value = true
    reviewError.value = ''
    try {
        await axios.post('/api/bonuses/review', { text })
        isReviewModalOpen.value = false
        reviewText.value = ''
        fetchDashboardData()
    } catch (e: any) {
        reviewError.value = e.response?.data?.message
            || e.response?.data?.errors?.text?.[0]
            || 'Не удалось отправить заявку'
    } finally {
        isReviewSubmitting.value = false
    }
}

const proceedToPayment = async () => {
    if (topUpAmount.value < 100) return;
    isTopUpInputOpen.value = false;
    isPaymentProcessing.value = true;

    try {
        const { data } = await axios.post('/api/billing/topup', {
            amount: topUpAmount.value,
            method: 'card',
            return_to: window.location.pathname + window.location.search,
            send_receipt: sendReceipt.value,
        });
        if (data.confirmation_token && data.payment_id) {
            paymentToken.value = data.confirmation_token;
            paymentId.value = data.payment_id;
            isPaymentWidgetOpen.value = true;
            return;
        }
        alert('Не удалось открыть форму оплаты');
    } catch (e: any) {
        alert(e.response?.data?.message || 'Сбой транзакции пополнения');
    } finally {
        isPaymentProcessing.value = false;
    }
}

const closePaymentWidget = () => {
    isPaymentWidgetOpen.value = false;
    paymentToken.value = '';
    paymentId.value = '';
}

const handlePaymentPaid = (payload: {
    paymentId: string
    amount: number
    fiscal_receipt_url?: string | null
    fiscal_status?: string | null
}) => {
    closePaymentWidget();
    receiptModalUrl.value = payload.fiscal_receipt_url || null;
    receiptModalAmount.value = payload.amount;
    receiptPaymentId.value = payload.paymentId || null;
    receiptFiscalStatus.value = payload.fiscal_status || null;
    receiptIsStub.value = !!payload.fiscal_status && payload.fiscal_status === 'skipped'
        || !!(payload.fiscal_receipt_url && String(payload.fiscal_receipt_url).includes('/receipt/stub/'));
    isReceiptModalOpen.value = true;
    try {
        sessionStorage.setItem('reactor_receipt', JSON.stringify({
            url: receiptModalUrl.value,
            amount: receiptModalAmount.value,
            paymentId: receiptPaymentId.value,
            fiscalStatus: receiptFiscalStatus.value,
            isStub: receiptIsStub.value,
            at: Date.now(),
        }))
    } catch { /* ignore */ }
    fetchDashboardData();
    router.reload({ only: ['auth', 'transactions'], preserveScroll: true });
}

const openTxReceipt = (tx: any) => {
    receiptModalUrl.value = tx?.fiscal_receipt_url || null
    receiptModalAmount.value = tx?.amount ?? null
    receiptPaymentId.value = tx?.payment_uuid || null
    receiptFiscalStatus.value = tx?.fiscal_status || null
    receiptIsStub.value = !!tx?.is_stub_receipt
    isReceiptModalOpen.value = true
}

const restoreReceiptModal = () => {
    try {
        const raw = sessionStorage.getItem('reactor_receipt')
        if (!raw) return
        const data = JSON.parse(raw)
        sessionStorage.removeItem('reactor_receipt')
        if (!data || Date.now() - Number(data.at || 0) > 180000) return
        receiptModalUrl.value = data.url || null
        receiptModalAmount.value = data.amount ?? null
        receiptPaymentId.value = data.paymentId || null
        receiptFiscalStatus.value = data.fiscalStatus || null
        receiptIsStub.value = !!data.isStub
        isReceiptModalOpen.value = true
    } catch { /* ignore */ }
}

onMounted(() => {
    restoreReceiptModal()
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

                <div class="bg-[#0a0a0a] border border-[#22c55e]/20 rounded-[1.125rem] p-10 relative overflow-hidden shadow-2xl shadow-[#22c55e]/5">
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

                    <div class="mt-10 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 relative z-10">
                        <button @click="isTopUpInputOpen = true" class="py-4 bg-[#22c55e] text-black font-black rounded-xl text-[9px] tracking-widest hover:scale-105 transition-all uppercase italic">Пополнить</button>
                        <button @click="isQuickStartOpen = true" class="py-4 bg-white/5 border border-[#22c55e]/40 text-[#22c55e] font-black rounded-xl text-[9px] tracking-widest hover:bg-[#22c55e]/10 transition-all uppercase italic">Сесть за ПК</button>
                        <Link href="/booking" class="py-4 bg-white/5 border border-white/10 text-white font-black rounded-xl text-[9px] flex items-center justify-center tracking-widest hover:bg-white/10 transition-all uppercase italic">Бронь</Link>
                        <Link href="/shop" class="py-4 bg-white/5 border border-white/10 text-white font-black rounded-xl text-[9px] flex items-center justify-center tracking-widest hover:bg-white/10 transition-all uppercase italic">Маркет</Link>
                        <button @click="openReviewModal" class="py-4 bg-white/5 border border-yellow-500/40 text-yellow-500 font-black rounded-xl text-[9px] tracking-widest hover:bg-yellow-500/10 transition-all uppercase italic">Бонус</button>
                        <button @click="openGameRequestModal" class="py-4 bg-white/5 border border-cyan-500/40 text-cyan-400 font-black rounded-xl text-[9px] tracking-widest hover:bg-cyan-500/10 transition-all uppercase italic">Хочу игру</button>
                    </div>
                </div>

                <div v-if="activeBookings.length > 0" class="space-y-4">
                    <div
                        v-for="b in activeBookings"
                        :key="b.id"
                        class="bg-[#0a0a0a] border rounded-[1rem] p-8 relative overflow-hidden group transition-colors"
                        :class="resolveBookingPhase(b) === 'late_waiting'
                            ? 'border-red-500/40 hover:border-red-500/70'
                            : 'border-[#3b82f6]/40 hover:border-[#3b82f6]'"
                    >
                        <div
                            class="absolute inset-0 pointer-events-none"
                            :class="resolveBookingPhase(b) === 'late_waiting'
                                ? 'bg-gradient-to-r from-red-500/8 to-transparent'
                                : 'bg-gradient-to-r from-[#3b82f6]/5 to-transparent'"
                        ></div>

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
                                <span
                                    class="text-[10px] uppercase block mb-2 font-bold italic border-b pb-1"
                                    :class="resolveBookingPhase(b) === 'late_waiting'
                                        ? 'text-red-400 border-red-500/20'
                                        : 'text-[#22c55e] border-[#22c55e]/20'"
                                >{{ ['waiting', 'late_waiting'].includes(resolveBookingPhase(b))
                                    ? 'Статус'
                                    : 'Осталось времени' }}</span>
                                <div
                                    class="text-3xl font-black font-mono tracking-tighter"
                                    :class="bookingTimeClass(b)"
                                >
                                    {{ getRemainingTime(b) }}
                                </div>
                                <div
                                    v-if="resolveBookingPhase(b) === 'late_billing'"
                                    class="mt-2 text-[10px] font-mono text-white/35 italic"
                                >
                                    время списывается — войдите на ПК
                                </div>
                            </div>
                        </div>

                        <div v-if="b.game_label" class="mt-6 pt-5 border-t border-white/5 relative z-10 flex flex-wrap items-center gap-x-3 gap-y-2">
                            <span class="text-[10px] text-white/30 uppercase font-bold italic tracking-widest">Игры</span>
                            <span class="text-sm font-black uppercase italic text-white/90 tracking-tight">{{ b.game_label }}</span>
                        </div>

                        <div
                            v-if="b.can_cancel || resolveBookingPhase(b) === 'waiting'"
                            class="mt-6 pt-5 border-t border-white/5 relative z-10 flex flex-wrap items-center justify-between gap-3"
                        >
                            <button
                                v-if="b.can_cancel"
                                type="button"
                                class="px-5 py-3 rounded-xl border border-red-500/40 text-red-400 text-[10px] font-black uppercase tracking-widest hover:bg-red-500/10 transition-all disabled:opacity-50"
                                :disabled="cancellingBookingId === b.id"
                                @click="cancelBooking(b)"
                            >
                                {{ cancellingBookingId === b.id ? 'Отмена…' : 'Отменить бронь' }}
                            </button>
                            <span
                                v-else
                                class="text-[10px] text-white/25 font-mono italic"
                            >
                                Самоотмена недоступна (менее {{ Math.round((b.cancel_before_minutes || 120) / 60) }} ч до старта)
                            </span>
                        </div>
                    </div>
                </div>

                <div
                    v-if="cancelError"
                    class="px-5 py-4 rounded-2xl border border-red-500/30 bg-red-500/10 text-red-400 text-xs font-black uppercase tracking-wider"
                >
                    {{ cancelError }}
                </div>
                <div class="bg-[#0a0a0a] border border-white/5 rounded-[1.125rem] p-10 shadow-xl">
                    <span class="text-[10px] uppercase text-white/40 tracking-[0.4em] font-black italic block mb-10">Лог транзакций</span>
                    <div v-if="transactions.length > 0" class="space-y-6">
                        <div v-for="tx in transactions" :key="tx.id" class="flex items-center justify-between group transition-all gap-4">
                            <div class="flex items-center gap-6 min-w-0">
                                <div :class="['w-12 h-12 rounded-2xl flex items-center justify-center border transition-colors shrink-0', tx.amount > 0 ? 'bg-[#22c55e]/5 border-[#22c55e]/20 text-[#22c55e]' : 'bg-white/5 border-white/10 text-white/20 group-hover:border-white/20']">
                                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path :d="tx.amount > 0 ? 'M12 6v12m6-6H6' : 'M18 12H6'" stroke-width="2.5" stroke-linecap="round"/></svg>
                                </div>
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2 min-w-0">
                                        <div class="text-sm font-black uppercase italic tracking-tight group-hover:text-[#22c55e] transition-colors truncate">{{ tx.description }}</div>
                                        <span
                                            v-if="tx.is_no_show"
                                            class="shrink-0 px-2 py-0.5 rounded-md border border-red-500/40 bg-red-500/10 text-[9px] font-black uppercase tracking-widest text-red-400"
                                        >Просрочена</span>
                                    </div>
                                    <div class="text-[10px] text-white/20 font-mono mt-1 italic">{{ tx.date }}</div>
                                </div>
                            </div>
                            <div class="flex items-center gap-4 shrink-0">
                                <button
                                    v-if="tx.has_receipt || tx.fiscal_status || tx.type === 'deposit'"
                                    type="button"
                                    class="px-3 py-2 rounded-xl border text-[9px] font-black uppercase tracking-widest transition-colors"
                                    :class="tx.has_receipt
                                        ? 'border-[#22c55e]/30 text-[#22c55e] hover:bg-[#22c55e]/10'
                                        : 'border-white/15 text-white/35 hover:bg-white/5'"
                                    @click="openTxReceipt(tx)"
                                >{{
                                    tx.has_receipt
                                        ? (tx.is_stub_receipt ? 'Чек · демо' : 'Чек')
                                        : (tx.fiscal_status === 'deferred' ? 'После входа' : 'Статус')
                                }}</button>
                                <div :class="['text-xl font-black italic font-mono tracking-tighter', tx.amount > 0 ? 'text-[#22c55e]' : 'text-white/40']">
                                    {{ tx.amount > 0 ? '+' : '' }}{{ tx.amount }} ₽
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-else class="text-center py-10 text-white/10 italic uppercase text-[10px] tracking-widest border border-dashed border-white/5 rounded-2xl">История пуста</div>
                </div>
            </div>

            <div class="space-y-8 md:sticky md:top-28 md:self-start">
                <div class="bg-[#0a0a0a] border border-white/5 rounded-[1.125rem] p-10 flex flex-col items-center shadow-xl">
                    <div class="w-32 h-32 rounded-full bg-black flex items-center justify-center text-5xl font-black text-[#22c55e] italic border-2 border-[#22c55e]/30 mb-6 overflow-hidden shadow-[0_0_40px_rgba(34,197,94,0.1)]">
                        <img v-if="page.props.user?.avatar" :src="`/images/avatars/${page.props.user.avatar}`" class="w-full h-full object-cover" />
                        <span v-else>{{ (page.props.user?.name || 'S')[0] }}</span>
                    </div>
                    <h3 class="text-3xl font-black uppercase italic tracking-tighter text-white text-center">{{ page.props.user?.name }}</h3>
                    <div class="mt-4 px-6 py-2 bg-[#22c55e]/10 border border-[#22c55e]/20 rounded-full text-[10px] text-[#22c55e] font-black uppercase italic tracking-widest">СТАЛКЕР</div>
                </div>

                <div v-if="achievements.length > 0" class="bg-[#0a0a0a] border border-purple-500/20 rounded-[1.125rem] p-8 shadow-xl">
                    <span class="text-[10px] uppercase text-purple-400 tracking-[0.4em] font-black italic block mb-6">Достижения и трофеи</span>
                    <div class="space-y-4">
                        <div v-for="a in achievements" :key="a.id"
                             class="border rounded-2xl p-4 transition-colors"
                             :class="a.completed ? 'border-purple-500/40 bg-purple-500/[0.06]' : 'border-white/5 bg-black/40'">
                            <div class="flex items-start justify-between gap-3 mb-3">
                                <div class="min-w-0">
                                    <div class="text-xs font-black uppercase italic tracking-tight text-white">{{ a.title }}</div>
                                    <div v-if="a.description" class="text-[10px] text-white/30 mt-1 leading-snug">{{ a.description }}</div>
                                    <div class="text-[8px] uppercase font-black tracking-widest text-white/25 mt-2">
                                        {{ a.period_label }} · +{{ Math.floor(a.reward_value) }} {{ rewardSuffix(a.reward_type) }}
                                    </div>
                                </div>
                                <div class="text-right shrink-0">
                                    <div class="text-base font-black font-mono italic"
                                         :class="a.completed ? 'text-purple-400' : 'text-white/70'">
                                        {{ a.progress }}<span class="text-white/30 text-xs">/{{ a.target }}</span>
                                    </div>
                                    <div v-if="a.rewarded" class="text-[8px] uppercase font-black text-emerald-400 tracking-widest mt-1">Получено</div>
                                    <div v-else-if="a.completed" class="text-[8px] uppercase font-black text-purple-300 tracking-widest mt-1">Готово</div>
                                </div>
                            </div>
                            <div class="h-1.5 rounded-full bg-white/5 overflow-hidden">
                                <div class="h-full rounded-full transition-all duration-700"
                                     :class="a.completed ? 'bg-purple-500' : 'bg-[#22c55e]'"
                                     :style="{ width: `${a.percent}%` }"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <nav class="flex flex-col gap-4">
                    <Link href="/account/profile" class="p-6 bg-[#0a0a0a] border border-white/5 rounded-[0.875rem] flex items-center justify-between hover:bg-white/5 transition-all group">
                        <span class="text-[10px] font-black uppercase text-white/40 group-hover:text-white transition-colors italic tracking-widest">Настройки профиля</span>
                        <svg class="w-6 h-6 text-[#22c55e] transform group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M9 5l7 7-7 7"/></svg>
                    </Link>
                    <button @click="router.post('/logout')" class="p-6 bg-[#0a0a0a] border border-white/5 rounded-[0.875rem] flex items-center justify-between hover:bg-red-500/10 transition-all group">
                        <span class="text-[10px] font-black uppercase text-white/40 group-hover:text-red-500 transition-colors italic tracking-widest">Завершить рейд</span>
                        <svg class="w-6 h-6 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M17 16l4-4m0 0l-4-4m4 4H7"/></svg>
                    </button>
                </nav>
            </div>
        </div>

        <Teleport to="body">
            <div v-if="isTopUpInputOpen" class="fixed inset-0 flex items-center justify-center z-[9998] p-6 animate-in fade-in duration-300">
                <div class="absolute inset-0 bg-black/95 backdrop-blur-xl" @click="isTopUpInputOpen = false"></div>
                <div class="relative max-w-md w-full bg-[#0a0a0a] border border-[#22c55e]/30 rounded-[1.25rem] p-12 text-center shadow-[0_0_120px_rgba(34,197,94,0.2)]">
                    <h2 class="text-[#22c55e] text-4xl font-black uppercase italic mb-10 tracking-tighter">Reactor Pay</h2>
                    <div class="grid grid-cols-3 gap-3 mb-8">
                        <button v-for="amount in [500, 1000, 2000]" :key="amount" @click="topUpAmount = amount"
                                :class="['py-4 rounded-2xl font-black transition-all italic text-[12px]', topUpAmount === amount ? 'bg-[#22c55e] text-black' : 'bg-white/5 text-white border border-white/10']">
                            {{ amount }}
                        </button>
                    </div>
                    <input v-model="topUpAmount" type="number" class="w-full bg-black border-2 border-white/5 rounded-[1rem] py-8 text-6xl font-black text-center text-white mb-8 outline-none focus:border-[#22c55e]/50 transition-colors" />
                    <div class="mb-8">
                        <PaymentReceiptConsent v-model="sendReceipt" pay-label="Подтвердить" />
                    </div>
                    <button
                        @click="proceedToPayment"
                        :disabled="isPaymentProcessing"
                        class="w-full py-7 bg-[#22c55e] text-black font-black uppercase rounded-[1rem] italic hover:bg-[#2ae06d] transition-colors shadow-[0_0_20px_rgba(34,197,94,0.3)] disabled:cursor-wait disabled:opacity-60"
                    >{{ isPaymentProcessing ? 'Подготовка...' : 'Подтвердить' }}</button>
                </div>
            </div>

            <div v-if="isQuickStartOpen" class="fixed inset-0 flex items-center justify-center z-[9998] p-6 animate-in zoom-in duration-300">
                <div class="absolute inset-0 bg-black/95 backdrop-blur-xl" @click="isQuickStartOpen = false"></div>
                <div class="relative max-w-md w-full bg-[#0a0a0a] border border-[#22c55e]/30 rounded-[1.25rem] p-12 text-center shadow-[0_0_80px_rgba(34,197,94,0.1)]">
                    <h2 class="text-[#22c55e] text-4xl font-black uppercase italic mb-10 tracking-tighter">Вход в узел</h2>
                    <input v-model="quickStartPc" type="number" placeholder="№ ПК" class="w-full bg-black border-2 border-white/5 rounded-[1rem] py-10 text-7xl font-black text-center text-[#22c55e] mb-12 outline-none focus:border-[#22c55e]/50 transition-colors" />
                    <button @click="isQuickStartOpen = false" class="w-full py-7 bg-[#22c55e] text-black font-black uppercase rounded-[1rem] italic shadow-[0_0_20px_rgba(34,197,94,0.2)]">Подключиться</button>
                </div>
            </div>

            <div v-if="isReviewModalOpen" class="fixed inset-0 flex items-center justify-center z-[9998] p-6 animate-in fade-in duration-300">
                <div class="absolute inset-0 bg-black/95 backdrop-blur-xl" @click="isReviewModalOpen = false"></div>
                <div class="relative max-w-md w-full bg-[#0a0a0a] border border-yellow-500/30 rounded-[1.25rem] p-10 sm:p-12 text-center shadow-[0_0_120px_rgba(234,179,8,0.15)]">
                    <h2 class="text-yellow-500 text-4xl font-black uppercase italic mb-3 tracking-tighter">Бонус +{{ bonusAmount }}₽</h2>
                    <p class="text-white/30 text-[10px] uppercase tracking-[0.25em] font-black italic mb-6 leading-relaxed">
                        Оставь отзыв 5★ на картах и вставь сюда его текст. Проверяем раз в сутки.
                    </p>

                    <div class="flex flex-col sm:flex-row gap-2 mb-6">
                        <a v-if="reviewMeta.yandex_maps_url" :href="reviewMeta.yandex_maps_url" target="_blank" rel="noopener"
                           class="flex-1 py-3 px-4 rounded-xl border border-yellow-500/30 bg-yellow-500/10 text-yellow-500 text-[9px] font-black uppercase tracking-widest italic hover:bg-yellow-500/20 transition-colors">
                            Яндекс.Карты
                        </a>
                        <a v-if="reviewMeta.twogis_url" :href="reviewMeta.twogis_url" target="_blank" rel="noopener"
                           class="flex-1 py-3 px-4 rounded-xl border border-white/15 bg-white/5 text-white/70 text-[9px] font-black uppercase tracking-widest italic hover:bg-white/10 transition-colors">
                            2ГИС
                        </a>
                    </div>

                    <div v-if="reviewStatus === 'pending'" class="mb-8 p-5 rounded-2xl bg-yellow-500/10 border border-yellow-500/20 text-yellow-500 text-[11px] uppercase font-black italic tracking-widest">
                        Заявка на проверке — ждём публикацию
                    </div>
                    <div v-else-if="reviewStatus === 'approved'" class="mb-6 p-4 rounded-2xl bg-[#22c55e]/10 border border-[#22c55e]/20 text-[#22c55e] text-[10px] uppercase font-black italic tracking-widest">
                        Последний бонус начислен — можно отправить новый отзыв
                    </div>
                    <div v-else-if="reviewStatus === 'rejected' || reviewStatus === 'expired'" class="mb-6 p-4 rounded-2xl bg-red-500/10 border border-red-500/20 text-red-400 text-[10px] uppercase font-black italic tracking-widest">
                        Предыдущая заявка не прошла — можно отправить снова
                    </div>

                    <template v-if="canSubmitReview">
                        <textarea
                            v-model="reviewText"
                            rows="5"
                            :placeholder="`Текст отзыва как на картах (от ${minReviewLength} символов)`"
                            class="w-full bg-black border-2 border-white/5 rounded-[0.875rem] py-5 px-6 text-sm font-mono text-white mb-4 outline-none focus:border-yellow-500/50 transition-colors placeholder:text-white/15 resize-none text-left"
                        />
                        <div v-if="reviewError" class="mb-4 text-red-400 text-[10px] uppercase font-black italic tracking-widest">{{ reviewError }}</div>
                        <button
                            type="button"
                            :disabled="isReviewSubmitting"
                            @click="submitReview"
                            class="w-full py-7 bg-yellow-500 text-black font-black uppercase rounded-[1rem] italic hover:bg-yellow-400 transition-colors shadow-[0_0_20px_rgba(234,179,8,0.3)] disabled:opacity-50"
                        >
                            {{ isReviewSubmitting ? 'Отправка...' : 'Отправить на проверку' }}
                        </button>
                    </template>
                    <button
                        v-else
                        type="button"
                        @click="isReviewModalOpen = false"
                        class="w-full py-7 bg-white/5 border border-white/10 text-white/50 font-black uppercase rounded-[1rem] italic hover:bg-white/10 transition-colors"
                    >
                        Закрыть
                    </button>
                </div>
            </div>

            <div v-if="isGameRequestOpen" class="fixed inset-0 flex items-center justify-center z-[9998] p-6 animate-in fade-in duration-300">
                <div class="absolute inset-0 bg-black/95 backdrop-blur-xl" @click="isGameRequestOpen = false"></div>
                <div class="relative max-w-md w-full bg-[#0a0a0a] border border-cyan-500/30 rounded-[1.25rem] p-10 sm:p-12 text-center shadow-[0_0_120px_rgba(34,211,238,0.12)]">
                    <h2 class="text-cyan-400 text-4xl font-black uppercase italic mb-3 tracking-tighter">Хочу игру</h2>
                    <p class="text-white/30 text-[10px] uppercase tracking-[0.25em] font-black italic mb-8 leading-relaxed">
                        Напиши тайтл, которого нет на дисках. Если наберётся спрос — поставим.
                    </p>
                    <div v-if="gameRequestSuccess" class="mb-6 p-4 rounded-2xl bg-cyan-500/10 border border-cyan-500/30 text-cyan-300 text-[10px] uppercase font-black italic tracking-widest">
                        {{ gameRequestSuccess }}
                    </div>
                    <template v-else>
                        <input
                            v-model="gameRequestTitle"
                            type="text"
                            maxlength="120"
                            placeholder="Название игры"
                            class="w-full bg-black border-2 border-white/5 rounded-[0.875rem] py-5 px-6 text-sm font-mono text-white mb-4 outline-none focus:border-cyan-500/50 transition-colors placeholder:text-white/15 text-left"
                        />
                        <textarea
                            v-model="gameRequestComment"
                            rows="3"
                            maxlength="500"
                            placeholder="Комментарий (необязательно)"
                            class="w-full bg-black border-2 border-white/5 rounded-[0.875rem] py-5 px-6 text-sm font-mono text-white mb-4 outline-none focus:border-cyan-500/50 transition-colors placeholder:text-white/15 resize-none text-left"
                        />
                        <div v-if="gameRequestError" class="mb-4 text-red-400 text-[10px] uppercase font-black italic tracking-widest">{{ gameRequestError }}</div>
                        <button
                            type="button"
                            :disabled="isGameRequestSubmitting"
                            @click="submitGameRequest"
                            class="w-full py-7 bg-cyan-400 text-black font-black uppercase rounded-[1rem] italic hover:bg-cyan-300 transition-colors disabled:opacity-40"
                        >
                            {{ isGameRequestSubmitting ? 'Отправка...' : 'Отправить заявку' }}
                        </button>
                    </template>
                </div>
            </div>

            <YooKassaWidgetModal
                :is-open="isPaymentWidgetOpen"
                :confirmation-token="paymentToken"
                :payment-id="paymentId"
                :amount="topUpAmount"
                @close="closePaymentWidget"
                @paid="handlePaymentPaid"
            />
            <FiscalReceiptModal
                :is-open="isReceiptModalOpen"
                :receipt-url="receiptModalUrl"
                :amount="receiptModalAmount"
                :payment-id="receiptPaymentId"
                :fiscal-status="receiptFiscalStatus"
                :is-stub="receiptIsStub"
                @close="isReceiptModalOpen = false"
            />
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
