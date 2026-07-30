<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, nextTick, watch } from 'vue'
import { Link, usePage, router } from '@inertiajs/vue3'
import axios from 'axios' // <--- Прямой импорт (решает проблему с window.axios)

import ConfirmModal from '@/Components/ConfirmModal.vue'
import SmsModal from '@/Components/SmsModal.vue'
import PaymentModal from '@/Components/PaymentModal.vue'

const page = usePage()

const isRolling = ref(false)
const isPhoneModalOpen = ref(false)
const isSmsModalOpen = ref(false)
const authPhone = ref('')
const smsModalRef = ref<InstanceType<typeof SmsModal> | null>(null)

// --- ПОПОЛНЕНИЕ (заглушка оплаты) ---
const isTopUpInputOpen = ref(false)
const isPaymentProcessing = ref(false)
const topUpAmount = ref(500)
const paymentMethod = ref<'card' | 'sbp'>('sbp')
const paymentData = ref<any>({})
const localBalance = ref<number | null>(null)

const displayBalance = computed(() => {
    if (localBalance.value !== null) return localBalance.value
    const props = page.props as any
    const fromAuth = props.auth?.user?.balance
    return parseFloat(String(fromAuth ?? 0)) || 0
})

const isAuthenticated = computed(() => !!(page.props.auth?.user || page.props.user))

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

const openTopUp = () => {
    if (!isAuthenticated.value) {
        isPhoneModalOpen.value = true
        return
    }
    topUpAmount.value = 500
    paymentMethod.value = 'sbp'
    isTopUpInputOpen.value = true
}

const proceedToPayment = async () => {
    if (topUpAmount.value < 100) return

    isTopUpInputOpen.value = false
    isPaymentProcessing.value = true
    paymentData.value = {
        mode: 'topup',
        price: topUpAmount.value,
        date: new Date().toLocaleDateString('ru-RU'),
    }

    try {
        // Payment stub: backend credits deposit_balance as if acquiring succeeded
        const { data } = await axios.post('/api/billing/topup', {
            amount: topUpAmount.value,
            method: paymentMethod.value,
        })
        const next = parseFloat(String(data.new_balance ?? data.deposit_balance ?? data.balance ?? 0))
        if (!isNaN(next)) localBalance.value = next
        router.reload({ only: ['auth', 'transactions'], preserveScroll: true })
    } catch (e: any) {
        isPaymentProcessing.value = false
        alert(e.response?.data?.message || 'Сбой транзакции пополнения')
    }
}

