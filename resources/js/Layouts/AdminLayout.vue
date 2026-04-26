<script setup lang="ts">
import { Link, usePage, router } from '@inertiajs/vue3'
import { ref, computed, onMounted, onUnmounted } from 'vue'
import axios from 'axios'

const page = usePage()

// --- ДАННЫЕ АДМИНА ---
const admin = computed(() => page.props.admin_user || { name: 'Admin', role: 'admin' })

// --- ЛОГИКА СИГНАЛИЗАЦИИ ---
const pendingCount = ref(0)
const checkTimer = ref<any>(null)
const audioRef = ref<HTMLAudioElement | null>(null)

const checkOrders = async () => {
    try {
        const { data } = await axios.get('/admin/api/check-orders')
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
        audioRef.value.play().catch(() => {})
    }
}

const handleLogout = () => {
    if (confirm('ВНИМАНИЕ: Завершить текущую сессию REACTOR CTRL?')) {
        router.post('/admin/logout')
    }
}

onMounted(() => {
    checkOrders()
    checkTimer.value = setInterval(checkOrders, 10000)
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
                <div class="text-[9px] text-white/20 uppercase mt-2 tracking-[0.4em] font-bold">Terminal Node // v2.6</div>
            </div>

            <nav class="flex-1 p-6 space-y-1 overflow-y-auto custom-scrollbar">
                <div class="nav-section-title">Операции</div>
                <Link href="/admin/dashboard" class="admin-nav-link" :class="{ 'active': $page.url === '/admin/dashboard' }">
                    <span class="nav-icon">⚡</span> <span>Дашбоард</span>
                </Link>
                <Link href="/admin/orders" class="admin-nav-link relative" :class="{ 'active': $page.url.startsWith('/admin/orders') }">
                    <span class="nav-icon">🍔</span> <span>Очередь заказов</span>
                    <span v-if="pendingCount > 0" class="absolute right-4 w-2 h-2 bg-red-500 rounded-full animate-ping"></span>
                </Link>

                <template v-if="['supervisor', 'owner'].includes(admin.role)">
                    <div class="nav-section-title mt-8 text-purple-500/50">Киберспорт</div>
                    <Link href="/admin/tournaments" class="admin-nav-link border-l-2 border-transparent"
                          :class="{ 'active !border-purple-500/30 !bg-purple-500/10 !text-purple-400 !shadow-[0_0_20px_rgba(168,85,247,0.15)]': $page.url.startsWith('/admin/tournaments') }">
                        <span class="nav-icon">🏆</span> <span>Менеджер Ивентов</span>
                    </Link>
                    <Link href="/admin/promocodes" class="admin-nav-link border-l-2 border-transparent"
                          :class="{ 'active !border-purple-500/30 !bg-purple-500/10 !text-purple-400 !shadow-[0_0_20px_rgba(168,85,247,0.15)]': $page.url.startsWith('/admin/promocodes') }">
                        <span class="nav-icon">🎁</span> <span>Маркетинг</span>
                    </Link>
                </template>

                <div class="nav-section-title mt-8">Экономика</div>
                <Link href="/admin/tariffs" class="admin-nav-link border-l-2 border-transparent"
                      :class="{ 'active !border-cyan-500/30 !bg-cyan-500/10 !text-cyan-400': $page.url.startsWith('/admin/tariffs') }">
                    <span class="nav-icon">🏷️</span> <span>Тарифы и пакеты</span>
                </Link>

                <template v-if="admin.role === 'owner'">
                    <Link href="/admin/taxes" class="admin-nav-link border-l-2 border-transparent"
                          :class="{ 'active !border-cyan-500/30 !bg-cyan-500/10 !text-cyan-400': $page.url.startsWith('/admin/taxes') }">
                        <span class="nav-icon">📊</span> <span>Налоговый движок</span>
                    </Link>
                </template>

                <div class="nav-section-title mt-8">Конфигурация</div>
                <Link href="/admin/zones" class="admin-nav-link border-l-2 border-transparent"
                      :class="{ 'active !border-cyan-500/30 !bg-cyan-500/10 !text-cyan-400': $page.url.startsWith('/admin/zones') }">
                    <span class="nav-icon">📍</span> <span>Топология залов</span>
                </Link>
                <Link href="/admin/map-builder" class="admin-nav-link" :class="{ 'active': $page.url.startsWith('/admin/map-builder') }">
                    <span class="nav-icon">🗺️</span> <span>Редактор карты</span>
                </Link>
                <Link href="/admin/overlays" class="admin-nav-link border-l-2 border-transparent"
                      :class="{ 'active !border-cyan-500/30 !bg-cyan-500/10 !text-cyan-400': $page.url.startsWith('/admin/overlays') }">
                    <span class="nav-icon">🖥️</span> <span>Shell Оверлеи</span>
                </Link>
                <Link href="/admin/inventory" class="admin-nav-link" :class="{ 'active': $page.url.startsWith('/admin/inventory') }">
                    <span class="nav-icon">📦</span> <span>Склад</span>
                </Link>
                <Link href="/admin/licenses" class="admin-nav-link" :class="{ 'active': $page.url.startsWith('/admin/licenses') }">
                    <span class="nav-icon">🔑</span> <span>Менеджер Лицензий</span>
                </Link>

                <div class="nav-section-title mt-8 text-red-500/50">Безопасность</div>
                <Link href="/admin/incidents" class="admin-nav-link border-l-2 border-transparent"
                      :class="{ 'active !border-red-500/30 !bg-red-500/10 !text-red-500 !shadow-[0_0_20px_rgba(239,68,68,0.15)]': $page.url.startsWith('/admin/incidents') }">
                    <span class="nav-icon">🚨</span> <span>Инциденты</span>
                </Link>
            </nav>

            <div class="p-6 border-t border-white/10 bg-black/40">
                <div class="flex items-center gap-4 mb-6 px-2">
                    <div class="w-10 h-10 rounded-xl bg-[#22c55e]/10 border border-[#22c55e]/30 text-[#22c55e] flex items-center justify-center font-black text-sm uppercase shadow-[0_0_15px_rgba(34,197,94,0.1)]"
                         :class="{ '!bg-purple-500/10 !border-purple-500/30 !text-purple-500 !shadow-[0_0_15px_rgba(168,85,247,0.2)]': admin.role === 'owner' }">
                        {{ admin.name.charAt(0) }}
                    </div>
                    <div class="overflow-hidden text-left">
                        <div class="text-xs font-black text-white truncate uppercase italic tracking-tight">{{ admin.name }}</div>
                        <div class="text-[9px] uppercase font-bold tracking-tighter opacity-60" :class="admin.role === 'owner' ? 'text-purple-500' : 'text-[#22c55e]'">
                            Lvl: {{ admin.role }}
                        </div>
                    </div>
                </div>
                <button @click="handleLogout" class="w-full py-3 border border-red-500/20 text-red-500 text-[10px] font-black uppercase rounded-xl hover:bg-red-500/10 transition-all tracking-widest active:scale-95">
                    LOGOUT
                </button>
            </div>
        </aside>

        <main class="flex-1 flex flex-col overflow-hidden relative">
            <header class="h-16 border-b border-white/5 bg-black/40 backdrop-blur-md flex items-center px-10 justify-between shrink-0 relative z-20">
                <div class="flex items-center gap-4">
                    <div class="text-[10px] text-white/30 uppercase tracking-[0.2em] font-bold">
                        Node: <span class="text-[#22c55e] ml-1">{{ $page.url }}</span>
                    </div>
                </div>
                <div class="flex items-center gap-6">
                    <Link href="/admin/orders" v-if="pendingCount > 0" class="flex items-center gap-3 bg-red-500 text-black px-5 py-1.5 rounded-full animate-pulse shadow-[0_0_20px_rgba(239,68,68,0.4)]">
                        <span class="text-[10px] font-black uppercase italic tracking-tighter">Orders: {{ pendingCount }}</span>
                    </Link>
                    <div class="flex items-center gap-2 px-3 py-1 bg-[#22c55e]/5 border border-[#22c55e]/20 rounded-full">
                        <span class="w-1.5 h-1.5 rounded-full bg-[#22c55e] animate-pulse"></span>
                        <span class="text-[9px] uppercase font-black text-[#22c55e] tracking-tighter">Core Online</span>
                    </div>
                </div>
            </header>
            <div class="flex-1 overflow-auto relative custom-scrollbar z-10"><slot /></div>
        </main>
    </div>
</template>

<style scoped>
@reference "../../css/app.css";
.nav-section-title { @apply text-[9px] uppercase text-white/20 px-4 mb-2 tracking-[0.3em] font-black italic; }
.admin-nav-link { @apply flex items-center gap-3 px-4 py-3 rounded-xl text-white/40 hover:text-white hover:bg-white/5 transition-all font-black uppercase tracking-widest text-[10px]; }
.admin-nav-link.active { @apply bg-[#22c55e]/10 text-white border border-[#22c55e]/30 shadow-[0_0_20px_rgba(34,197,94,0.1)]; }
.custom-scrollbar::-webkit-scrollbar { width: 6px; }
.custom-scrollbar::-webkit-scrollbar-track { background: rgba(0,0,0,0.2); }
.custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.05); border-radius: 10px; }
</style>
