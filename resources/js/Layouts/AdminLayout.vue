<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3'
import { computed, watch } from 'vue'
import Toast from '@/Components/Toast.vue'
import { useAdminAlerts } from '@/Composables/useAdminAlerts'
import { useAdminBarcodeScanner } from '@/Composables/useAdminBarcodeScanner'

// HID-сканер на любой странице админки: по умолчанию списание КМ в заказ
useAdminBarcodeScanner().attachGlobalListener()

// Определяем текущий маршрут для автоматической подсветки активного пункта меню
const page = usePage()
const currentUrl = computed(() => page.url)

// Только путь, без query-строки и хвостового слэша
const currentPath = computed(() => {
    const path = currentUrl.value.split('?')[0].split('#')[0]
    return path.length > 1 ? path.replace(/\/+$/, '') : path
})

// Точное совпадение либо вложенный маршрут — иначе /admin/orders светился бы на /admin/orders-archive
const isActive = (url: string) => currentPath.value === url || currentPath.value.startsWith(url + '/')

const { counts, setCounts } = useAdminAlerts()

watch(() => page.props.admin_alerts, (next) => setCounts(next), { immediate: true, deep: true })

// Данные оператора для шапки (шарятся из HandleInertiaRequests)
const admin = computed(() => page.props.admin_user as any)
const adminName = computed(() => admin.value?.name || admin.value?.email || 'Оператор')
const adminRole = computed(() => admin.value?.role || null)
const isOwner = computed(() => adminRole.value === 'owner')
const isSupervisorPlus = computed(() => adminRole.value === 'supervisor' || adminRole.value === 'owner')

// admin_shift приходит только при открытой смене (status != closed)
const shift = computed(() => page.props.admin_shift as any)
// Зелёным горит только своя смена — чужую подсвечивать как «активна у тебя» нельзя
const shiftIsActive = computed(() => Boolean(shift.value?.is_mine))