const handleLogout = () => {
    if (confirm('ВНИМАНИЕ: Разорвать соединение с Sector 0451?')) {
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

const triggerRoll = async () => {
    if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return
    isRolling.value = false
    await nextTick()
    setTimeout(() => { isRolling.value = true }, 30)
}

onMounted(() => {
    setTimeout(() => { triggerRoll() }, 500)
})

onUnmounted(() => {
    stopOrderPolling()
})
</script>

<template>
    <main class="min-h-screen bg-[#050505] text-slate-200 font-mono overflow-x-hidden relative flex flex-col">

        <div class="fixed inset-0 pointer-events-none z-[100] opacity-[0.02] bg-[linear-gradient(rgba(18,16,16,0)_50%,rgba(0,0,0,0.25)_50%),linear-gradient(90deg,rgba(255,0,0,0.06),rgba(0,255,0,0.02),rgba(0,0,0,0.06))] bg-[length:100%_4px,3px_100%]"></div>

        <header class="bg-black/80 backdrop-blur-2xl sticky top-0 z-50 py-4 sm:py-6 flex-shrink-0">
            <div class="max-w-[1600px] mx-auto px-4 sm:px-6 flex flex-col items-center gap-4 sm:gap-5 relative text-center">

                <Link href="/" class="flex items-center justify-center cursor-pointer select-none" @click="triggerRoll">
                    <h1 class="flex items-center justify-center">
                        <span class="sector-neon text-[34px] sm:text-[52px] lg:text-[68px] uppercase leading-none tracking-tighter italic font-bomber">Sector</span>
                        <div class="slot-container ml-2 sm:ml-4 lg:ml-6 flex items-center justify-center relative border border-white/5 rounded-lg overflow-hidden bg-black/50">
                            <div class="slot-inner flex w-full h-full">
                                <div v-for="(digit, index) in [0, 4, 5, 1]" :key="index" class="digit-box border-r border-white/5 last:border-0">
                                    <div class="digit-strip" :class="[`strip-${digit}`, { 'roll-active': isRolling }]" :style="{ animationDelay: isRolling ? `${index * 150}ms` : '0ms' }">
                                        <span v-for="n in 20" :key="n" class="d-cell font-mono font-black italic">{{ (n - 1) % 10 }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </h1>
                </Link>

                <nav class="flex flex-wrap items-center justify-center gap-2 sm:gap-3">
                    <Link href="/booking" class="nav-btn" :class="{ 'active': $page.url.startsWith('/booking') }">Бронирование</Link>
                    <Link href="/" class="nav-btn" :class="{ 'active': $page.url === '/' }">Главная</Link>

                    <template v-if="isAuthenticated">
                        <Link href="/account/dashboard" class="nav-btn" :class="{ 'active': $page.url.startsWith('/account') }">Кабинет</Link>
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
                        <button type="button" @click="handleLogout" class="nav-btn !text-red-500 !border-red-500/20 hover:!bg-red-500 hover:!text-white">Выйти</button>
                    </template>

                    <template v-else>
                        <button type="button" @click="isPhoneModalOpen = true" class="nav-btn !text-[#22c55e] !border-[#22c55e]/30 hover:!bg-[#22c55e] hover:!text-black">Войти</button>
                    </template>
                </nav>
            </div>
        </header>

        <div class="flex-grow w-full py-6 sm:py-10 flex flex-col items-center px-4 sm:px-6">
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
                <div class="relative max-w-md w-full bg-[#0a0a0a] border border-[#22c55e]/30 rounded-[3.5rem] p-12 text-center shadow-[0_0_120px_rgba(34,197,94,0.2)]">
                    <h2 class="text-[#22c55e] text-4xl font-black uppercase italic mb-8 tracking-tighter">Reactor Pay</h2>
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
                        class="w-full bg-black border-2 border-white/5 rounded-[2.5rem] py-8 text-6xl font-black text-center text-white mb-6 outline-none focus:border-[#22c55e]/50 transition-colors"
                    />
                    <div class="grid grid-cols-2 gap-3 mb-8">
                        <button
                            type="button"
                            @click="paymentMethod = 'sbp'"
                            :class="['py-3 rounded-xl font-black uppercase text-[10px] tracking-widest italic border', paymentMethod === 'sbp' ? 'bg-[#22c55e]/10 border-[#22c55e] text-[#22c55e]' : 'border-white/10 text-white/40']"
                        >СБП</button>
                        <button
                            type="button"
                            @click="paymentMethod = 'card'"
                            :class="['py-3 rounded-xl font-black uppercase text-[10px] tracking-widest italic border', paymentMethod === 'card' ? 'bg-[#22c55e]/10 border-[#22c55e] text-[#22c55e]' : 'border-white/10 text-white/40']"
                        >Карта</button>
                    </div>
                    <button
                        type="button"
                        @click="proceedToPayment"
                        class="w-full py-7 bg-[#22c55e] text-black font-black uppercase rounded-[2.5rem] italic hover:bg-[#2ae06d] transition-colors shadow-[0_0_20px_rgba(34,197,94,0.3)]"
                    >Оплатить</button>
                </div>
            </div>

            <PaymentModal
                v-if="isPaymentProcessing"
                :is-open="isPaymentProcessing"
                mode="topup"
                :data="paymentData"
                @close="isPaymentProcessing = false"
            />
        </Teleport>
    </main>
</template>

<style scoped>
@reference "../../css/app.css";

.font-bomber { font-family: 'BomberEscort', sans-serif; }
.sector-neon { color: #000; -webkit-text-stroke: 1.2px #22c55e; text-shadow: 0 0 5px rgba(34, 197, 94, 0.8), 0 0 20px rgba(34, 197, 94, 0.4); filter: brightness(1.2); font-family: 'BomberEscort', sans-serif; }

/* Геометрия слот-машины считается от высоты ячейки, поэтому цифры
   остаются выровненными на любом размере экрана. */
.slot-container { --cell: 38px; width: calc(var(--cell) * 2.87); height: var(--cell); }
@media (min-width: 640px) { .slot-container { --cell: 56px; } }
@media (min-width: 1024px) { .slot-container { --cell: 72px; } }

.digit-box { width: 25%; height: 100%; position: relative; overflow: hidden; }
.digit-strip { display: flex; flex-direction: column; will-change: transform; width: 100%; }
.d-cell {
    height: var(--cell);
    line-height: var(--cell);
    font-size: calc(var(--cell) * 0.78);
    display: flex; align-items: center; justify-content: center;
    color: #000;
    -webkit-text-stroke: calc(var(--cell) * 0.028) #22c55e;
    paint-order: stroke fill;
}

.strip-0 { transform: translateY(0); }
.strip-1 { transform: translateY(calc(var(--cell) * -1)); }
.strip-4 { transform: translateY(calc(var(--cell) * -4)); }
.strip-5 { transform: translateY(calc(var(--cell) * -5)); }

.roll-active { animation-duration: 2.5s; animation-timing-function: cubic-bezier(0.45, 0.05, 0.55, 0.95); animation-fill-mode: both; }
.strip-0.roll-active { animation-name: roll-0; } .strip-4.roll-active { animation-name: roll-4; } .strip-5.roll-active { animation-name: roll-5; } .strip-1.roll-active { animation-name: roll-1; }

@keyframes roll-0 { from { transform: translateY(0); } 100% { transform: translateY(calc(var(--cell) * -10)); } }
@keyframes roll-1 { from { transform: translateY(calc(var(--cell) * -1)); } 100% { transform: translateY(calc(var(--cell) * -11)); } }
@keyframes roll-4 { from { transform: translateY(calc(var(--cell) * -4)); } 100% { transform: translateY(calc(var(--cell) * -14)); } }
@keyframes roll-5 { from { transform: translateY(calc(var(--cell) * -5)); } 100% { transform: translateY(calc(var(--cell) * -15)); } }

@media (prefers-reduced-motion: reduce) {
    .roll-active { animation: none !important; }
}

.nav-btn {
    @apply px-4 py-2.5 sm:px-6 sm:py-3 border border-white/10 rounded-xl text-[10px] sm:text-[11px] font-black transition-all cursor-pointer uppercase tracking-widest italic;
    font-family: Arial, Helvetica, sans-serif;
}
@media (min-width: 1024px) { .nav-btn { min-width: 170px; } }
.nav-btn.active { @apply bg-[#22c55e] text-black border-transparent shadow-[0_0_20px_rgba(34,197,94,0.4)]; }

.nav-meta {
    @apply inline-flex items-center gap-2 sm:gap-3 px-4 py-2.5 sm:px-6 sm:py-3 border border-white/10 rounded-xl
           text-[10px] sm:text-[11px] font-black uppercase tracking-widest italic text-white/70 bg-white/[0.03] box-border;
    font-family: Arial, Helvetica, sans-serif;
}
.nav-meta-name {
    @apply truncate max-w-[9rem] sm:max-w-[14rem];
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
}
</style>
