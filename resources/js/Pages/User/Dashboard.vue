<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { usePage, Link, router } from '@inertiajs/vue3'
import axios from 'axios'
import MainLayout from '@/Layouts/MainLayout.vue'
import ClubMap from '@/Components/ClubMap.vue'
import YooKassaWidgetModal from '@/Components/YooKassaWidgetModal.vue'
import PaymentReceiptConsent from '@/Components/PaymentReceiptConsent.vue'
import FiscalReceiptModal from '@/Components/FiscalReceiptModal.vue'

import { useClubName } from '@/Composables/useClubName'

const page = usePage()
const clubName = useClubName()
const userName = computed(() => {
    const props = page.props as any
    return String(props.auth?.user?.name || props.user?.name || 'игрок').trim() || 'игрок'
})

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

watch(
    () => page.props.server_time,
    (value) => {
        if (typeof value === 'string' && value.length > 0) {
            const parsed = new Date(value).getTime()
            if (!Number.isNaN(parsed)) {
                currentTime.value = parsed
            }
        }
    }
)

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
        only: ['user', 'auth', 'transactions', 'active_bookings', 'orders', 'latest_review', 'review_meta', 'achievements', 'server_time'],
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
const isTransferOpen = ref(false)
const transferTargets = ref<any[]>([])
const transferLoading = ref(false)
const transferPreview = ref<any>(null)
const transferTargetId = ref<number | null>(null)
const transferError = ref('')
const transferBusy = ref(false)
const transferMapConfig = ref<any>(null)
const transferComputers = ref<any[]>([])
const transferOccupiedIds = ref<string[]>([])
const transferSelectableIds = ref<string[]>([])
const transferFromComputerId = ref<number | null>(null)
const transferDone = ref<{ pin: string; pcName: string } | null>(null)

const transferSelectedIds = computed(() => {
    const ids: string[] = []
    if (transferFromComputerId.value) ids.push(String(transferFromComputerId.value))
    if (transferTargetId.value) ids.push(String(transferTargetId.value))
    return ids
})

const transferSelectedMeta = computed(() =>
    transferTargets.value.find((t: any) => t.id === transferTargetId.value) || null
)

const hasLiveSession = computed(() =>
    activeBookings.value.some((b: any) => b.status === 'active' || b.is_started || b.phase === 'active')
)

const openSeatAction = async () => {
    if (hasLiveSession.value) {
        await openTransferModal()
        return
    }
    isQuickStartOpen.value = true
}

const openTransferModal = async () => {
    transferError.value = ''
    transferPreview.value = null
    transferTargetId.value = null
    transferDone.value = null
    transferMapConfig.value = null
    transferComputers.value = []
    transferOccupiedIds.value = []
    transferSelectableIds.value = []
    transferFromComputerId.value = null
    isTransferOpen.value = true
    transferLoading.value = true
    try {
        const { data } = await axios.get('/account/transfer/targets')
        transferTargets.value = data.targets || []
        transferMapConfig.value = data.map_config || null
        transferComputers.value = data.computers || []
        transferOccupiedIds.value = (data.occupied_ids || []).map(String)
        transferSelectableIds.value = (data.selectable_ids || []).map(String)
        transferFromComputerId.value = data.from_computer_id ? Number(data.from_computer_id) : null
    } catch (e: any) {
        transferError.value = e?.response?.data?.message || 'Не удалось загрузить ПК'
        transferTargets.value = []
    } finally {
        transferLoading.value = false
    }
}

const onTransferMapToggle = (id: string) => {
    if (!transferSelectableIds.value.includes(String(id))) return
    void selectTransferTarget(Number(id))
}

const selectTransferTarget = async (id: number) => {
    if (transferDone.value) return
    transferTargetId.value = id
    transferError.value = ''
    transferBusy.value = true
    try {
        const { data } = await axios.post('/account/transfer/preview', { target_computer_id: id })
        transferPreview.value = data.preview
    } catch (e: any) {
        transferPreview.value = null
        transferError.value = e?.response?.data?.message || 'Ошибка расчёта'
    } finally {
        transferBusy.value = false
    }
}

