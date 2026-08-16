<script setup lang="ts">
import { ref, computed, nextTick, onMounted, onUnmounted, watch } from 'vue'
import { Link, usePage, router } from '@inertiajs/vue3'
import axios from 'axios' // <--- Прямой импорт (решает проблему с window.axios)

import ConfirmModal from '@/Components/ConfirmModal.vue'
import SmsModal from '@/Components/SmsModal.vue'
import FlashToast from '@/Components/FlashToast.vue'
import YooKassaWidgetModal from '@/Components/YooKassaWidgetModal.vue'
import PaymentReceiptConsent from '@/Components/PaymentReceiptConsent.vue'
import FiscalReceiptModal from '@/Components/FiscalReceiptModal.vue'
import QrScannerModal from '@/Components/QrScannerModal.vue'

import { useClubName } from '@/Composables/useClubName'

const page = usePage()
const clubName = useClubName()

const isPhoneModalOpen = ref(false)
const isSmsModalOpen = ref(false)
const authPhone = ref('')
const smsModalRef = ref<InstanceType<typeof SmsModal> | null>(null)
const isQrScannerOpen = ref(false)
const qrScannerRef = ref<InstanceType<typeof QrScannerModal> | null>(null)
const pendingQrTopUp = ref(false)

// --- ПОПОЛНЕНИЕ (ЮKassa) ---
const isTopUpInputOpen = ref(false)
const isPaymentProcessing = ref(false)
const isPaymentWidgetOpen = ref(false)
const paymentToken = ref('')
const paymentId = ref('')
const topUpAmount = ref(500)
const paymentMethod = ref<'card' | 'sbp'>('card')
const sendReceipt = ref(false)
const isReceiptModalOpen = ref(false)
const receiptModalUrl = ref<string | null>(null)
const receiptModalAmount = ref<number | null>(null)
const receiptPaymentId = ref<string | null>(null)
const receiptFiscalStatus = ref<string | null>(null)
const receiptIsStub = ref(false)
const localBalance = ref<number | null>(null)

const clearReceiptSession = () => {
    try {
        sessionStorage.removeItem('reactor_receipt')
    } catch { /* ignore */ }
}

const closeReceiptModal = () => {
    isReceiptModalOpen.value = false
    receiptModalUrl.value = null
    receiptModalAmount.value = null
    receiptPaymentId.value = null
    receiptFiscalStatus.value = null
    receiptIsStub.value = false
    clearReceiptSession()
}

const displayBalance = computed(() => {
    if (localBalance.value !== null) return localBalance.value
    const props = page.props as any
    const fromAuth = props.auth?.user?.balance
    return parseFloat(String(fromAuth ?? 0)) || 0
})

const isAuthenticated = computed(() => !!(page.props.auth?.user || page.props.user))
const isBookingPage = computed(() => String(page.url || '').startsWith('/booking'))
const isAccountPage = computed(() => String(page.url || '').startsWith('/account'))
const contentPadClass = computed(() => {
    if (isAccountPage.value) return 'py-0 sm:py-6 lg:py-10 px-0 sm:px-4 lg:px-6'
    if (isBookingPage.value) return 'py-2 sm:py-6 lg:py-10 px-1 sm:px-4 lg:px-6'
    return 'py-6 sm:py-10 px-4 sm:px-6'
})

type ActiveOrder = {
    id: number
    status: string
    status_label: string
    product_name?: string
    pc_name?: string
}

const activeOrders = ref<ActiveOrder[]>([])
const hasActiveOrder = computed(() => activeOrders.value.length > 0)
const activeOrderLabel = computed(() => {
    if (!activeOrders.value.length) return ''
    if (activeOrders.value.length > 1) {
        return `Заказ в работе · ${activeOrders.value.length}`
    }
    return 'Заказ в работе'
})
const activeOrderHint = computed(() => {
    const first = activeOrders.value[0]
    if (!first) return ''
    const name = first.product_name || ''
    const pc = first.pc_name ? ` → ${first.pc_name}` : ''
    return `${name}${pc}`.trim()
})

