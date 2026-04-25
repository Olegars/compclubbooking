<script setup lang="ts">
import { Link, usePage, router } from '@inertiajs/vue3'
import { ref, computed, onMounted, onUnmounted } from 'vue'
import axios from 'axios'

const page = usePage()

// --- ДАННЫЕ АДМИНА ---
// Теперь мы четко забираем данные из admin_user
const admin = computed(() => page.props.admin_user || { name: 'Admin', role: 'admin' })

// --- ЛОГИКА СИГНАЛИЗАЦИИ ---
const pendingCount = ref(0)
const checkTimer = ref<any>(null)
const audioRef = ref<HTMLAudioElement | null>(null)

const checkOrders = async () => {
    try {
        const { data } = await axios.get('/admin/api/check-orders')
        // Если новых заказов стало больше — играем звук
        if (data.count > pendingCount.value) {
            playAlarm()
        }
        pendingCount.value = data.count
    } catch (e) {
        console.error('Ошибка мониторинга заказов')
    }
}

const playAlarm = () => {
    if (audioRef.value) {
        audioRef.value.currentTime = 0
        audioRef.value.play().catch(() => {
            console.log('Браузер заблокировал звук до первого клика админа')
        })
    }
}

const handleLogout = () => {
    if (confirm('ВНИМАНИЕ: Выйти из панели управления REACTOR?')) {
        router.post('/admin/logout') // <-- Вот здесь нужно было добавить /admin/
    }
}

onMounted(() => {
    checkOrders()
    checkTimer.value = setInterval(checkOrders, 10000) // Проверка каждые 10 сек
})

onUnmounted(() => {
    if (checkTimer.value) clearInterval(checkTimer.value)
})
</script>

