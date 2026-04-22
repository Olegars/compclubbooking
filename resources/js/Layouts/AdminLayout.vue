<script setup lang="ts">
import { Link, usePage, router } from '@inertiajs/vue3'
import { computed } from 'vue'

const page = usePage()

// Получаем данные админа из пропсов Auth
const admin = computed(() => page.props.auth?.user || { name: 'Admin', role: 'Operator' })

const handleLogout = () => {
    if (confirm('Выйти из панели управления REACTOR?')) {
        router.post(route('logout'))
    }
}
</script>

<template>
    <div class="min-h-screen bg-[#020202] text-slate-300 font-mono flex">

        <aside class="w-72 border-r border-white/10 bg-[#050505] flex flex-col shrink-0 shadow-2xl z-50">
            <div class="p-8 border-b border-white/10 bg-black/50">
                <div class="text-[#22c55e] font-black italic text-2xl tracking-tighter flex items-center gap-2">
                    <div class="w-3 h-3 bg-[#22c55e] rounded-full animate-pulse"></div>
                    REACTOR <span class="text-white opacity-40 font-light">CTRL</span>
                </div>
                <div class="text-[9px] text-white/20 uppercase mt-2 tracking-[0.4em] font-bold">
                    Terminal Node // v2.6
                </div>
            </div>

            <nav class="flex-1 p-6 space-y-3 overflow-y-auto custom-scrollbar">
                <div class="text-[10px] uppercase text-white/20 px-4 mb-4 tracking-widest font-black italic">
                    Конфигурация
                </div>

                <Link :href="route('admin.map-builder')" class="admin-nav-link" :class="{ 'active': $page.url.includes('map-builder') }">
                    <span class="text-lg">🗺️</span>
                    <span>Редактор карты</span>
                </Link>

                <div class="text-[10px] uppercase text-white/20 px-4 mt-8 mb-4 tracking-widest font-black italic">
                    Управление
                </div>

                <button class="admin-nav-link w-full text-left opacity-30 cursor-not-allowed grayscale">
                    <span class="text-lg">💻</span> Компьютеры
                </button>
                <button class="admin-nav-link w-full text-left opacity-30 cursor-not-allowed grayscale">
                    <span class="text-lg">📊</span> Статистика
                </button>
                <button class="admin-nav-link w-full text-left opacity-30 cursor-not-allowed grayscale">
                    <span class="text-lg">👥</span> Клиенты
                </button>
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
                            {{ admin.role || 'Superuser' }}
                        </div>
                    </div>
                </div>

                <button
                    @click="handleLogout"
                    class="w-full py-3 border border-red-500/20 text-red-500 text-[10px] font-black uppercase rounded-xl hover:bg-red-500/10 hover:border-red-500/40 transition-all tracking-widest"
                >
                    Завершить сессию
                </button>
            </div>
        </aside>

        <main class="flex-1 flex flex-col overflow-hidden relative">
            <div class="absolute inset-0 pointer-events-none z-[100] opacity-[0.015] bg-[linear-gradient(rgba(18,16,16,0)_50%,rgba(0,0,0,0.25)_50%),linear-gradient(90deg,rgba(255,0,0,0.06),rgba(0,255,0,0.02),rgba(0,0,0,0.06))] bg-[length:100%_4px,3px_100%]"></div>

            <header class="h-16 border-b border-white/5 bg-black/40 backdrop-blur-md flex items-center px-10 justify-between shrink-0">
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

            <div class="flex-1 overflow-auto relative custom-scrollbar">
                <slot />
            </div>
        </main>
    </div>
</template>

<style scoped>
/* Подключаем референс Tailwind v4 */
@reference "../../css/app.css";

.custom-scrollbar::-webkit-scrollbar {
    width: 4px;
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