let orderPollTimer: ReturnType<typeof setInterval> | null = null

const fetchActiveOrders = async () => {
    if (!isAuthenticated.value) {
        activeOrders.value = []
        return
    }
    try {
        const { data } = await axios.get('/api/shop/active-orders')
        activeOrders.value = Array.isArray(data?.orders) ? data.orders : []
    } catch {
        // тихо — индикатор не критичен
    }
}

const startOrderPolling = () => {
    stopOrderPolling()
    fetchActiveOrders()
    orderPollTimer = setInterval(fetchActiveOrders, 5000)
    window.addEventListener('shop-order-placed', fetchActiveOrders)
}

const stopOrderPolling = () => {
    if (orderPollTimer) {
        clearInterval(orderPollTimer)
        orderPollTimer = null
    }
    window.removeEventListener('shop-order-placed', fetchActiveOrders)
}

watch(isAuthenticated, (ok) => {
    if (ok) startOrderPolling()
    else {
        stopOrderPolling()
        activeOrders.value = []
    }
}, { immediate: true })

const openTopUp = (suggestedAmount?: number) => {
    if (!isAuthenticated.value) {
        isPhoneModalOpen.value = true
        return
    }
    const amount = typeof suggestedAmount === 'number' && Number.isFinite(suggestedAmount) && suggestedAmount >= 100
        ? Math.ceil(suggestedAmount)
        : 500
    topUpAmount.value = amount
    paymentMethod.value = 'card'
    sendReceipt.value = false
    isTopUpInputOpen.value = true
}

const openQrScanner = () => {
    if (!isAuthenticated.value) {
        isPhoneModalOpen.value = true
        return
    }
    isQrScannerOpen.value = true
}

const closeQrScanner = () => {
    isQrScannerOpen.value = false
    pendingQrTopUp.value = false
}

const onQrRequestTopUp = (amount: number) => {
    pendingQrTopUp.value = true
    openTopUp(amount)
}

const onQrActivated = () => {
    router.reload({
        only: ['auth', 'transactions', 'active_bookings', 'server_time'],
        preserveScroll: true,
    })
}

const proceedToPayment = async () => {
    if (topUpAmount.value < 100) return

    isTopUpInputOpen.value = false
    isPaymentProcessing.value = true

    try {
        const { data } = await axios.post('/api/billing/topup', {
            amount: topUpAmount.value,
            method: paymentMethod.value,
            return_to: window.location.pathname + window.location.search,
            send_receipt: sendReceipt.value,
        })
        if (data.confirmation_token && data.payment_id) {
            paymentToken.value = data.confirmation_token
            paymentId.value = data.payment_id
            isPaymentWidgetOpen.value = true
            return
        }
        alert('Не удалось открыть форму оплаты')
    } catch (e: any) {
        alert(e.response?.data?.message || 'Сбой транзакции пополнения')
    } finally {
        isPaymentProcessing.value = false
    }
}

const closePaymentWidget = () => {
    isPaymentWidgetOpen.value = false
    paymentToken.value = ''
    paymentId.value = ''
}

const handlePaymentPaid = (payload: {
    paymentId: string
    amount: number
    fiscal_receipt_url?: string | null
    fiscal_status?: string | null
}) => {
    closePaymentWidget()
    receiptModalUrl.value = payload.fiscal_receipt_url || null
    receiptModalAmount.value = payload.amount
    receiptPaymentId.value = payload.paymentId || null
    receiptFiscalStatus.value = payload.fiscal_status || null
    receiptIsStub.value = payload.fiscal_status === 'skipped'
        || !!(payload.fiscal_receipt_url && String(payload.fiscal_receipt_url).includes('/receipt/stub/'))
    isReceiptModalOpen.value = true
    clearReceiptSession()
    if (typeof payload.amount === 'number' && localBalance.value !== null) {
        localBalance.value = localBalance.value + payload.amount
    } else if (typeof payload.amount === 'number') {
        localBalance.value = displayBalance.value + payload.amount
    }
    router.reload({
        only: ['auth', 'transactions'],
        preserveScroll: true,
        onFinish: () => {
            if (pendingQrTopUp.value && qrScannerRef.value) {
                void qrScannerRef.value.refreshAfterTopUp?.()
            }
        },
    })
}

