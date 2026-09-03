<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { Link, usePage, router } from '@inertiajs/vue3'
import axios from 'axios' // <--- Прямой импорт (решает проблему с window.axios)

import ConfirmModal from '@/Components/ConfirmModal.vue'
import SmsModal from '@/Components/SmsModal.vue'
import FlashToast from '@/Components/FlashToast.vue'
import YooKassaWidgetModal from '@/Components/YooKassaWidgetModal.vue'
import PaymentReceiptConsent from '@/Components/PaymentReceiptConsent.vue'
import FiscalReceiptModal from '@/Components/FiscalReceiptModal.vue'
import QrScannerModal from '@/Components/QrScannerModal.vue'
import AvatarWatermarkBg from '@/Components/AvatarWatermarkBg.vue'
import FlipDigit from '@/Components/FlipDigit.vue'

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
const isClientApp = /CompClubClient/i.test(navigator.userAgent || '')
const apkNoticeOpen = ref(false)
let apkNoticeTimer: ReturnType<typeof setTimeout> | null = null

const onApkDownloadClick = () => {
    if (apkNoticeTimer) clearTimeout(apkNoticeTimer)
    apkNoticeTimer = setTimeout(() => {
        apkNoticeOpen.value = true
    }, 1000)
}
const isBookingPage = computed(() => String(page.url || '').startsWith('/booking'))
const isAccountPage = computed(() => String(page.url || '').startsWith('/account'))
const isShopPage = computed(() => String(page.url || '').startsWith('/shop'))
const contentPadClass = computed(() => {
    if (isAccountPage.value) return 'py-0 sm:py-6 lg:py-10 px-0 sm:px-4 lg:px-6'
    if (isBookingPage.value) return 'py-2 sm:py-6 lg:py-10 px-1 sm:px-4 lg:px-6'
    if (isShopPage.value) return 'py-2 sm:py-6 lg:py-10 px-[3px] sm:px-4 lg:px-6'
    return 'py-6 sm:py-10 px-4 sm:px-6'
})

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
const flipTick = ref(0)

let flipTimer: ReturnType<typeof setInterval> | null = null

const triggerFlip = () => {
    flipTick.value += 1
}

const startFlipClock = () => {
    stopFlipClock()
    flipTimer = setInterval(triggerFlip, FLIP_EVERY_MS)
}

const stopFlipClock = () => {
    if (flipTimer) {
        clearInterval(flipTimer)
        flipTimer = null
    }
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
    stopFlipClock()
    if (apkNoticeTimer) clearTimeout(apkNoticeTimer)
})
</script>

<template>
    <main class="min-h-screen bg-[#050505] text-slate-200 font-mono overflow-x-hidden relative flex flex-col">
        <AvatarWatermarkBg />

        <div class="fixed inset-0 pointer-events-none z-[100] opacity-[0.02] bg-[linear-gradient(rgba(18,16,16,0)_50%,rgba(0,0,0,0.25)_50%),linear-gradient(90deg,rgba(255,0,0,0.06),rgba(0,255,0,0.02),rgba(0,0,0,0.06))] bg-[length:100%_4px,3px_100%]"></div>

        <header class="sticky top-0 z-50 flex-shrink-0 pt-3 sm:pt-4 pb-2 sm:pb-3">
            <div class="w-full px-4 sm:px-4 lg:px-6">
            <div class="masthead-bar max-w-7xl mx-auto">
                <div class="masthead">
                    <div class="masthead-row">
                        <Link href="/" class="masthead-brand">
                            <div class="masthead-digits" aria-hidden="true" @click.prevent="triggerFlip">
                                <FlipDigit
                                    v-for="(digit, index) in BRAND_DIGITS"
                                    :key="index"
                                    :value="digit"
                                    :delay="index * 90"
                                    :tick="flipTick"
                                />
                            </div>
                            <span class="masthead-title">компьютерный клуб</span>
                        </Link>
                    </div>
                </div>
            </div>
            </div>
        </header>

        <div class="relative z-10 w-full px-4 sm:px-4 lg:px-6 pb-3 sm:pb-4">
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
                    <a
                        v-if="!isClientApp"
                        href="/app.apk"
                        class="nav-btn nav-btn-download"
                        download="sector0451.apk"
                        @click="onApkDownloadClick"
                    >Скачать приложение</a>
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
            class="relative z-10 flex-grow w-full flex flex-col items-center"
            :class="contentPadClass"
        >
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

            <div
                v-if="apkNoticeOpen"
                class="fixed inset-0 flex items-end sm:items-center justify-center z-[9998] p-4 sm:p-6"
            >
                <div class="absolute inset-0 bg-black/80" @click="apkNoticeOpen = false"></div>
                <div class="relative w-full max-w-md bg-[#0a0a0a] border border-[#22c55e]/40 rounded-[1.25rem] p-6 sm:p-8 shadow-[0_0_80px_rgba(34,197,94,0.2)]">
                    <p class="text-[11px] sm:text-sm text-white/80 uppercase tracking-widest leading-relaxed font-black italic">
                        После завершения загрузки нажмите «Открыть» во всплывающем окне или в шторке уведомлений вверху экрана.
                    </p>
                    <button
                        type="button"
                        class="mt-6 w-full py-4 bg-[#22c55e] text-black font-black uppercase rounded-xl italic tracking-widest"
                        @click="apkNoticeOpen = false"
                    >Понятно</button>
                </div>
            </div>
        </Teleport>

        <FlashToast />
    </main>