<template>
    <div class="min-h-screen bg-[#020202] text-slate-300 font-mono flex">

        <audio ref="audioRef" src="/sounds/order-alarm.mp3" preload="auto"></audio>

        <aside class="w-72 border-r border-white/10 bg-[#050505] flex flex-col shrink-0 shadow-2xl z-50 relative">

            <div class="p-8 border-b border-white/10 bg-black/50">
                <div class="text-[#22c55e] font-black italic text-2xl tracking-tighter flex items-center gap-2">
                    <div class="w-3 h-3 bg-[#22c55e] rounded-full animate-pulse shadow-[0_0_10px_#22c55e]"></div>
                    REACTOR <span class="text-white opacity-40 font-light">CTRL</span>
                </div>
                <div class="text-[9px] text-white/20 uppercase mt-2 tracking-[0.4em] font-bold">
                    Terminal Node // v2.6
                </div>
            </div>

            <nav class="flex-1 p-6 space-y-2 overflow-y-auto custom-scrollbar">

                <div class="nav-section-title">Операции</div>

                <Link href="/admin/dashboard" class="admin-nav-link" :class="{ 'active': $page.url.startsWith('/admin/dashboard') }">
                    <span class="nav-icon">⚡</span>
                    <span>Дашбоард</span>
                </Link>

                <Link href="/admin/orders" class="admin-nav-link relative" :class="{ 'active': $page.url.startsWith('/admin/orders') }">
                    <span class="nav-icon">🍔</span>
                    <span>Очередь заказов</span>
                    <span v-if="pendingCount > 0" class="absolute right-4 w-2 h-2 bg-red-500 rounded-full animate-ping"></span>
                </Link>

                <template v-if="admin.role === 'supervisor' || admin.role === 'owner'">
                    <Link href="/admin/inventory" class="admin-nav-link" :class="{ 'active': $page.url.startsWith('/admin/inventory') }">
                        <span class="nav-icon">📦</span>
                        <span>Склад Маркета</span>
                    </Link>
                </template>

                <div class="nav-section-title mt-8">Протоколы</div>

                <Link href="/admin/shifts/transfer" class="admin-nav-link" :class="{ 'active': $page.url.startsWith('/admin/shifts/transfer') }">
                    <span class="nav-icon">🔄</span>
                    <span>Пересменка</span>
                </Link>

                <template v-if="admin.role === 'supervisor' || admin.role === 'owner'">
                    <Link href="/admin/shifts/history" class="admin-nav-link" :class="{ 'active': $page.url.startsWith('/admin/shifts/history') }">
                        <span class="nav-icon">📜</span>
                        <span>Архив смен</span>
                    </Link>
                </template>

                <div class="nav-section-title mt-8">Конфигурация</div>

                <Link href="/admin/map-builder" class="admin-nav-link" :class="{ 'active': $page.url.startsWith('/admin/map-builder') }">
                    <span class="nav-icon">🗺️</span>
                    <span>Редактор карты</span>
                </Link>

                <template v-if="admin.role === 'supervisor' || admin.role === 'owner'">
                    <Link href="/admin/bonus-logs" class="admin-nav-link" :class="{ 'active': $page.url.startsWith('/admin/bonus-logs') || $page.url.startsWith('/admin/bonuses') }">
                        <span class="nav-icon">🛡️</span>
                        <span>Реестр бонусов</span>
                    </Link>
                </template>

                <template v-if="admin.role === 'supervisor' || admin.role === 'owner'">
                    <div class="nav-section-title mt-8 text-red-500/50">Надзор</div>

                    <Link href="/admin/incidents" class="admin-nav-link border-l-2 border-transparent"
                          :class="{ 'active !border-red-500/30 !bg-red-500/10 !text-red-500': $page.url.startsWith('/admin/incidents') }">
                        <span class="nav-icon">🚨</span>
                        <span>Реестр инцидентов</span>
                    </Link>
                </template>

                <template v-if="admin.role === 'owner'">
                    <div class="nav-section-title mt-8 text-purple-500/50">Дирекция</div>

                    <Link href="/admin/taxes" class="admin-nav-link border-l-2 border-transparent"
                          :class="{ 'active !border-purple-500/30 !bg-purple-500/10 !text-purple-500': $page.url.startsWith('/admin/taxes') }">
                        <span class="nav-icon">📊</span>
                        <span>Налоговый движок</span>
                    </Link>

                    <Link href="/admin/staff" class="admin-nav-link border-l-2 border-transparent"
                          :class="{ 'active !border-purple-500/30 !bg-purple-500/10 !text-purple-500': $page.url.startsWith('/admin/staff') }">
                        <span class="nav-icon">👥</span>
                        <span>Управление штатом</span>
                    </Link>
                </template>

            </nav>

            <div class="p-6 border-t border-white/10 bg-black/40">
                <div class="flex items-center gap-4 mb-6 px-2">
                    <div class="w-10 h-10 rounded-xl bg-[#22c55e]/10 border border-[#22c55e]/30 text-[#22c55e] flex items-center justify-center font-black text-sm uppercase shadow-[0_0_15px_rgba(34,197,94,0.1)]"
                         :class="{ '!bg-purple-500/10 !border-purple-500/30 !text-purple-500 !shadow-[0_0_15px_rgba(168,85,247,0.2)]': admin.role === 'owner' }">
                        {{ admin.name.charAt(0) }}
                    </div>
                    <div class="overflow-hidden">
                        <div class="text-xs font-black text-white truncate uppercase italic tracking-tight">
                            {{ admin.name }}
                        </div>
                        <div class="text-[9px] uppercase font-bold tracking-tighter opacity-60"
                             :class="admin.role === 'owner' ? 'text-purple-500' : 'text-[#22c55e]'">
                            Level: {{ admin.role }}
                        </div>
                    </div>
                </div>

                <button @click="handleLogout"
                        class="w-full py-3 border border-red-500/20 text-red-500 text-[10px] font-black uppercase rounded-xl hover:bg-red-500/10 hover:border-red-500/40 transition-all tracking-widest active:scale-95">
                    Завершить сессию
                </button>
            </div>
        </aside>

        <main class="flex-1 flex flex-col overflow-hidden relative">

            <div class="absolute inset-0 pointer-events-none z-[100] opacity-[0.015] bg-[linear-gradient(rgba(18,16,16,0)_50%,rgba(0,0,0,0.25)_50%),linear-gradient(90deg,rgba(34,197,94,0.06),rgba(0,0,0,0.02),rgba(0,0,0,0.06))] bg-[length:100%_4px,3px_100%]"></div>

            <header class="h-16 border-b border-white/5 bg-black/40 backdrop-blur-md flex items-center px-10 justify-between shrink-0 relative z-20">
                <div class="flex items-center gap-4">
                    <div class="text-[10px] text-white/30 uppercase tracking-[0.2em] font-bold">
                        Location: <span class="text-[#22c55e] ml-1">{{ $page.url }}</span>
                    </div>
                </div>

                <div class="flex items-center gap-6">
                    <Link href="/admin/orders" v-if="pendingCount > 0"
                          class="flex items-center gap-3 bg-red-500 text-black px-5 py-1.5 rounded-full animate-pulse shadow-[0_0_20px_rgba(239,68,68,0.4)] hover:scale-105 transition-transform cursor-pointer">
                        <span class="text-sm">⚠️</span>
                        <span class="text-[10px] font-black uppercase italic tracking-tighter">Новые заказы: {{ pendingCount }}</span>
                    </Link>

                    <div class="flex items-center gap-2 px-3 py-1 bg-[#22c55e]/5 border border-[#22c55e]/20 rounded-full">
                        <span class="w-1.5 h-1.5 rounded-full bg-[#22c55e] animate-pulse"></span>
                        <span class="text-[9px] uppercase font-black text-[#22c55e] tracking-tighter">Core Online</span>
                    </div>
                </div>
            </header>

            <div class="flex-1 overflow-auto relative custom-scrollbar z-10">
                <slot />
            </div>
        </main>
    </div>
</template>

<style scoped>
@reference "../../css/app.css";

/* Стили для заголовков секций меню */
.nav-section-title {
    @apply text-[10px] uppercase text-white/20 px-4 mb-3 tracking-widest font-black italic;
}

/* Стили для иконок в меню */
.nav-icon {
    @apply text-lg drop-shadow-md;
}

/* Базовый стиль ссылки */
.admin-nav-link {
    @apply flex items-center gap-3 px-4 py-3.5 rounded-xl text-white/40 hover:text-white hover:bg-white/5 transition-all font-black uppercase tracking-widest text-[10px];
}

/* Активное состояние (Зеленое по умолчанию) */
.admin-nav-link.active {
    @apply bg-[#22c55e]/10 text-white border border-[#22c55e]/30 shadow-[0_0_20px_rgba(34,197,94,0.1)];
}

/* Кастомный скроллбар */
.custom-scrollbar::-webkit-scrollbar {
    width: 6px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: rgba(0,0,0,0.2);
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background: rgba(255,255,255,0.05);
    border-radius: 10px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background: rgba(34, 197, 94, 0.2);
}

/* Анимация пульсации для сигналки */
@keyframes pulse-red {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.8; transform: scale(1.02); }
}
.animate-pulse-custom {
    animation: pulse-red 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}
</style>