const handleLogout = () => {
    if (confirm(`ВНИМАНИЕ: Разорвать соединение с ${clubName.value}?`)) {
        router.post('/logout', {}, {
            onFinish: () => { window.location.href = '/' }
        })
    }
}

// --- УСИЛЕННЫЙ МЕТОД С ДИАГНОСТИКОЙ ---
const handlePhoneConfirm = async (payload: any) => {
    authPhone.value = payload.phone || payload

    try {
        console.log('[Sector 0451] Инициализация отправки SMS на номер:', authPhone.value)

        // Отправка запроса через чистый axios
        const response = await axios.post('/auth/send-code', { phone: authPhone.value })

        console.log('[Sector 0451] Успешный ответ сервера:', response.data)

        // Закрываем окно телефона, открываем ввод СМС
        isPhoneModalOpen.value = false
        isSmsModalOpen.value = true

    } catch (e: any) {
        // Жесткий вывод ошибки на экран
        const errorMessage = e.response?.data?.message || e.message || 'Неизвестный сбой сети'
        const statusCode = e.response?.status || 'Нет ответа'

        alert(`СИСТЕМНЫЙ СБОЙ: ОТПРАВКА СМС\n\nКод: HTTP ${statusCode}\nПричина: ${errorMessage}\n\nПроверьте роуты Laravel или консоль (F12).`)
        console.error('Критическая ошибка авторизации:', e)
    }
}

const handleSmsVerify = (code: string) => {
    router.post('/auth/verify-code', {
        phone: authPhone.value,
        code: code
    }, {
        onSuccess: () => { isSmsModalOpen.value = false },
        onError: () => { if (smsModalRef.value) smsModalRef.value.resetError() }
    })
}

const BRAND_DIGITS = [0, 4, 5, 1] as const
const FLIP_EVERY_MS = 20_000
const isRolling = ref(false)

let flipTimer: ReturnType<typeof setInterval> | null = null
let flipKick: ReturnType<typeof setTimeout> | null = null

const prefersReducedMotion = () => {
    try {
        return window.matchMedia('(prefers-reduced-motion: reduce)').matches
    } catch {
        return false
    }
}

const triggerRoll = async () => {
    if (prefersReducedMotion()) return
    isRolling.value = false
    await nextTick()
    if (flipKick) clearTimeout(flipKick)
    flipKick = setTimeout(() => {
        isRolling.value = true
        flipKick = null
    }, 30)
}

const startFlipClock = () => {
    stopFlipClock()
    if (prefersReducedMotion()) return
    void triggerRoll()
    flipTimer = setInterval(() => { void triggerRoll() }, FLIP_EVERY_MS)
}

const stopFlipClock = () => {
    if (flipTimer) {
        clearInterval(flipTimer)
        flipTimer = null
    }
    if (flipKick) {
        clearTimeout(flipKick)
        flipKick = null
    }
    isRolling.value = false
}

onMounted(() => {
    clearReceiptSession()
    try {
        const params = new URLSearchParams(window.location.search)
        if (params.get('qr') && isAuthenticated.value) {
            isQrScannerOpen.value = true
        }
    } catch { /* ignore */ }
    startFlipClock()
})

// Layout живёт между страницами Inertia — гасим попап пополнения/чека при уходе.
watch(() => page.url, () => {
    closeReceiptModal()
    closePaymentWidget()
    isTopUpInputOpen.value = false
    isPaymentProcessing.value = false
})

