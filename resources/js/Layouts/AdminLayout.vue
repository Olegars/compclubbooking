<script setup lang="ts">
import { Link, usePage, router } from '@inertiajs/vue3'
import { computed } from 'vue'

const page = usePage()

// Получаем данные админа из глобальной шины
const admin = computed(() => page.props.auth?.user || { name: 'Admin', role: 'Operator' })

const handleLogout = () => {
    if (confirm('ВНИМАНИЕ: Выйти из панели управления REACTOR?')) {
        router.post('/logout')
    }
}
</script>

<template>
    <div class="min-h-screen bg-[#020202] text-slate-300 font-mono flex">

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

                <div class="text-[10px] uppercase text-white/20 px-4 mb-3 mt-2 tracking-widest font-black italic">
                    Операции
                </div>

                <Link href="/admin/dashboard" class="admin-nav-link" :class="{ 'active': $page.url.startsWith('/admin/dashboard') }">
                    <span class="text-lg drop-shadow-md">⚡</span>
                    <span>Дашбоард</span>
                </Link>

                <Link href="/admin/orders" class="admin-nav-link" :class="{ 'active': $page.url.startsWith('/admin/orders') }">
                    <span class="text-lg drop-shadow-md">🍔</span>
                    <span>Очередь заказов</span>
                </Link>

                <Link href="/admin/inventory" class="admin-nav-link" :class="{ 'active': $page.url.startsWith('/admin/inventory') }">
                    <span class="text-lg drop-shadow-md">📦</span>
                    <span>Склад Маркета</span>
                </Link>

                <div class="text-[10px] uppercase text-white/20 px-4 mt-8 mb-3 tracking-widest font-black italic">
                    Конфигурация
                </div>

                <Link href="/admin/map-builder" class="admin-nav-link" :class="{ 'active': $page.url.startsWith('/admin/map-builder') }">
                    <span class="text-lg drop-shadow-md">🗺️</span>
                    <span>Редактор карты</span>
                </Link>

                <Link href="/admin/bonus-logs" class="admin-nav-link" :class="{ 'active': $page.url.startsWith('/admin/bonus-logs') }">
                    <span class="text-lg drop-shadow-md">🛡️</span>
                    <span>Реестр бонусов</span>
                </Link>

            </nav>

            <div class="p-6 border-t border-white/10 bg-black/40">
                <div class="flex items-center gap-4 mb-6 px-2">
                    <div class="w-10 h-10 rounded-xl bg-[#22c55e]/10 border border-[#22c55e]/30 text-[#22c55e] flex items-center justify-center font-black text-sm uppercase shadow-[0_0_15px_rgba(34,197,94,0.1)]">
                        {{ admin.name.charAt(0) }}
                    </div>
                    <div class="overflow-hidden">
                        <div class="text-xs font-black text-white truncate uppercase italic tracking-tight">
                            {{ admin.name }}
                        </div>
                        <div class="text-[9px] text-[#22c55e] uppercase font-bold tracking-tighter opacity-60">
                            {{ admin.role || 'Operator' }}
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

/* Стили для ссылок навигации */
.admin-nav-link {
    @apply flex items-center gap-3 px-4 py-3.5 rounded-xl text-white/40 hover:text-white hover:bg-white/5 transition-all font-black uppercase tracking-widest text-[10px];
}

/* Активное состояние ссылки */
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
</style>