const confirmTransfer = async () => {
    if (!transferTargetId.value || transferDone.value) return
    transferBusy.value = true
    transferError.value = ''
    try {
        const { data } = await axios.post('/account/transfer/confirm', {
            target_computer_id: transferTargetId.value,
        })
        const pin = String(data.pin_code || data.result?.pin_code || '')
        const pcName = String(data.to?.name || data.result?.to?.name || transferSelectedMeta.value?.name || '')
        transferDone.value = { pin, pcName }
        transferPreview.value = null
    } catch (e: any) {
        transferError.value = e?.response?.data?.message || 'Не удалось пересесть'
    } finally {
        transferBusy.value = false
    }
}

const finishTransferModal = () => {
    isTransferOpen.value = false
    window.location.reload()
}
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
    try { sessionStorage.removeItem('reactor_receipt') } catch { /* ignore */ }
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

const closeReceiptModal = () => {
    isReceiptModalOpen.value = false
    receiptModalUrl.value = null
    receiptModalAmount.value = null
    receiptPaymentId.value = null
    receiptFiscalStatus.value = null
    receiptIsStub.value = false
    try { sessionStorage.removeItem('reactor_receipt') } catch { /* ignore */ }
}

onMounted(() => {
    try { sessionStorage.removeItem('reactor_receipt') } catch { /* ignore */ }
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
        <div class="cabinet max-w-7xl mx-auto w-full font-mono pb-16 sm:pb-20 text-white animate-in fade-in duration-700">
            <div class="grid grid-cols-1 md:grid-cols-3 md:gap-6 lg:gap-8 md:px-0">

            <div class="md:col-span-2 flex flex-col gap-px md:gap-6 bg-white/[0.06] md:bg-transparent">

                <div class="cabinet-block bg-[#0a0a0a] border-0 md:border md:border-[#22c55e]/20 rounded-none md:rounded-[1.125rem] p-4 sm:p-8 md:p-10 relative md:shadow-2xl md:shadow-[#22c55e]/5">

                    <span class="text-[10px] uppercase text-[#22c55e] tracking-[0.35em] font-black italic relative z-10">Баланс {{ userName }}</span>
                    <div class="mt-2 sm:mt-4 flex items-baseline gap-2 sm:gap-4 relative z-10">
                        <span class="text-5xl sm:text-8xl font-black italic tracking-tighter text-white drop-shadow-[0_0_25px_rgba(34,197,94,0.4)] leading-none">
                            {{ Math.floor(displayBalance) }}
                        </span>
                        <span class="text-xl sm:text-3xl font-bold text-[#22c55e] uppercase italic">RUB</span>
                    </div>

                    <div class="mt-5 sm:mt-10 grid grid-cols-3 gap-1.5 sm:gap-3 relative z-10">
                        <button @click="isTopUpInputOpen = true" class="py-3.5 sm:py-4 px-1 bg-white/5 border border-[#22c55e]/40 text-[#22c55e] font-black rounded-lg sm:rounded-xl text-[11px] sm:text-sm tracking-wide hover:bg-[#22c55e]/10 transition-all uppercase italic leading-tight">Пополнить</button>
                        <button @click="openSeatAction" class="py-3.5 sm:py-4 px-1 bg-white/5 border border-[#22c55e]/40 text-[#22c55e] font-black rounded-lg sm:rounded-xl text-[11px] sm:text-sm tracking-wide hover:bg-[#22c55e]/10 transition-all uppercase italic leading-tight">
                            {{ hasLiveSession ? 'Пересесть' : 'Сесть за ПК' }}
                        </button>
                        <Link href="/booking" class="py-3.5 sm:py-4 px-1 bg-white/5 border border-white/10 text-white font-black rounded-lg sm:rounded-xl text-[11px] sm:text-sm flex items-center justify-center tracking-wide hover:bg-white/10 transition-all uppercase italic leading-tight">Бронь</Link>
                        <Link href="/shop" class="py-3.5 sm:py-4 px-1 bg-white/5 border border-white/10 text-white font-black rounded-lg sm:rounded-xl text-[11px] sm:text-sm flex items-center justify-center tracking-wide hover:bg-white/10 transition-all uppercase italic leading-tight">Маркет</Link>
                        <button @click="openReviewModal" class="py-3.5 sm:py-4 px-1 bg-white/5 border border-yellow-500/40 text-yellow-500 font-black rounded-lg sm:rounded-xl text-[11px] sm:text-sm tracking-wide hover:bg-yellow-500/10 transition-all uppercase italic leading-tight">Бонус</button>
                        <button @click="openGameRequestModal" class="py-3.5 sm:py-4 px-1 bg-white/5 border border-cyan-500/40 text-cyan-400 font-black rounded-lg sm:rounded-xl text-[11px] sm:text-sm tracking-wide hover:bg-cyan-500/10 transition-all uppercase italic leading-tight">Хочу игру</button>
                    </div>
                </div>

                <div
                    v-for="b in activeBookings"
                    :key="b.id"
                    class="cabinet-block bg-[#0a0a0a] border-0 md:border rounded-none md:rounded-[1rem] p-4 sm:p-6 md:p-8 relative overflow-hidden group transition-colors"
                    :class="resolveBookingPhase(b) === 'late_waiting'
                        ? 'md:border-red-500/40 hover:md:border-red-500/70'
                        : 'md:border-[#3b82f6]/40 hover:md:border-[#3b82f6]'"
                >
                        <div
                            class="absolute inset-0 pointer-events-none"
                            :class="resolveBookingPhase(b) === 'late_waiting'
                                ? 'bg-gradient-to-r from-red-500/8 to-transparent'
                                : 'bg-gradient-to-r from-[#3b82f6]/5 to-transparent'"
                        ></div>

                        <div class="grid grid-cols-2 gap-x-3 gap-y-4 md:grid-cols-4 md:gap-6 relative z-10">
                            <div>
                                <span class="text-[9px] sm:text-[10px] text-white/30 uppercase block mb-1.5 font-bold italic">Объект</span>
                                <div class="text-2xl sm:text-3xl font-black italic text-white uppercase leading-none">ПК №{{ b.formatted_pc }}</div>
                            </div>

                            <div>
                                <span class="text-[9px] sm:text-[10px] text-white/30 uppercase block mb-1.5 font-bold italic">PIN-КОД</span>
                                <div class="text-2xl sm:text-3xl font-mono font-black text-[#22c55e] tracking-widest drop-shadow-[0_0_10px_rgba(34,197,94,0.2)] leading-none">
                                    {{ b.pin_code || '—' }}
                                </div>
                            </div>

                            <div class="text-left md:text-right">
                                <span class="text-[9px] sm:text-[10px] text-white/30 uppercase block mb-1.5 font-bold italic">Старт</span>
                                <div class="text-xl sm:text-2xl font-black text-[#3b82f6] font-mono leading-none">{{ formatStartTime(b.start_time) }}</div>
                            </div>

                            <div class="text-left md:text-right">
                                <span
                                    class="text-[9px] sm:text-[10px] uppercase block mb-1.5 font-bold italic border-b pb-1"
                                    :class="resolveBookingPhase(b) === 'late_waiting'
                                        ? 'text-red-400 border-red-500/20'
                                        : 'text-[#22c55e] border-[#22c55e]/20'"
                                >{{ ['waiting', 'late_waiting'].includes(resolveBookingPhase(b))
                                    ? 'Статус'
                                    : 'Осталось' }}</span>
                                <div
                                    class="text-2xl sm:text-3xl font-black font-mono tracking-tighter leading-none"
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

                        <div v-if="b.game_label" class="mt-4 sm:mt-6 pt-4 sm:pt-5 border-t border-white/5 relative z-10 flex flex-wrap items-center gap-x-3 gap-y-2">
                            <span class="text-[10px] text-white/30 uppercase font-bold italic tracking-widest">Игры</span>
                            <span class="text-sm font-black uppercase italic text-white/90 tracking-tight">{{ b.game_label }}</span>
                        </div>

                        <div
                            v-if="b.can_cancel || resolveBookingPhase(b) === 'waiting'"
                            class="mt-4 sm:mt-6 pt-4 sm:pt-5 border-t border-white/5 relative z-10 flex flex-wrap items-center justify-between gap-3"
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

                <div
                    v-if="cancelError"
                    class="px-4 py-3 bg-red-500/10 text-red-400 text-xs font-black uppercase tracking-wider md:rounded-2xl md:border md:border-red-500/30"
                >
                    {{ cancelError }}
                </div>

                <div class="cabinet-block bg-[#0a0a0a] border-0 md:border md:border-white/5 rounded-none md:rounded-[1.125rem] p-4 sm:p-8 md:p-10 md:shadow-xl">
                    <span class="text-[10px] uppercase text-white/40 tracking-[0.35em] font-black italic block mb-5 sm:mb-8">Лог транзакций</span>
                    <div v-if="transactions.length > 0" class="space-y-4 sm:space-y-6">
                        <div v-for="tx in transactions" :key="tx.id" class="flex items-center justify-between group transition-all gap-3 sm:gap-4">
                            <div class="flex items-center gap-3 sm:gap-6 min-w-0">
                                <div :class="['w-10 h-10 sm:w-12 sm:h-12 rounded-xl sm:rounded-2xl flex items-center justify-center border transition-colors shrink-0', tx.amount > 0 ? 'bg-[#22c55e]/5 border-[#22c55e]/20 text-[#22c55e]' : 'bg-white/5 border-white/10 text-white/20 group-hover:border-white/20']">
                                    <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path :d="tx.amount > 0 ? 'M12 6v12m6-6H6' : 'M18 12H6'" stroke-width="2.5" stroke-linecap="round"/></svg>
                                </div>
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2 min-w-0">
                                        <div class="text-xs sm:text-sm font-black uppercase italic tracking-tight group-hover:text-[#22c55e] transition-colors truncate">{{ tx.description }}</div>
                                        <span
                                            v-if="tx.is_no_show"
                                            class="shrink-0 px-2 py-0.5 rounded-md border border-red-500/40 bg-red-500/10 text-[9px] font-black uppercase tracking-widest text-red-400"
                                        >Просрочена</span>
                                    </div>
                                    <div class="text-[10px] text-white/20 font-mono mt-1 italic">{{ tx.date }}</div>
                                </div>
                            </div>
                            <div class="flex items-center gap-2 sm:gap-4 shrink-0">
                                <button
                                    v-if="tx.has_receipt || tx.fiscal_status || tx.type === 'deposit'"
                                    type="button"
                                    class="px-2.5 sm:px-3 py-1.5 sm:py-2 rounded-lg sm:rounded-xl border text-[8px] sm:text-[9px] font-black uppercase tracking-widest transition-colors"
                                    :class="tx.has_receipt
                                        ? 'border-[#22c55e]/30 text-[#22c55e] hover:bg-[#22c55e]/10'
                                        : 'border-white/15 text-white/35 hover:bg-white/5'"
                                    @click="openTxReceipt(tx)"
                                >{{
                                    tx.has_receipt
                                        ? (tx.is_stub_receipt ? 'Чек · демо' : 'Чек')
                                        : (tx.fiscal_status === 'deferred' ? 'После входа' : 'Статус')
                                }}</button>
                                <div :class="['text-base sm:text-xl font-black italic font-mono tracking-tighter', tx.amount > 0 ? 'text-[#22c55e]' : 'text-white/40']">
                                    {{ tx.amount > 0 ? '+' : '' }}{{ Math.round(Number(tx.amount)) }} ₽
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-else class="text-center py-8 sm:py-10 text-white/10 italic uppercase text-[10px] tracking-widest border border-dashed border-white/5 rounded-xl">История пуста</div>
                </div>
            </div>

            <div class="flex flex-col gap-px md:gap-6 md:space-y-0 bg-white/[0.06] md:bg-transparent md:sticky md:top-28 md:self-start mt-px md:mt-0">
                <div class="cabinet-block bg-[#0a0a0a] border-0 md:border md:border-white/5 rounded-none md:rounded-[1.125rem] p-4 sm:p-8 md:p-10 flex flex-row md:flex-col items-center gap-4 md:gap-0 md:shadow-xl">
                    <div class="w-16 h-16 md:w-32 md:h-32 rounded-full bg-black flex items-center justify-center text-2xl md:text-5xl font-black text-[#22c55e] italic border-2 border-[#22c55e]/30 md:mb-6 overflow-hidden shadow-[0_0_40px_rgba(34,197,94,0.1)] shrink-0">
                        <img v-if="page.props.user?.avatar" :src="`/images/avatars/${page.props.user.avatar}`" class="w-full h-full object-cover" />
                        <span v-else>{{ (page.props.user?.name || 'S')[0] }}</span>
                    </div>
                    <div class="min-w-0 flex-1 md:flex-none text-left md:text-center">
                        <h3 class="text-xl md:text-3xl font-black uppercase italic tracking-tighter text-white truncate">{{ page.props.user?.name }}</h3>
                        <div class="mt-2 md:mt-4 inline-flex px-4 md:px-6 py-1.5 md:py-2 bg-[#22c55e]/10 border border-[#22c55e]/20 rounded-full text-[9px] md:text-[10px] text-[#22c55e] font-black uppercase italic tracking-widest">СТАЛКЕР</div>
                    </div>
                </div>

                <div v-if="achievements.length > 0" class="cabinet-block bg-[#0a0a0a] border-0 md:border md:border-purple-500/20 rounded-none md:rounded-[1.125rem] p-4 sm:p-6 md:p-8 md:shadow-xl">
                    <span class="text-[10px] uppercase text-purple-400 tracking-[0.35em] font-black italic block mb-4 sm:mb-6">Достижения и трофеи</span>
                    <div class="space-y-3 sm:space-y-4">
                        <div v-for="a in achievements" :key="a.id"
                             class="border rounded-xl sm:rounded-2xl p-3 sm:p-4 transition-colors"
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

                <nav class="flex flex-col gap-px md:gap-3 bg-white/[0.06] md:bg-transparent">
                    <Link href="/account/profile" class="p-4 sm:p-5 md:p-6 bg-[#0a0a0a] border-0 md:border md:border-white/5 rounded-none md:rounded-[0.875rem] flex items-center justify-between hover:bg-white/5 transition-all group">
                        <span class="text-[10px] font-black uppercase text-white/40 group-hover:text-white transition-colors italic tracking-widest">Настройки профиля</span>
                        <svg class="w-5 h-5 sm:w-6 sm:h-6 text-[#22c55e] transform group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M9 5l7 7-7 7"/></svg>
                    </Link>
                    <button @click="router.post('/logout')" class="p-4 sm:p-5 md:p-6 bg-[#0a0a0a] border-0 md:border md:border-white/5 rounded-none md:rounded-[0.875rem] flex items-center justify-between hover:bg-red-500/10 transition-all group">
                        <span class="text-[10px] font-black uppercase text-white/40 group-hover:text-red-500 transition-colors italic tracking-widest">Завершить рейд</span>
                        <svg class="w-5 h-5 sm:w-6 sm:h-6 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M17 16l4-4m0 0l-4-4m4 4H7"/></svg>
                    </button>
                </nav>
            </div>
            </div>
        </div>

        <Teleport to="body">
            <div v-if="isTopUpInputOpen" class="fixed inset-0 flex items-center justify-center z-[9998] p-6 animate-in fade-in duration-300">
                <div class="absolute inset-0 bg-black/95 backdrop-blur-xl" @click="isTopUpInputOpen = false"></div>
                <div class="relative max-w-md w-full bg-[#0a0a0a] border border-[#22c55e]/30 rounded-[1.25rem] p-12 text-center shadow-[0_0_120px_rgba(34,197,94,0.2)]">
                    <h2 class="text-[#22c55e] text-4xl font-black uppercase italic mb-10 tracking-tighter">{{ clubName }} Pay</h2>
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

            <div v-if="isTransferOpen" class="fixed inset-0 flex items-center justify-center z-[9998] p-4 sm:p-6 animate-in fade-in duration-300">
                <div class="absolute inset-0 bg-black/95 backdrop-blur-xl" @click="transferDone ? finishTransferModal() : (isTransferOpen = false)"></div>
                <div class="relative max-w-3xl w-full bg-[#0a0a0a] border border-cyan-500/30 rounded-[1.25rem] p-6 sm:p-8 shadow-[0_0_80px_rgba(6,182,212,0.12)] max-h-[92vh] overflow-y-auto">
                    <template v-if="transferDone">
                        <h2 class="text-cyan-400 text-3xl font-black uppercase italic mb-2 tracking-tighter">Готово</h2>
                        <p class="text-white/30 text-[10px] uppercase tracking-widest font-black mb-8">
                            Войдите PIN на {{ transferDone.pcName || 'новом ПК' }}
                        </p>
                        <div class="mb-10 text-center">
                            <div class="text-[10px] text-white/30 uppercase tracking-widest font-black mb-3">Новый PIN</div>
                            <div class="text-7xl font-mono font-black text-[#22c55e] tracking-[0.35em] drop-shadow-[0_0_20px_rgba(34,197,94,0.35)]">
                                {{ transferDone.pin || '—' }}
                            </div>
                        </div>
                        <button type="button" @click="finishTransferModal"
                                class="w-full py-4 bg-cyan-500 text-black uppercase font-black rounded-xl text-[10px] cursor-pointer">
                            Понятно
                        </button>
                    </template>
                    <template v-else>
                        <h2 class="text-cyan-400 text-3xl font-black uppercase italic mb-2 tracking-tighter">Пересадка</h2>
                        <p class="text-white/30 text-[10px] uppercase tracking-widest font-black mb-6">Карта клуба · клик по свободному ПК</p>

                        <div v-if="transferLoading" class="py-10 text-center text-white/40 text-xs uppercase font-black">Загрузка…</div>
                        <div v-else-if="!transferTargets.length" class="py-10 text-center text-white/40 text-xs uppercase font-black">Нет свободных ПК</div>
                        <template v-else>
                            <div class="w-full h-[min(52vh,420px)] mb-4">
                                <ClubMap
                                    :mapConfig="transferMapConfig"
                                    :computers="transferComputers"
                                    :occupiedIds="transferOccupiedIds"
                                    :selectedIds="transferSelectedIds"
                                    @toggle-seat="onTransferMapToggle"
                                />
                            </div>
                            <p v-if="transferSelectedMeta" class="mb-4 text-[10px] text-white/50 uppercase tracking-widest font-black">
                                Цель: {{ transferSelectedMeta.name }}
                                · {{ transferSelectedMeta.zone || 'зона —' }}
                                · {{ Math.round(transferSelectedMeta.hourly_rate) }} ₽/ч
                            </p>
                            <p v-else class="mb-4 text-[10px] text-white/30 uppercase tracking-widest font-black">
                                Ваш ПК подсвечен · выберите свободный
                            </p>
                        </template>

                        <div v-if="transferPreview" class="mb-6 p-4 rounded-xl border border-amber-500/30 bg-amber-500/10 text-amber-200 text-sm">
                            {{ transferPreview.warning }}
                            <div class="text-[10px] uppercase tracking-widest mt-2 text-amber-200/60">
                                Доплата: {{ Math.round(Number(transferPreview.charge || 0)) }} ₽
                                · баланс после: {{ Math.round(Number(transferPreview.balance_after || 0)) }} ₽
                            </div>
                        </div>
                        <div v-if="transferError" class="mb-4 text-red-400 text-xs font-bold">{{ transferError }}</div>

                        <div class="flex gap-3">
                            <button type="button" @click="isTransferOpen = false" class="flex-1 py-4 border border-white/10 text-white/40 uppercase font-black rounded-xl text-[10px] cursor-pointer">Отмена</button>
                            <button type="button" @click="confirmTransfer" :disabled="!transferPreview || transferBusy"
                                    class="flex-[2] py-4 bg-cyan-500 text-black uppercase font-black rounded-xl text-[10px] cursor-pointer disabled:opacity-40">
                                {{ transferBusy ? '…' : 'Подтвердить' }}
                            </button>
                        </div>
                    </template>
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
                @close="closeReceiptModal"
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