onUnmounted(() => {
    stopOrderPolling()
    stopFlipClock()
})
</script>

<template>
    <main class="min-h-screen bg-[#050505] text-slate-200 font-mono overflow-x-hidden relative flex flex-col">

        <div class="fixed inset-0 pointer-events-none z-[100] opacity-[0.02] bg-[linear-gradient(rgba(18,16,16,0)_50%,rgba(0,0,0,0.25)_50%),linear-gradient(90deg,rgba(255,0,0,0.06),rgba(0,255,0,0.02),rgba(0,0,0,0.06))] bg-[length:100%_4px,3px_100%]"></div>

        <header class="sticky top-0 z-50 flex-shrink-0 bg-[#050505]/92 backdrop-blur-xl pt-3 sm:pt-4 pb-2 sm:pb-3">
            <div class="w-full px-4 sm:px-4 lg:px-6">
            <div class="max-w-7xl mx-auto">
                <div class="masthead">
                    <div class="masthead-row">
                        <Link href="/" class="masthead-brand">
                            <div class="masthead-digits" aria-hidden="true">
                                <div
                                    v-for="(digit, index) in BRAND_DIGITS"
                                    :key="index"
                                    class="flip-unit"
                                >
                                    <div
                                        class="digit-strip"
                                        :class="[`strip-${digit}`, { 'roll-active': isRolling }]"
                                        :style="{ animationDelay: isRolling ? `${index * 160}ms` : '0ms' }"
                                    >
                                        <span v-for="n in 20" :key="n" class="d-cell">{{ (n - 1) % 10 }}</span>
                                    </div>
                                </div>
                            </div>
                            <span class="masthead-title">
                                <span>компьютерный клуб</span>
                                <svg class="masthead-arrow" viewBox="0 0 90 32" aria-hidden="true">
                                    <g transform="skewX(-18)" fill="#000" stroke="#22c55e" stroke-width="1.6" stroke-linejoin="round">
                                        <polygon points="2,16 18,3 18,29" />
                                        <rect x="24" y="7" width="10" height="18" rx="1" />
                                        <rect x="38.5" y="7" width="9" height="18" rx="1" />
                                        <rect x="52" y="7" width="8.1" height="18" rx="1" />
                                        <rect x="64.6" y="7" width="7.3" height="18" rx="1" />
                                    </g>
                                </svg>
                            </span>
                        </Link>
                    </div>
                </div>
            </div>
            </div>
        </header>

        <div class="w-full px-4 sm:px-4 lg:px-6 pb-3 sm:pb-4">
            <nav class="site-nav max-w-7xl mx-auto">
                <div class="site-nav-links">
                    <Link href="/" class="nav-btn" :class="{ 'active': $page.url === '/' }">Главная</Link>
                    <Link
                        v-if="isAuthenticated"
                        href="/account/dashboard"
                        class="nav-btn"
                        :class="{ 'active': $page.url.startsWith('/account') }"
                    >Кабинет</Link>
                    <Link href="/booking" class="nav-btn" :class="{ 'active': $page.url.startsWith('/booking') }">Бронирование</Link>
                </div>
                <div class="masthead-tools">
                    <button
                        v-if="isAuthenticated"
                        type="button"
                        class="nav-btn-icon md:hidden"
                        :class="{ 'active': isQrScannerOpen }"
                        title="Сканировать QR"
                        aria-label="Сканировать QR"
                        @click="openQrScanner"
                    >
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M3 7V5a2 2 0 0 1 2-2h2" />
                            <path d="M17 3h2a2 2 0 0 1 2 2v2" />
                            <path d="M21 17v2a2 2 0 0 1-2 2h-2" />
                            <path d="M7 21H5a2 2 0 0 1-2-2v-2" />
                            <rect x="7" y="7" width="3.5" height="3.5" rx="0.5" fill="currentColor" stroke="none" />
                            <rect x="13.5" y="7" width="3.5" height="3.5" rx="0.5" fill="currentColor" stroke="none" />
                            <rect x="7" y="13.5" width="3.5" height="3.5" rx="0.5" fill="currentColor" stroke="none" />
                            <path d="M13.5 13.5h1.5v1.5h-1.5zm2 0h1.5v1.5H15.5zm0 2h1.5V17H15.5zm-2 0h1.5V17h-1.5z" fill="currentColor" stroke="none" />
                        </svg>
                    </button>
                    <template v-if="isAuthenticated">
                        <div class="nav-meta">
                            <span class="nav-meta-name">
                                {{ $page.props.auth?.user?.name || $page.props.user?.name || '—' }}
                            </span>
                            <span class="nav-meta-balance">
                                {{ Math.floor(displayBalance) }}<span class="text-[#22c55e] ml-0.5">₽</span>
                            </span>
                            <button
                                type="button"
                                @click="openTopUp"
                                title="Пополнить баланс"
                                class="nav-meta-plus"
                            >+</button>
                        </div>
                        <button type="button" @click="handleLogout" class="nav-btn nav-btn-exit">Выйти</button>
                    </template>
                    <template v-else>
                        <button type="button" @click="isPhoneModalOpen = true" class="nav-btn nav-btn-enter">Войти</button>
                    </template>
                </div>
            </nav>
        </div>

        <div
            class="flex-grow w-full flex flex-col items-center"
            :class="contentPadClass"
        >
            <div
                v-if="isAuthenticated && hasActiveOrder"
                class="order-live-bar w-full max-w-xl mb-6 rounded-2xl overflow-hidden"
            >
                <div class="order-live-inner px-4 sm:px-6 py-3 flex items-center justify-center gap-3 sm:gap-4">
                    <span class="order-live-dot" aria-hidden="true"></span>
                    <div class="text-center min-w-0">
                        <div class="order-live-title">{{ activeOrderLabel }}</div>
                        <div v-if="activeOrderHint" class="order-live-hint truncate">{{ activeOrderHint }}</div>
                    </div>
                    <span class="order-live-dot" aria-hidden="true"></span>
                </div>
            </div>

            <slot />
        </div>

        <Teleport to="body">
            <div v-if="isPhoneModalOpen || isSmsModalOpen" class="fixed inset-0 bg-black/90 backdrop-blur-sm z-[9999900]" @click="isPhoneModalOpen = false; isSmsModalOpen = false"></div>

            <ConfirmModal
                v-if="isPhoneModalOpen"
                :is-open="isPhoneModalOpen"
                mode="auth"
                :data="{}"
                @close="isPhoneModalOpen = false"
                @confirm="handlePhoneConfirm"
            />

            <SmsModal
                v-if="isSmsModalOpen"
                ref="smsModalRef"
                :is-open="isSmsModalOpen"
                :phone="authPhone"
                @close="isSmsModalOpen = false"
                @verify="handleSmsVerify"
            />

            <div v-if="isTopUpInputOpen" class="fixed inset-0 flex items-center justify-center z-[9998] p-6 animate-in fade-in duration-300">
                <div class="absolute inset-0 bg-black/95 backdrop-blur-xl" @click="isTopUpInputOpen = false"></div>
                <div class="relative max-w-md w-full bg-[#0a0a0a] border border-[#22c55e]/30 rounded-[1.25rem] p-12 text-center shadow-[0_0_120px_rgba(34,197,94,0.2)]">
                    <h2 class="text-[#22c55e] text-4xl font-black uppercase italic mb-8 tracking-tighter">{{ clubName }} Pay</h2>
                    <div class="grid grid-cols-3 gap-3 mb-6">
                        <button
                            v-for="amount in [500, 1000, 2000]"
                            :key="amount"
                            type="button"
                            @click="topUpAmount = amount"
                            :class="['py-4 rounded-2xl font-black transition-all italic text-[12px]', topUpAmount === amount ? 'bg-[#22c55e] text-black' : 'bg-white/5 text-white border border-white/10']"
                        >
                            {{ amount }}
                        </button>
                    </div>
                    <input
                        v-model.number="topUpAmount"
                        type="number"
                        min="100"
                        class="w-full bg-black border-2 border-white/5 rounded-[1rem] py-8 text-6xl font-black text-center text-white mb-6 outline-none focus:border-[#22c55e]/50 transition-colors"
                    />
                    <div class="grid grid-cols-2 gap-3 mb-8">
                        <button
                            type="button"
                            disabled
                            class="py-3 rounded-xl font-black uppercase text-[10px] tracking-widest italic border border-white/5 text-white/20 cursor-not-allowed"
                            title="СБП недоступен в тестовом режиме ЮKassa"
                        >СБП</button>
                        <button
                            type="button"
                            @click="paymentMethod = 'card'"
                            :class="['py-3 rounded-xl font-black uppercase text-[10px] tracking-widest italic border', paymentMethod === 'card' ? 'bg-[#22c55e]/10 border-[#22c55e] text-[#22c55e]' : 'border-white/10 text-white/40']"
                        >Карта / ЮMoney</button>
                    </div>
                    <p class="text-[9px] text-white/30 uppercase tracking-widest mb-6">Тестовая ЮKassa: карта или ЮMoney</p>
                    <div class="mb-8 text-left">
                        <PaymentReceiptConsent v-model="sendReceipt" pay-label="Оплатить" />
                    </div>
                    <button
                        type="button"
                        @click="proceedToPayment"
                        :disabled="isPaymentProcessing"
                        class="w-full py-7 bg-[#22c55e] text-black font-black uppercase rounded-[1rem] italic hover:bg-[#2ae06d] transition-colors shadow-[0_0_20px_rgba(34,197,94,0.3)] disabled:cursor-wait disabled:opacity-60"
                    >{{ isPaymentProcessing ? 'Подготовка...' : 'Оплатить' }}</button>
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

            <QrScannerModal
                ref="qrScannerRef"
                :is-open="isQrScannerOpen"
                @close="closeQrScanner"
                @activated="onQrActivated"
                @request-topup="onQrRequestTopUp"
            />
        </Teleport>

        <FlashToast />
    </main>
