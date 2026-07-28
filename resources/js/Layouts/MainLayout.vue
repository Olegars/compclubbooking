<script setup lang="ts">
import { ref, computed, onMounted, nextTick } from 'vue'
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
    const fromGizmo = props.gizmo?.balance
    const fromAuth = props.auth?.user?.balance
    return parseFloat(String(fromGizmo ?? fromAuth ?? 0)) || 0
})

const isAuthenticated = computed(() => !!(page.props.auth?.user || page.props.user))

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
        router.reload({ only: ['gizmo', 'auth', 'transactions'], preserveScroll: true })
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
</script>

<template>
    <main class="min-h-screen bg-[#050505] text-slate-200 font-mono overflow-x-hidden relative flex flex-col">

        <div class="fixed inset-0 pointer-events-none z-[100] opacity-[0.02] bg-[linear-gradient(rgba(18,16,16,0)_50%,rgba(0,0,0,0.25)_50%),linear-gradient(90deg,rgba(255,0,0,0.06),rgba(0,255,0,0.02),rgba(0,0,0,0.06))] bg-[length:100%_4px,3px_100%]"></div>

        <header class="border-b border-white/5 bg-black/80 backdrop-blur-2xl sticky top-0 z-50 py-4 sm:py-6 flex-shrink-0">
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
                        <button @click="handleLogout" class="nav-btn !text-red-500 !border-red-500/20 hover:!bg-red-500 hover:!text-white">Выйти</button>
                    </template>

                    <template v-else>
                        <button @click="isPhoneModalOpen = true" class="nav-btn !text-[#22c55e] !border-[#22c55e]/30 hover:!bg-[#22c55e] hover:!text-black">Войти</button>
                    </template>
                </nav>
            </div>
        </header>

        <div class="flex-grow w-full py-6 sm:py-10 flex flex-col items-center px-4 sm:px-6">
            <div v-if="isAuthenticated"
                 class="w-full max-w-[1400px] flex flex-wrap justify-between items-center gap-4 mb-8 border-b border-white/10 pb-6 text-left">
                <div>
                    <div class="text-[10px] uppercase text-white/30 tracking-[0.3em] mb-1 italic">Оператор</div>
                    <div class="font-bold text-sm tracking-widest uppercase text-white">
                        {{ $page.props.auth?.user?.name || $page.props.user?.name || '—' }}
                    </div>
                </div>

                <div class="bg-white/5 border border-white/10 py-4 px-5 rounded-2xl flex items-center gap-5 backdrop-blur-md">
                    <div>
                        <span class="block text-[10px] uppercase text-white/30 tracking-[0.2em] mb-0.5 italic font-black">Баланс</span>
                        <span class="text-3xl font-black italic tracking-tighter text-white font-bomber">
                            {{ Math.floor(displayBalance) }}
                            <span class="text-[#22c55e] text-xl font-mono ml-1">₽</span>
                        </span>
                    </div>
                    <button
                        type="button"
                        @click="openTopUp"
                        title="Пополнить баланс"
                        class="w-11 h-11 rounded-xl bg-white/5 border border-white/10 text-[#22c55e] flex items-center justify-center hover:bg-[#22c55e] hover:text-black transition-all group"
                    >
                        <svg class="w-5 h-5 group-hover:scale-125 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                        </svg>
                    </button>
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
    font-family: 'BomberEscort', sans-serif;
}
@media (min-width: 1024px) { .nav-btn { min-width: 170px; } }
.nav-btn.active { @apply bg-[#22c55e] text-black border-transparent shadow-[0_0_20px_rgba(34,197,94,0.4)]; }
</style>