const shiftLabel = computed(() => {
    const parts: string[] = []

    if (!shift.value) {
        parts.push('Смена закрыта')
    } else if (shift.value.is_mine) {
        parts.push('Смена активна')
    } else {
        parts.push(`Смена: ${shift.value.admin_name || '—'}`)
    }

    if (adminRole.value) parts.push(adminRole.value)

    return parts.join(' · ')
})
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

                <!-- СЕКЦИЯ: ОПЕРАЦИИ (все роли) -->
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
                        <span v-if="counts.pending_orders > 0"
                              class="ml-auto min-w-[22px] px-2 py-0.5 rounded-full bg-amber-500 text-black text-[10px] font-black tabular-nums text-center shadow-[0_0_12px_rgba(245,158,11,0.4)]">
                            {{ counts.pending_orders }}
                        </span>
                    </Link>
                    <Link href="/admin/inventory"
                          class="flex items-center gap-4 px-5 py-4 rounded-2xl border transition-all text-xs font-black uppercase tracking-wider"
                          :class="isActive('/admin/inventory') ? 'bg-[#22c55e]/10 border-[#22c55e]/30 text-[#22c55e]' : 'bg-transparent border-transparent text-white/40 hover:text-white hover:bg-white/[0.02]'">
                        <span>📦</span> Склад
                    </Link>
                    <Link href="/admin/shifts/transfer"
                          class="flex items-center gap-4 px-5 py-4 rounded-2xl border transition-all text-xs font-black uppercase tracking-wider"
                          :class="isActive('/admin/shifts/transfer') ? 'bg-[#22c55e]/10 border-[#22c55e]/30 text-[#22c55e]' : 'bg-transparent border-transparent text-white/40 hover:text-white hover:bg-white/[0.02]'">
                        <span>🔄</span> Пересменка
                    </Link>
                    <Link href="/admin/shifts/history"
                          class="flex items-center gap-4 px-5 py-4 rounded-2xl border transition-all text-xs font-black uppercase tracking-wider"
                          :class="isActive('/admin/shifts/history') ? 'bg-[#22c55e]/10 border-[#22c55e]/30 text-[#22c55e]' : 'bg-transparent border-transparent text-white/40 hover:text-white hover:bg-white/[0.02]'">
                        <span>📋</span> Архив смен
                    </Link>
                </div>

                <!-- СЕКЦИЯ: КИБЕРСПОРТ (supervisor+) -->
                <div v-if="isSupervisorPlus" class="space-y-2">
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
                    <Link href="/admin/achievements"
                          class="flex items-center gap-4 px-5 py-4 rounded-2xl border transition-all text-xs font-black uppercase tracking-wider"
                          :class="isActive('/admin/achievements') ? 'bg-cyan-500/10 border-cyan-500/30 text-cyan-500' : 'bg-transparent border-transparent text-white/40 hover:text-white hover:bg-white/[0.02]'">
                        <span>⭐</span> Достижения и трофеи
                    </Link>
                    <Link href="/admin/game-requests"
                          class="flex items-center gap-4 px-5 py-4 rounded-2xl border transition-all text-xs font-black uppercase tracking-wider"
                          :class="isActive('/admin/game-requests') ? 'bg-cyan-500/10 border-cyan-500/30 text-cyan-500' : 'bg-transparent border-transparent text-white/40 hover:text-white hover:bg-white/[0.02]'">
                        <span>🎮</span> Заявки на игры
                    </Link>
                    <Link href="/admin/bonuses"
                          class="flex items-center gap-4 px-5 py-4 rounded-2xl border transition-all text-xs font-black uppercase tracking-wider"
                          :class="isActive('/admin/bonuses') ? 'bg-cyan-500/10 border-cyan-500/30 text-cyan-500' : 'bg-transparent border-transparent text-white/40 hover:text-white hover:bg-white/[0.02]'">
                        <span>⭐</span> Бонусы за отзывы
                    </Link>
                    <Link href="/admin/bonus-logs"
                          class="flex items-center gap-4 px-5 py-4 rounded-2xl border transition-all text-xs font-black uppercase tracking-wider"
                          :class="isActive('/admin/bonus-logs') ? 'bg-cyan-500/10 border-cyan-500/30 text-cyan-500' : 'bg-transparent border-transparent text-white/40 hover:text-white hover:bg-white/[0.02]'">
                        <span>📜</span> Реестр бонусов
                    </Link>
                </div>

                <!-- СЕКЦИЯ: ЭКОНОМИКА (supervisor+ / owner) -->
                <div v-if="isSupervisorPlus" class="space-y-2">
                    <div class="text-[10px] text-white/30 font-black uppercase tracking-[0.3em] italic pl-4 mb-3">Экономика</div>
                    <Link href="/admin/tariffs"
                          class="flex items-center gap-4 px-5 py-4 rounded-2xl border transition-all text-xs font-black uppercase tracking-wider"
                          :class="isActive('/admin/tariffs') ? 'bg-cyan-500/10 border-cyan-500/30 text-cyan-500' : 'bg-transparent border-transparent text-white/40 hover:text-white hover:bg-white/[0.02]'">
                        <span>🏷️</span> Тарифы и пакеты
                    </Link>
                    <Link href="/admin/analytics"
                          class="flex items-center gap-4 px-5 py-4 rounded-2xl border transition-all text-xs font-black uppercase tracking-wider"
                          :class="isActive('/admin/analytics') ? 'bg-cyan-500/10 border-cyan-500/30 text-cyan-500' : 'bg-transparent border-transparent text-white/40 hover:text-white hover:bg-white/[0.02]'">
                        <span>📊</span> Аналитика
                    </Link>
                    <Link v-if="isOwner"
                          href="/admin/taxes"
                          class="flex items-center gap-4 px-5 py-4 rounded-2xl border transition-all text-xs font-black uppercase tracking-wider"
                          :class="isActive('/admin/taxes') ? 'bg-cyan-500/10 border-cyan-500/30 text-cyan-500' : 'bg-transparent border-transparent text-white/40 hover:text-white hover:bg-white/[0.02]'">
                        <span>🧾</span> Налоги
                    </Link>
                    <Link v-if="isOwner"
                          href="/admin/staff"
                          class="flex items-center gap-4 px-5 py-4 rounded-2xl border transition-all text-xs font-black uppercase tracking-wider"
                          :class="isActive('/admin/staff') ? 'bg-cyan-500/10 border-cyan-500/30 text-cyan-500' : 'bg-transparent border-transparent text-white/40 hover:text-white hover:bg-white/[0.02]'">
                        <span>👥</span> Штат
                    </Link>
                </div>

                <!-- СЕКЦИЯ: КОНФИГУРАЦИЯ (supervisor+) -->
                <div v-if="isSupervisorPlus" class="space-y-2">
                    <div class="text-[10px] text-white/30 font-black uppercase tracking-[0.3em] italic pl-4 mb-3">Конфигурация</div>
                    <Link href="/admin/zones"
                          class="flex items-center gap-4 px-5 py-4 rounded-2xl border transition-all text-xs font-black uppercase tracking-wider"
                          :class="isActive('/admin/zones') ? 'bg-cyan-500/10 border-cyan-500/30 text-cyan-500' : 'bg-transparent border-transparent text-white/40 hover:text-white hover:bg-white/[0.02]'">
                        <span>📍</span> Топология залов
                    </Link>
                    <Link v-if="isOwner"
                          href="/admin/fans"
                          class="flex items-center gap-4 px-5 py-4 rounded-2xl border transition-all text-xs font-black uppercase tracking-wider"
                          :class="isActive('/admin/fans') ? 'bg-cyan-500/10 border-cyan-500/30 text-cyan-500' : 'bg-transparent border-transparent text-white/40 hover:text-white hover:bg-white/[0.02]'">
                        <span>🌀</span> Вентиляторы
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
                    <Link href="/admin/licenses"
                          class="flex items-center gap-4 px-5 py-4 rounded-2xl border transition-all text-xs font-black uppercase tracking-wider"
                          :class="isActive('/admin/licenses') ? 'bg-cyan-500/10 border-cyan-500/30 text-cyan-500' : 'bg-transparent border-transparent text-white/40 hover:text-white hover:bg-white/[0.02]'">
                        <span>🎮</span> Игры и лицензии
                    </Link>
                    <Link href="/admin/quick-apps"
                          class="flex items-center gap-4 px-5 py-4 rounded-2xl border transition-all text-xs font-black uppercase tracking-wider"
                          :class="isActive('/admin/quick-apps') ? 'bg-cyan-500/10 border-cyan-500/30 text-cyan-500' : 'bg-transparent border-transparent text-white/40 hover:text-white hover:bg-white/[0.02]'">
                        <span>⚡</span> Быстрый софт
                    </Link>
                    <Link href="/admin/video-surveillance"
                          class="flex items-center gap-4 px-5 py-4 rounded-2xl border transition-all text-xs font-black uppercase tracking-wider"
                          :class="isActive('/admin/video-surveillance') ? 'bg-cyan-500/10 border-cyan-500/30 text-cyan-500' : 'bg-transparent border-transparent text-white/40 hover:text-white hover:bg-white/[0.02]'">
                        <span>📹</span> Видео-метки
                    </Link>
                </div>

                <!-- СЕКЦИЯ: БЕЗОПАСНОСТЬ -->
                <div class="space-y-2">
                    <div class="text-[10px] text-white/30 font-black uppercase tracking-[0.3em] italic pl-4 mb-3">Безопасность</div>
                    <Link href="/admin/incidents"
                          class="flex items-center gap-4 px-5 py-4 rounded-2xl border transition-all text-xs font-black uppercase tracking-wider"
                          :class="isActive('/admin/incidents') ? 'bg-red-500/10 border-red-500/30 text-red-500' : 'bg-transparent border-transparent text-white/40 hover:text-white hover:bg-white/[0.02]'">
                        <span>⚠️</span> Инциденты
                        <span v-if="counts.incidents > 0"
                              class="ml-auto min-w-[22px] px-2 py-0.5 rounded-full bg-red-500 text-black text-[10px] font-black tabular-nums text-center shadow-[0_0_12px_rgba(239,68,68,0.45)]"
                              :class="counts.sos > 0 ? 'animate-pulse' : ''">
                            {{ counts.incidents }}
                        </span>
                    </Link>
                </div>

                <!-- СЕКЦИЯ: СПРАВКА -->
                <div class="space-y-2">
                    <div class="text-[10px] text-white/30 font-black uppercase tracking-[0.3em] italic pl-4 mb-3">Справка</div>
                    <Link href="/admin/docs"
                          class="flex items-center gap-4 px-5 py-4 rounded-2xl border transition-all text-xs font-black uppercase tracking-wider"
                          :class="isActive('/admin/docs') ? 'bg-[#22c55e]/10 border-[#22c55e]/30 text-[#22c55e]' : 'bg-transparent border-transparent text-white/40 hover:text-white hover:bg-white/[0.02]'">
                        <span>📖</span> О системе
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
                        <div class="text-xs font-black uppercase italic text-white">{{ adminName }}</div>
                        <div class="text-[9px] font-bold uppercase tracking-wider"
                             :class="shiftIsActive ? 'text-[#22c55e]' : 'text-white/30'">
                            {{ shiftLabel }}
                        </div>
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

        <Toast />
    </div>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar { width: 4px; height: 4px; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.03); border-radius: 10px; }
.custom-scrollbar::-webkit-scrollbar-thumb:hover { background: rgba(6, 182, 212, 0.2); }
</style>