</template>

<style scoped>
@reference "../../css/app.css";

.masthead {
    --brand: 2.85rem;
}
@media (min-width: 640px) { .masthead { --brand: 3.6rem; } }
@media (min-width: 1024px) { .masthead { --brand: 4.5rem; } }
.masthead-row {
    display: flex;
    align-items: center;
    gap: 0.75rem 1rem;
    flex-wrap: nowrap;
}
.masthead-brand {
    display: flex;
    align-items: center;
    gap: 0.7rem;
    flex-shrink: 0;
    text-decoration: none;
    height: var(--brand);
    overflow: visible;
}
@media (min-width: 640px) {
    .masthead-brand { gap: 0.9rem; }
}
@media (min-width: 640px) {
    .masthead-brand { gap: 0.9rem; }
}
.masthead-digits {
    display: flex;
    gap: 3px;
    height: var(--brand);
    flex-shrink: 0;
}
.flip-unit {
    position: relative;
    width: calc(var(--brand) * 0.72);
    height: var(--brand);
    border: 1px solid rgba(34, 197, 94, 0.38);
    border-radius: 0.4rem;
    background:
        linear-gradient(180deg, rgba(34, 197, 94, 0.16) 0%, rgba(10, 10, 10, 0.9) 55%),
        #050505;
    box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, 0.08),
        0 0 12px rgba(34, 197, 94, 0.08);
    overflow: hidden;
}
.flip-unit::after {
    content: '';
    position: absolute;
    left: 0;
    right: 0;
    top: 50%;
    height: 1px;
    background: rgba(0, 0, 0, 0.72);
    box-shadow: 0 1px 0 rgba(255, 255, 255, 0.06);
    z-index: 6;
    pointer-events: none;
}
.digit-strip {
    display: flex;
    flex-direction: column;
    width: 100%;
    will-change: transform;
}
.d-cell {
    height: var(--brand);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #000;
    -webkit-text-stroke: calc(var(--brand) * 0.028) #22c55e;
    paint-order: stroke fill;
    font-family: Arial, Helvetica, sans-serif;
    font-weight: 900;
    font-style: italic;
    font-size: calc(var(--brand) * 0.62);
    line-height: 1;
    text-shadow: 0 0 8px rgba(34, 197, 94, 0.55);
    flex-shrink: 0;
}
.strip-0 { transform: translateY(0); }
.strip-1 { transform: translateY(calc(var(--brand) * -1)); }
.strip-4 { transform: translateY(calc(var(--brand) * -4)); }
.strip-5 { transform: translateY(calc(var(--brand) * -5)); }
.roll-active {
    animation-duration: 2.6s;
    animation-timing-function: cubic-bezier(0.45, 0.05, 0.55, 0.95);
    animation-fill-mode: both;
}
.strip-0.roll-active { animation-name: roll-0; }
.strip-1.roll-active { animation-name: roll-1; }
.strip-4.roll-active { animation-name: roll-4; }
.strip-5.roll-active { animation-name: roll-5; }
@keyframes roll-0 {
    from { transform: translateY(0); }
    to { transform: translateY(calc(var(--brand) * -10)); }
}
@keyframes roll-1 {
    from { transform: translateY(calc(var(--brand) * -1)); }
    to { transform: translateY(calc(var(--brand) * -11)); }
}
@keyframes roll-4 {
    from { transform: translateY(calc(var(--brand) * -4)); }
    to { transform: translateY(calc(var(--brand) * -14)); }
}
@keyframes roll-5 {
    from { transform: translateY(calc(var(--brand) * -5)); }
    to { transform: translateY(calc(var(--brand) * -15)); }
}
.masthead-title {
    display: flex;
    align-items: center;
    gap: 0.28em;
    height: var(--brand);
    flex-shrink: 0;
    overflow: visible;
    padding-right: 0.15em;
    font-family: 'BomberEscort', Arial, Helvetica, sans-serif;
    font-weight: 900;
    font-style: italic;
    font-size: calc(var(--brand) * 0.78);
    line-height: 1;
    text-transform: uppercase;
    letter-spacing: -0.05em;
    white-space: nowrap;
    color: #000;
    -webkit-text-stroke: 1.2px #22c55e;
    text-shadow: 0 0 5px rgba(34, 197, 94, 0.8), 0 0 20px rgba(34, 197, 94, 0.4);
    paint-order: stroke fill;
}
.masthead-arrow {
    height: 0.7em;
    width: auto;
    flex-shrink: 0;
    overflow: visible;
    filter: drop-shadow(0 0 5px rgba(34, 197, 94, 0.8));
}
.masthead-tools {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    flex-shrink: 0;
}
@media (min-width: 640px) {
    .masthead-tools { gap: 0.5rem; }
}
.site-nav {
    display: flex;
    align-items: stretch;
    gap: 0.5rem;
}
.site-nav-links {
    display: flex;
    flex: 1 1 auto;
    min-width: 0;
    gap: 0.5rem;
}
.site-nav-links .nav-btn {
    flex: 1 1 0;
    min-width: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
}

