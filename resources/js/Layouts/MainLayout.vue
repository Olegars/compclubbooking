<script setup lang="ts">
import { ref, onMounted, nextTick } from 'vue'
import { Link, usePage, router } from '@inertiajs/vue3'
import axios from 'axios' // <--- Прямой импорт (решает проблему с window.axios)

import ConfirmModal from '@/Components/ConfirmModal.vue'
import SmsModal from '@/Components/SmsModal.vue'

const page = usePage()

const isRolling = ref(false)
const isPhoneModalOpen = ref(false)
const isSmsModalOpen = ref(false)
const authPhone = ref('')
const smsModalRef = ref<InstanceType<typeof SmsModal> | null>(null)

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
    isRolling.value = false
    await nextTick()
    setTimeout(() => { isRolling.value = true }, 30)
}

onMounted(() => {
    setTimeout(() => { triggerRoll() }, 500)
    setInterval(() => { triggerRoll() }, 15000)
})
</script>

<template>
    <main class="min-h-screen bg-[#050505] text-slate-200 font-mono overflow-x-hidden relative flex flex-col">

        <div class="fixed inset-0 pointer-events-none z-[100] opacity-[0.02] bg-[linear-gradient(rgba(18,16,16,0)_50%,rgba(0,0,0,0.25)_50%),linear-gradient(90deg,rgba(255,0,0,0.06),rgba(0,255,0,0.02),rgba(0,0,0,0.06))] bg-[length:100%_4px,3px_100%]"></div>

        <header class="border-b border-white/5 bg-black/80 backdrop-blur-2xl sticky top-0 z-50 py-10 flex-shrink-0">
            <div class="max-w-[1600px] mx-auto px-6 flex flex-col items-center gap-6 relative text-center">

                <div class="flex flex-col items-center gap-4 cursor-pointer group select-none" @click="triggerRoll">
                    <h1 class="flex items-center justify-center scale-90 lg:scale-100 text-center">
                        <span class="sector-neon text-[80px] lg:text-[110px] uppercase leading-none tracking-tighter italic font-bomber">Sector</span>
                        <div class="slot-container ml-6 lg:ml-10 mt-2 lg:mt-4 flex items-center justify-center relative border border-white/5 rounded-xl overflow-hidden bg-black/50">
                            <div class="slot-inner flex w-full h-full">
                                <div v-for="(digit, index) in [0, 4, 5, 1]" :key="index" class="digit-box border-r border-white/5 last:border-0">
                                    <div class="digit-strip" :class="[`strip-${digit}`, { 'roll-active': isRolling }]" :style="{ animationDelay: isRolling ? `${index * 150}ms` : '0ms' }">
                                        <span v-for="n in 20" :key="n" class="d-cell font-mono font-black italic">{{ (n - 1) % 10 }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </h1>
                </div>

                <nav class="flex items-center gap-6 mt-6">
                    <Link href="/booking" class="nav-btn" :class="{ 'active': $page.url.startsWith('/booking') }">БРОНИРОВАНИЕ</Link>
                    <Link href="/" class="nav-btn" :class="{ 'active': $page.url === '/' }">ГЛАВНАЯ</Link>

                    <template v-if="$page.props.auth?.user || $page.props.user">
                        <Link href="/account/dashboard" class="nav-btn" :class="{ 'active': $page.url.startsWith('/account') }">КАБИНЕТ</Link>
                        <button @click="handleLogout" class="nav-btn !text-red-500 !border-red-500/20 hover:!bg-red-500 hover:!text-white">ВЫЙТИ</button>
                    </template>

                    <template v-else>
                        <button @click="isPhoneModalOpen = true" class="nav-btn !text-[#22c55e] !border-[#22c55e]/20 hover:!bg-[#22c55e] hover:!text-black shadow-[0_0_15px_rgba(34,197,94,0.1)]">АВТОРИЗАЦИЯ</button>
                    </template>
                </nav>
            </div>
        </header>

        <div class="flex-grow w-full py-10 flex flex-col items-center px-6">
            <div class="w-full max-w-[1400px] flex flex-col lg:flex-row justify-between items-start lg:items-end gap-8 mb-12 border-b border-white/10 pb-8 text-left">

                <div class="flex gap-12">
                    <div>
                        <div class="text-[10px] uppercase text-[#22c55e] tracking-[0.3em] mb-2 flex items-center gap-2 font-black italic">
                            <span class="w-1.5 h-1.5 rounded-full bg-[#22c55e] animate-pulse"></span> SYSTEM STATUS
                        </div>
                        <div class="text-[10px] text-white/50 space-y-1 uppercase font-mono">
                            <p>CONNECTION: <span class="text-white">SECURE_CHANNEL</span></p>
                            <p>NODES: <span class="text-white">124 / 150</span></p>
                        </div>
                    </div>

                    <div class="hidden md:block border-l border-white/10 pl-12">
                        <div class="text-[10px] uppercase text-white/30 tracking-[0.3em] mb-2 italic">Current_Operator</div>
                        <div class="font-bold text-sm tracking-widest uppercase text-white">
                            {{ ($page.props.auth?.user || $page.props.user) ? ($page.props.auth?.user?.name || $page.props.user?.name) : 'GUEST_0451' }}
                        </div>
                    </div>
                </div>

                <div class="bg-white/5 border border-white/10 p-6 rounded-3xl flex items-center gap-8 backdrop-blur-md min-w-[320px]">
                    <div>
                        <span class="block text-[10px] uppercase text-white/30 tracking-[0.2em] mb-1 italic font-black">Account Balance</span>
                        <span class="text-4xl font-black italic tracking-tighter text-white font-bomber">
                            {{ $page.props.gizmo?.balance || '0' }}
                            <span class="text-[#22c55e] text-2xl font-mono ml-1">₽</span>
                        </span>
                    </div>
                    <div class="ml-auto w-12 h-12 rounded-2xl bg-white/5 border border-white/10 text-[#22c55e] flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    </div>
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
        </Teleport>
    </main>
</template>

<style scoped>
@reference "../../css/app.css";

.font-bomber { font-family: 'BomberEscort', sans-serif; }
.sector-neon { color: #000; -webkit-text-stroke: 1.2px #22c55e; text-shadow: 0 0 5px rgba(34, 197, 94, 0.8), 0 0 20px rgba(34, 197, 94, 0.4); filter: brightness(1.2); font-family: 'BomberEscort', sans-serif; }

.slot-container { width: 200px; height: 70px; }
@media (min-width: 1024px) { .slot-container { width: 264px; height: 92px; } }

.digit-box { width: 25%; height: 100%; position: relative; overflow: hidden; }
.digit-strip { display: flex; flex-direction: column; will-change: transform; width: 100%; }
.d-cell { height: 92px; display: flex; align-items: center; justify-content: center; color: #000; -webkit-text-stroke: 2.5px #22c55e; paint-order: stroke fill; font-size: 4.5rem; line-height: 92px; }

.strip-0 { transform: translateY(0); } .strip-4 { transform: translateY(-368px); } .strip-5 { transform: translateY(-460px); } .strip-1 { transform: translateY(-92px); }

.roll-active { animation-duration: 2.5s; animation-timing-function: cubic-bezier(0.45, 0.05, 0.55, 0.95); animation-fill-mode: both; }
.strip-0.roll-active { animation-name: roll-0; } .strip-4.roll-active { animation-name: roll-4; } .strip-5.roll-active { animation-name: roll-5; } .strip-1.roll-active { animation-name: roll-1; }

@keyframes roll-0 { from { transform: translateY(0); } 100% { transform: translateY(-920px); } }
@keyframes roll-4 { from { transform: translateY(-368px); } 100% { transform: translateY(-1288px); } }
@keyframes roll-5 { from { transform: translateY(-460px); } 100% { transform: translateY(-1380px); } }
@keyframes roll-1 { from { transform: translateY(-92px); } 100% { transform: translateY(-1012px); } }

.nav-btn {
    @apply px-8 py-4 border border-white/10 rounded-xl text-xs font-black transition-all cursor-pointer uppercase tracking-widest italic;
    font-family: 'BomberEscort', sans-serif;
    min-width: 220px;
}
.nav-btn.active { @apply bg-[#22c55e] text-black border-transparent shadow-[0_0_20px_rgba(34,197,94,0.4)]; }
</style>
