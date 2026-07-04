<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

// Определяем текущий маршрут для автоматической подсветки активного пункта меню
const page = usePage()
const currentUrl = computed(() => page.url)

const isActive = (url: string) => currentUrl.value.startsWith(url)
</script>

<template>
    <div class="min-h-screen bg-[#050505] flex font-mono text-white selection:bg-cyan-500 selection:text-black">

        <!-- ========================================== -->
        <!-- ЛЕВАЯ ПАНЕЛЬ НАВИГАЦИИ (SIDEBAR) -->
        <!-- ========================================== -->
        <aside class="w-[320px] bg-[#020202] border-r border-white/5 flex flex-col shrink-0 min-h-screen select-none">

            <div class="p-8 border-b border-white/5">
                <div class="flex items-center gap-3">
                    <div class="w-3 h-3 bg-[#22c55e] rounded-full animate-pulse shadow-[0_0_10px_#22c55e]"></div>
                    <span class="text-2xl font-black uppercase italic tracking-tighter text-white">
                        Reactor <span class="text-[#22c55e]">Ctrl</span>
                    </span>
                </div>
                <div class="text-[10px] text-white/20 uppercase font-black tracking-[0.25em] italic mt-2">
                    Terminal Node // v2.6
                </div>
            </div>

            <div class="flex-1 overflow-y-auto py-8 px-6 space-y-8 custom-scrollbar">

                <!-- СЕКЦИЯ: ОПЕРАЦИИ -->
                <div class="space-y-2">
                    <div class="text-[10px] text-white/30 font-black uppercase tracking-[0.3em] italic pl-4 mb-3">Операции</div>
                    <Link href="/admin/dashboard"
                          class="flex items-center gap-4 px-5 py-4 rounded-2xl border transition-all text-xs font-black uppercase tracking-wider"
                          :class="isActive('/admin/dashboard') ? 'bg-[#22c55e]/10 border-[#22c55e]/30 text-[#22c55e] shadow-[0_0_30px_rgba(34,197,94,0.05)]' : 'bg-transparent border-transparent text-white/40 hover:text-white hover:bg-white/[0.02]'">
                        <span>⚡</span> Дашбоард
                    </Link>
                    <Link href="/admin/orders"
                          class="flex items-center gap-4 px-5 py-4 rounded-2xl border transition-all text-xs font-black uppercase tracking-wider"
                          :class="isActive('/admin/orders') ? 'bg-[#22c55e]/10 border-[#22c55e]/30 text-[#22c55e]' : 'bg-transparent border-transparent text-white/40 hover:text-white hover:bg-white/[0.02]'">
                        <span>📦</span> Очередь заказов
                    </Link>
                </div>

                <!-- СЕКЦИЯ: КИБЕРСПОРТ -->
                <div class="space-y-2">
                    <div class="text-[10px] text-white/30 font-black uppercase tracking-[0.3em] italic pl-4 mb-3">Киберспорт</div>
                    <Link href="/admin/tournaments"
                          class="flex items-center gap-4 px-5 py-4 rounded-2xl border transition-all text-xs font-black uppercase tracking-wider"
                          :class="isActive('/admin/tournaments') ? 'bg-cyan-500/10 border-cyan-500/30 text-cyan-500' : 'bg-transparent border-transparent text-white/40 hover:text-white hover:bg-white/[0.02]'">
                        <span>🏆</span> Менеджер ивентов
                    </Link>
                    <Link href="/admin/promocodes"
                          class="flex items-center gap-4 px-5 py-4 rounded-2xl border transition-all text-xs font-black uppercase tracking-wider"
                          :class="isActive('/admin/promocodes') ? 'bg-cyan-500/10 border-cyan-500/30 text-cyan-500' : 'bg-transparent border-transparent text-white/40 hover:text-white hover:bg-white/[0.02]'">
                        <span>🎁</span> Маркетинг
                    </Link>

                    <!-- ИСПРАВЛЕНО: Ссылка теперь перенаправляет на рабочий роут лицензий и игр -->
                    <Link href="/admin/licenses"
                          class="flex items-center gap-4 px-5 py-4 rounded-2xl border transition-all text-xs font-black uppercase tracking-wider"
                          :class="isActive('/admin/licenses') ? 'bg-cyan-500/10 border-cyan-500/30 text-cyan-500 shadow-[0_0_30px_rgba(6,182,212,0.05)]' : 'bg-transparent border-transparent text-white/40 hover:text-white hover:bg-white/[0.02]'">
                        <span>🎮</span> Библиотека игр
                    </Link>
                </div>

                <!-- СЕКЦИЯ: ЭКОНОМИКА -->
                <div class="space-y-2">
                    <div class="text-[10px] text-white/30 font-black uppercase tracking-[0.3em] italic pl-4 mb-3">Экономика</div>
                    <Link href="/admin/tariffs"
                          class="flex items-center gap-4 px-5 py-4 rounded-2xl border transition-all text-xs font-black uppercase tracking-wider"
                          :class="isActive('/admin/tariffs') ? 'bg-cyan-500/10 border-cyan-500/30 text-cyan-500' : 'bg-transparent border-transparent text-white/40 hover:text-white hover:bg-white/[0.02]'">
                        <span>🏷️</span> Тарифы и пакеты
                    </Link>
                </div>

                <!-- СЕКЦИЯ: КОНФИГУРАЦИЯ -->
                <div class="space-y-2">
                    <div class="text-[10px] text-white/30 font-black uppercase tracking-[0.3em] italic pl-4 mb-3">Конфигурация</div>
                    <Link href="/admin/zones"
                          class="flex items-center gap-4 px-5 py-4 rounded-2xl border transition-all text-xs font-black uppercase tracking-wider"
                          :class="isActive('/admin/zones') ? 'bg-cyan-500/10 border-cyan-500/30 text-cyan-500' : 'bg-transparent border-transparent text-white/40 hover:text-white hover:bg-white/[0.02]'">
                        <span>📍</span> Топология залов
                    </Link>
                    <Link href="/admin/map-builder"
                          class="flex items-center gap-4 px-5 py-4 rounded-2xl border transition-all text-xs font-black uppercase tracking-wider"
                          :class="isActive('/admin/map-builder') ? 'bg-cyan-500/10 border-cyan-500/30 text-cyan-500' : 'bg-transparent border-transparent text-white/40 hover:text-white hover:bg-white/[0.02]'">
                        <span>🗺️</span> Редактор карты
                    </Link>
                    <Link href="/admin/overlays"
                          class="flex items-center gap-4 px-5 py-4 rounded-2xl border transition-all text-xs font-black uppercase tracking-wider"
                          :class="isActive('/admin/overlays') ? 'bg-cyan-500/10 border-cyan-500/30 text-cyan-500' : 'bg-transparent border-transparent text-white/40 hover:text-white hover:bg-white/[0.02]'">
                        <span>🖥️</span> Shell Оверлеи
                    </Link>
                    <Link href="/admin/inventory"
                          class="flex items-center gap-4 px-5 py-4 rounded-2xl border transition-all text-xs font-black uppercase tracking-wider"
                          :class="isActive('/admin/inventory') ? 'bg-cyan-500/10 border-cyan-500/30 text-cyan-500' : 'bg-transparent border-transparent text-white/40 hover:text-white hover:bg-white/[0.02]'">
                        <span>📦</span> Склад
                    </Link>
                    <Link href="/admin/licenses"
                          class="flex items-center gap-4 px-5 py-4 rounded-2xl border transition-all text-xs font-black uppercase tracking-wider"
                          :class="isActive('/admin/licenses') ? 'bg-cyan-500/10 border-cyan-500/30 text-cyan-500' : 'bg-transparent border-transparent text-white/40 hover:text-white hover:bg-white/[0.02]'">
                        <span>🔑</span> Менеджер лицензий
                    </Link>
                </div>

                <!-- СЕКЦИЯ: БЕЗОПАСНОСТЬ -->
                <div class="space-y-2">
                    <div class="text-[10px] text-white/30 font-black uppercase tracking-[0.3em] italic pl-4 mb-3">Безопасность</div>
                    <Link href="/admin/incidents"
                          class="flex items-center gap-4 px-5 py-4 rounded-2xl border transition-all text-xs font-black uppercase tracking-wider"
                          :class="isActive('/admin/incidents') ? 'bg-red-500/10 border-red-500/30 text-red-500' : 'bg-transparent border-transparent text-white/40 hover:text-white hover:bg-white/[0.02]'">
                        <span>⚠️</span> Инциденты
                    </Link>
                </div>

            </div>
        </aside>

        <!-- ========================================== -->
        <!-- ОСНОВНОЙ КОНТЕНТ СТРАНИЦЫ -->
        <!-- ========================================== -->
        <div class="flex-1 flex flex-col min-h-screen overflow-hidden">

            <header class="h-24 border-b border-white/5 flex items-center justify-between px-10 select-none bg-[#020202]/50 backdrop-blur-md shrink-0">
                <div class="flex items-center gap-2 text-[10px] uppercase font-black italic tracking-widest text-white/40">
                    Node: <span class="text-cyan-400">{{ currentUrl }}</span>
                </div>

                <div class="flex items-center gap-6">
                    <div class="text-right">
                        <div class="text-xs font-black uppercase italic text-white">Operator_1</div>
                        <div class="text-[9px] text-[#22c55e] font-bold uppercase tracking-wider">Shift: Active</div>
                    </div>
                    <Link href="/admin/logout" method="post" as="button" class="px-4 py-2 border border-white/10 hover:border-red-500/40 text-white/40 hover:text-red-500 rounded-xl text-[10px] font-black uppercase transition-all">
                        Exit Node
                    </Link>
                </div>
            </header>

            <main class="flex-1 overflow-y-auto custom-scrollbar bg-[#050505]">
                <slot />
            </main>

        </div>
    </div>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar { width: 4px; height: 4px; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.03); border-radius: 10px; }
.custom-scrollbar::-webkit-scrollbar-thumb:hover { background: rgba(6, 182, 212, 0.2); }
</style>