.nav-btn {
    @apply px-4 py-2.5 sm:px-6 sm:py-3 border border-white/10 rounded-xl text-[10px] sm:text-[11px] font-black transition-all cursor-pointer uppercase tracking-widest italic;
    font-family: Arial, Helvetica, sans-serif;
    white-space: nowrap;
}
.nav-btn.active { @apply bg-[#22c55e] text-black border-transparent shadow-[0_0_20px_rgba(34,197,94,0.4)]; }
.nav-btn-exit { @apply !text-red-500 !border-red-500/20 hover:!bg-red-500 hover:!text-white; }
.nav-btn-enter { @apply !text-[#22c55e] !border-[#22c55e]/30 hover:!bg-[#22c55e] hover:!text-black; }

.nav-btn-icon {
    @apply inline-flex items-center justify-center w-11 h-11 sm:w-12 sm:h-12 border border-white/10 rounded-xl
           text-white/70 transition-all cursor-pointer bg-transparent shrink-0;
}
.nav-btn-icon:hover { @apply border-[#22c55e]/40 text-[#22c55e]; }
.nav-btn-icon.active { @apply bg-[#22c55e] text-black border-transparent shadow-[0_0_20px_rgba(34,197,94,0.4)]; }
@media (min-width: 768px) {
    .nav-btn-icon { display: none !important; }
}

.nav-meta {
    @apply inline-flex items-center gap-2 sm:gap-3 px-3 py-2 sm:px-4 sm:py-2.5 border border-white/10 rounded-xl
           text-[10px] sm:text-[11px] font-black uppercase tracking-widest italic text-white/70 bg-white/[0.03] box-border;
    font-family: Arial, Helvetica, sans-serif;
}
.nav-meta-name {
    @apply truncate max-w-[6rem] lg:max-w-[10rem];
    font-family: inherit;
}
.nav-meta-balance {
    @apply text-white whitespace-nowrap;
    font-family: inherit;
}
.nav-meta-plus {
    @apply -my-1 w-6 h-6 sm:w-7 sm:h-7 rounded-md border border-[#22c55e]/30 bg-[#22c55e]/10 text-[#22c55e]
           text-base leading-none font-black flex items-center justify-center shrink-0
           hover:bg-[#22c55e] hover:text-black transition-all cursor-pointer not-italic;
    font-family: Arial, Helvetica, sans-serif;
}

.order-live-bar {
    background: linear-gradient(90deg, rgba(34, 197, 94, 0.08), rgba(34, 197, 94, 0.22), rgba(34, 197, 94, 0.08));
    border: 1px solid rgba(34, 197, 94, 0.35);
    animation: order-live-glow 2.8s ease-in-out infinite;
}
.order-live-inner {
    font-family: Arial, Helvetica, sans-serif;
}
.order-live-title {
    @apply text-[11px] sm:text-sm font-black uppercase tracking-[0.25em] text-[#22c55e];
    animation: order-live-pulse 2.8s ease-in-out infinite;
}
.order-live-hint {
    @apply text-[10px] sm:text-xs text-white/55 mt-0.5 font-semibold tracking-wide;
}
.order-live-dot {
    width: 8px;
    height: 8px;
    border-radius: 9999px;
    background: #22c55e;
    box-shadow: 0 0 10px rgba(34, 197, 94, 0.8);
    animation: order-live-dot 2.8s ease-in-out infinite;
    flex-shrink: 0;
}
@keyframes order-live-glow {
    0%, 100% { background-color: rgba(34, 197, 94, 0.06); }
    50% { background-color: rgba(34, 197, 94, 0.16); }
}
@keyframes order-live-pulse {
    0%, 100% { opacity: 0.55; }
    50% { opacity: 1; }
}
@keyframes order-live-dot {
    0%, 100% { opacity: 0.35; transform: scale(0.85); }
    50% { opacity: 1; transform: scale(1.15); }
}
@media (prefers-reduced-motion: reduce) {
    .order-live-bar,
    .order-live-title,
    .order-live-dot { animation: none !important; opacity: 1; }
    .roll-active { animation: none !important; }
}
</style>