</template>

<style scoped>
@reference "../../css/app.css";

.masthead {
    --brand: 2.15rem;
}
@media (min-width: 640px) { .masthead { --brand: 3.6rem; } }
@media (min-width: 1024px) { .masthead { --brand: 4.5rem; } }
.masthead-bar {
    @apply bg-white/5 border border-white/10 rounded-xl;
    padding: 0.45rem 0.65rem;
    overflow: visible;
}
@media (min-width: 640px) {
    .masthead-bar { padding: 0.65rem 1.15rem; }
}
.masthead-row {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    min-width: 0;
}
@media (min-width: 640px) {
    .masthead-row { gap: 0.75rem 1rem; }
}
.masthead-brand {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    min-width: 0;
    max-width: 100%;
    text-decoration: none;
    height: var(--brand);
    overflow: visible;
}
@media (min-width: 640px) {
    .masthead-brand {
        align-items: flex-end;
        gap: 0.9rem;
        flex-shrink: 0;
    }
}
.masthead-digits {
    display: flex;
    gap: 3px;
    height: var(--brand);
    flex-shrink: 0;
    cursor: pointer;
}
.masthead-title {
    display: flex;
    align-items: center;
    min-width: 0;
    flex: 1 1 auto;
    font-family: 'BomberEscort', Arial, Helvetica, sans-serif;
    font-weight: 900;
    font-style: italic;
    font-size: clamp(0.72rem, 4.1vw, calc(var(--brand) * 0.5));
    line-height: 1;
    text-transform: uppercase;
    letter-spacing: -0.05em;
    white-space: nowrap;
    overflow: visible;
    padding-left: 0.28em;
    padding-right: 0.42em;
    margin-left: -0.12em;
    margin-right: -0.12em;
    color: #000;
    -webkit-text-fill-color: #000;
    -webkit-text-stroke: 0;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
    text-rendering: geometricPrecision;
    paint-order: normal;
    text-shadow:
        -1.5px -1.5px 0 #22c55e, 0 -1.5px 0 #22c55e, 1.5px -1.5px 0 #22c55e,
        1.5px 0 0 #22c55e, 1.5px 1.5px 0 #22c55e, 0 1.5px 0 #22c55e,
        -1.5px 1.5px 0 #22c55e, -1.5px 0 0 #22c55e,
        0 0 6px rgba(34, 197, 94, 0.85),
        0 0 18px rgba(34, 197, 94, 0.45);
}
@media (min-width: 640px) {
    .masthead-title {
        align-items: flex-end;
        flex: 0 0 auto;
        font-size: calc(var(--brand) * 0.5);
    }
}
.masthead-tools {
    display: flex;
    align-items: stretch;
    gap: 0.4rem;
    min-width: 0;
    flex: 1 1 100%;
}
@media (min-width: 640px) {
    .masthead-tools { align-items: center; gap: 0.5rem; flex-shrink: 0; }
}
.site-nav {
    display: flex;
    flex-wrap: wrap;
    align-items: stretch;
    gap: 0.5rem;
}
.site-nav-links {
    display: flex;
    flex: 1 1 auto;
    flex-wrap: wrap;
    min-width: 0;
    gap: 0.4rem;
    align-items: stretch;
}
@media (min-width: 640px) {
    .site-nav-links {
        gap: 0.5rem;
    }
    .masthead-tools { flex: 0 0 auto; }
}
.site-nav-links .nav-btn {
    flex: 0 0 auto;
    min-width: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-align: center;
}

.nav-btn {
    @apply px-2.5 py-2.5 sm:px-4 sm:py-3 border border-white/10 rounded-xl text-[9px] sm:text-[11px] font-black transition-all cursor-pointer uppercase tracking-widest italic;
    font-family: Arial, Helvetica, sans-serif;
    white-space: nowrap;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}
.nav-btn.active { @apply bg-[#22c55e] text-black border-transparent shadow-[0_0_20px_rgba(34,197,94,0.4)]; }
.nav-btn-exit { @apply !text-red-500 !border-red-500/20 hover:!bg-red-500 hover:!text-white; }
.nav-btn-enter { @apply !text-[#22c55e] !border-[#22c55e]/30 hover:!bg-[#22c55e] hover:!text-black; }
.nav-btn-download {
    @apply !text-[#22c55e] !border-[#22c55e]/30 hover:!bg-[#22c55e]/15;
    text-decoration: none;
}

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
    @apply inline-flex items-center gap-1.5 sm:gap-3 px-2.5 py-2 sm:px-4 sm:py-2.5 border border-white/10 rounded-xl
           text-[9px] sm:text-[11px] font-black uppercase tracking-widest italic text-white/70 bg-white/[0.03] box-border min-w-0 flex-1 sm:flex-none;
    font-family: Arial, Helvetica, sans-serif;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}
.nav-meta-name {
    @apply truncate max-w-[4.5rem] sm:max-w-[6rem] lg:max-w-[10rem];
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
</style>
