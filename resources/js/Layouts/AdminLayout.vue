<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3'
import { computed, watch, ref } from 'vue'
import Toast from '@/Components/Toast.vue'
import { useAdminAlerts } from '@/Composables/useAdminAlerts'
import { useAdminBarcodeScanner } from '@/Composables/useAdminBarcodeScanner'
import { useClubName } from '@/Composables/useClubName'
import AvatarWatermarkBg from '@/Components/AvatarWatermarkBg.vue'

useAdminBarcodeScanner().attachGlobalListener()

const page = usePage()
const clubName = useClubName()
const currentUrl = computed(() => page.url)

const currentPath = computed(() => {
    const path = currentUrl.value.split('?')[0].split('#')[0]
    return path.length > 1 ? path.replace(/\/+$/, '') : path
})

const isActive = (url: string) => currentPath.value === url || currentPath.value.startsWith(url + '/')

const { counts, setCounts } = useAdminAlerts()

watch(() => page.props.admin_alerts, (next) => setCounts(next), { immediate: true, deep: true })

const admin = computed(() => page.props.admin_user as any)
const adminName = computed(() => admin.value?.name || admin.value?.email || 'Оператор')
const adminRole = computed(() => admin.value?.role || null)
const isOwner = computed(() => adminRole.value === 'owner')
const isSupervisorPlus = computed(() => adminRole.value === 'supervisor' || adminRole.value === 'owner')
const canAccessClub = computed(() => isOwner.value || Boolean(page.props.can_access_club))
const canAccessStore = computed(() => isOwner.value || Boolean(page.props.can_access_store))
const location = computed(() => page.props.admin_location as any)
const locations = computed(() => (page.props.admin_locations as any[]) || [])
const switching = ref(false)
const showLocationSwitcher = computed(() => isOwner.value && locations.value.length > 0)

const shift = computed(() => page.props.admin_shift as any)
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

const switchLocation = (clubId: string | number) => {
    if (!clubId || switching.value) return
    switching.value = true
    router.post('/admin/store/location/switch', { club_id: Number(clubId) }, {
        preserveScroll: true,
        onFinish: () => { switching.value = false },
    })
}
</script>

<template>
    <div class="admin-ui min-h-screen bg-[#050505] flex font-sans text-white selection:bg-cyan-500 selection:text-black relative">
        <AvatarWatermarkBg />

        <aside class="relative z-10 w-[320px] bg-[#020202]/92 backdrop-blur-md border-r border-white/5 flex flex-col shrink-0 min-h-screen select-none">

            <div class="p-8 border-b border-white/5">
                <div class="flex items-center gap-3">
                    <div class="w-3 h-3 bg-[#22c55e] rounded-full animate-pulse shadow-[0_0_10px_#22c55e]"></div>
                    <span class="text-2xl font-bold uppercase tracking-tight text-white">
                        {{ clubName }} <span class="text-[#22c55e]">Ctrl</span>
                    </span>
                </div>
                <div class="text-[11px] text-white/35 uppercase font-semibold tracking-[0.18em] mt-2">
                    Terminal Node // v2.7
                </div>
                <div v-if="showLocationSwitcher" class="mt-4">
                    <div class="text-[11px] text-white/45 font-semibold uppercase tracking-[0.16em] mb-2">Локация</div>
                    <select
                        class="w-full bg-black/40 border border-cyan-500/30 rounded-xl px-3 py-2.5 text-xs font-semibold uppercase tracking-wider text-cyan-300 outline-none"
                        :value="location?.id || ''"
                        :disabled="switching"
                        @change="switchLocation(($event.target as HTMLSelectElement).value)"
                    >
                        <option v-for="loc in locations" :key="loc.id" :value="loc.id">
                            {{ loc.name }} ({{ loc.type }})
                        </option>
                    </select>
                </div>
                <div v-else-if="location" class="mt-4 text-xs uppercase tracking-wider text-cyan-400/80 font-semibold">
                    {{ location.name }}
                    <span class="text-white/30">· {{ location.type }}</span>
                </div>
            </div>

            <div class="flex-1 overflow-y-auto py-8 px-6 space-y-8 custom-scrollbar">

                <!-- СЕКЦИЯ: ОПЕРАЦИИ КЛУБА -->
                <div v-if="canAccessClub" class="space-y-2">
                    <div class="text-[11px] text-white/45 font-semibold uppercase tracking-[0.16em] pl-4 mb-3">Операции</div>
                    <Link href="/admin/dashboard"
                          class="flex items-center gap-4 px-5 py-3.5 rounded-2xl border transition-all text-[13px] font-semibold uppercase tracking-wide"
                          :class="isActive('/admin/dashboard') ? 'bg-[#22c55e]/10 border-[#22c55e]/30 text-[#22c55e] shadow-[0_0_30px_rgba(34,197,94,0.05)]' : 'bg-transparent border-transparent text-white/55 hover:text-white hover:bg-white/[0.02]'">
                        <span>⚡</span> Дашбоард
                    </Link>
                    <Link href="/admin/orders"
                          class="flex items-center gap-4 px-5 py-3.5 rounded-2xl border transition-all text-[13px] font-semibold uppercase tracking-wide"
                          :class="isActive('/admin/orders') ? 'bg-[#22c55e]/10 border-[#22c55e]/30 text-[#22c55e]' : 'bg-transparent border-transparent text-white/55 hover:text-white hover:bg-white/[0.02]'">
                        <span>📦</span> Очередь заказов
                        <span v-if="counts.pending_orders > 0"
                              class="ml-auto min-w-[22px] px-2 py-0.5 rounded-full bg-amber-500 text-black text-[10px] font-black tabular-nums text-center shadow-[0_0_12px_rgba(245,158,11,0.4)]">
                            {{ counts.pending_orders }}
                        </span>
                    </Link>
                    <Link href="/admin/transactions"
                          class="flex items-center gap-4 px-5 py-3.5 rounded-2xl border transition-all text-[13px] font-semibold uppercase tracking-wide"
                          :class="isActive('/admin/transactions') ? 'bg-[#22c55e]/10 border-[#22c55e]/30 text-[#22c55e]' : 'bg-transparent border-transparent text-white/55 hover:text-white hover:bg-white/[0.02]'">
                        <span>🧾</span> Транзакции
                    </Link>
                    <Link href="/admin/inventory"
                          class="flex items-center gap-4 px-5 py-3.5 rounded-2xl border transition-all text-[13px] font-semibold uppercase tracking-wide"
                          :class="isActive('/admin/inventory') ? 'bg-[#22c55e]/10 border-[#22c55e]/30 text-[#22c55e]' : 'bg-transparent border-transparent text-white/55 hover:text-white hover:bg-white/[0.02]'">
                        <span>📦</span> Склад
                    </Link>
                    <Link v-if="isSupervisorPlus" href="/admin/suppliers"
                          class="flex items-center gap-4 px-5 py-3.5 rounded-2xl border transition-all text-[13px] font-semibold uppercase tracking-wide"
                          :class="isActive('/admin/suppliers') ? 'bg-[#22c55e]/10 border-[#22c55e]/30 text-[#22c55e]' : 'bg-transparent border-transparent text-white/55 hover:text-white hover:bg-white/[0.02]'">
                        <span>🚚</span> Поставщики
                    </Link>
                    <Link href="/admin/shifts/transfer"
                          class="flex items-center gap-4 px-5 py-3.5 rounded-2xl border transition-all text-[13px] font-semibold uppercase tracking-wide"
                          :class="isActive('/admin/shifts/transfer') ? 'bg-[#22c55e]/10 border-[#22c55e]/30 text-[#22c55e]' : 'bg-transparent border-transparent text-white/55 hover:text-white hover:bg-white/[0.02]'">
                        <span>🔄</span> Пересменка
                    </Link>
                    <Link href="/admin/shifts/history"
                          class="flex items-center gap-4 px-5 py-3.5 rounded-2xl border transition-all text-[13px] font-semibold uppercase tracking-wide"
                          :class="isActive('/admin/shifts/history') ? 'bg-[#22c55e]/10 border-[#22c55e]/30 text-[#22c55e]' : 'bg-transparent border-transparent text-white/55 hover:text-white hover:bg-white/[0.02]'">
                        <span>📋</span> Архив смен
                    </Link>
                    <Link href="/admin/salary"
                          class="flex items-center gap-4 px-5 py-3.5 rounded-2xl border transition-all text-[13px] font-semibold uppercase tracking-wide"
                          :class="isActive('/admin/salary') ? 'bg-[#22c55e]/10 border-[#22c55e]/30 text-[#22c55e]' : 'bg-transparent border-transparent text-white/55 hover:text-white hover:bg-white/[0.02]'">
                        <span>💰</span> Моя зарплата
                    </Link>
                </div>

                <!-- СЕКЦИЯ: МАГАЗИН ПРИ КЛУБЕ -->
                <div v-if="canAccessStore || isOwner" class="space-y-2">
                    <div class="text-[11px] text-white/45 font-semibold uppercase tracking-[0.16em] pl-4 mb-3">Магазин</div>
                    <Link v-if="canAccessStore || isOwner" href="/admin/store/warehouse"
                          class="flex items-center gap-4 px-5 py-3.5 rounded-2xl border transition-all text-[13px] font-semibold uppercase tracking-wide"
                          :class="isActive('/admin/store/warehouse') ? 'bg-amber-500/10 border-amber-500/30 text-amber-400' : 'bg-transparent border-transparent text-white/55 hover:text-white hover:bg-white/[0.02]'">
                        <span>🧩</span> Склад комплектующих
                    </Link>
                    <Link v-if="canAccessStore || isOwner" href="/admin/store/estimates"
                          class="flex items-center gap-4 px-5 py-3.5 rounded-2xl border transition-all text-[13px] font-semibold uppercase tracking-wide"
                          :class="isActive('/admin/store/estimates') ? 'bg-amber-500/10 border-amber-500/30 text-amber-400' : 'bg-transparent border-transparent text-white/55 hover:text-white hover:bg-white/[0.02]'">
                        <span>📋</span> Сметы
                    </Link>
                    <Link v-if="canAccessStore || isOwner" href="/admin/store/built-pcs"
                          class="flex items-center gap-4 px-5 py-3.5 rounded-2xl border transition-all text-[13px] font-semibold uppercase tracking-wide"
                          :class="isActive('/admin/store/built-pcs') ? 'bg-amber-500/10 border-amber-500/30 text-amber-400' : 'bg-transparent border-transparent text-white/55 hover:text-white hover:bg-white/[0.02]'">
                        <span>🖥️</span> Готовые ПК
                    </Link>
                    <Link v-if="canAccessStore || isOwner" href="/admin/store/orders"
                          class="flex items-center gap-4 px-5 py-3.5 rounded-2xl border transition-all text-[13px] font-semibold uppercase tracking-wide"
                          :class="isActive('/admin/store/orders') ? 'bg-amber-500/10 border-amber-500/30 text-amber-400' : 'bg-transparent border-transparent text-white/55 hover:text-white hover:bg-white/[0.02]'">
                        <span>🛠️</span> Заказы
                    </Link>
                    <Link v-if="canAccessStore || isOwner" href="/admin/store/warranty"
                          class="flex items-center gap-4 px-5 py-3.5 rounded-2xl border transition-all text-[13px] font-semibold uppercase tracking-wide"
                          :class="isActive('/admin/store/warranty') ? 'bg-amber-500/10 border-amber-500/30 text-amber-400' : 'bg-transparent border-transparent text-white/55 hover:text-white hover:bg-white/[0.02]'">
                        <span>🛡️</span> Гарантия
                    </Link>
                    <Link v-if="canAccessStore || isOwner" href="/admin/store/clients"
                          class="flex items-center gap-4 px-5 py-3.5 rounded-2xl border transition-all text-[13px] font-semibold uppercase tracking-wide"
                          :class="isActive('/admin/store/clients') ? 'bg-amber-500/10 border-amber-500/30 text-amber-400' : 'bg-transparent border-transparent text-white/55 hover:text-white hover:bg-white/[0.02]'">
                        <span>👤</span> Клиенты
                    </Link>
                    <Link v-if="canAccessStore || isOwner" href="/admin/store/avito"
                          class="flex items-center gap-4 px-5 py-3.5 rounded-2xl border transition-all text-[13px] font-semibold uppercase tracking-wide"
                          :class="isActive('/admin/store/avito') ? 'bg-amber-500/10 border-amber-500/30 text-amber-400' : 'bg-transparent border-transparent text-white/55 hover:text-white hover:bg-white/[0.02]'">
                        <span>📣</span> Avito
                        <span v-if="counts.avito_unread > 0"
                              class="ml-auto min-w-[22px] px-2 py-0.5 rounded-full bg-amber-500 text-black text-[10px] font-black tabular-nums text-center">
                            {{ counts.avito_unread }}
                        </span>
                    </Link>
                    <Link v-if="isOwner" href="/admin/store/locations"
                          class="flex items-center gap-4 px-5 py-3.5 rounded-2xl border transition-all text-[13px] font-semibold uppercase tracking-wide"
                          :class="isActive('/admin/store/locations') ? 'bg-amber-500/10 border-amber-500/30 text-amber-400' : 'bg-transparent border-transparent text-white/55 hover:text-white hover:bg-white/[0.02]'">
                        <span>📍</span> Локации
                    </Link>
                </div>

                <!-- СЕКЦИЯ: КИБЕРСПОРТ (supervisor+) -->
                <div v-if="canAccessClub && isSupervisorPlus" class="space-y-2">
                    <div class="text-[11px] text-white/45 font-semibold uppercase tracking-[0.16em] pl-4 mb-3">Киберспорт</div>
                    <Link href="/admin/tournaments"
                          class="flex items-center gap-4 px-5 py-3.5 rounded-2xl border transition-all text-[13px] font-semibold uppercase tracking-wide"
                          :class="isActive('/admin/tournaments') ? 'bg-cyan-500/10 border-cyan-500/30 text-cyan-500' : 'bg-transparent border-transparent text-white/55 hover:text-white hover:bg-white/[0.02]'">
                        <span>🏆</span> Менеджер ивентов
                    </Link>
                    <Link href="/admin/promocodes"
                          class="flex items-center gap-4 px-5 py-3.5 rounded-2xl border transition-all text-[13px] font-semibold uppercase tracking-wide"
                          :class="isActive('/admin/promocodes') ? 'bg-cyan-500/10 border-cyan-500/30 text-cyan-500' : 'bg-transparent border-transparent text-white/55 hover:text-white hover:bg-white/[0.02]'">
                        <span>🎁</span> Маркетинг
                    </Link>
                    <Link href="/admin/achievements"
                          class="flex items-center gap-4 px-5 py-3.5 rounded-2xl border transition-all text-[13px] font-semibold uppercase tracking-wide"
                          :class="isActive('/admin/achievements') ? 'bg-cyan-500/10 border-cyan-500/30 text-cyan-500' : 'bg-transparent border-transparent text-white/55 hover:text-white hover:bg-white/[0.02]'">
                        <span>⭐</span> Достижения и трофеи
                    </Link>
                    <Link href="/admin/game-requests"
                          class="flex items-center gap-4 px-5 py-3.5 rounded-2xl border transition-all text-[13px] font-semibold uppercase tracking-wide"
                          :class="isActive('/admin/game-requests') ? 'bg-cyan-500/10 border-cyan-500/30 text-cyan-500' : 'bg-transparent border-transparent text-white/55 hover:text-white hover:bg-white/[0.02]'">
                        <span>🎮</span> Заявки на игры
                    </Link>
                    <Link href="/admin/bonuses"
                          class="flex items-center gap-4 px-5 py-3.5 rounded-2xl border transition-all text-[13px] font-semibold uppercase tracking-wide"
                          :class="isActive('/admin/bonuses') ? 'bg-cyan-500/10 border-cyan-500/30 text-cyan-500' : 'bg-transparent border-transparent text-white/55 hover:text-white hover:bg-white/[0.02]'">
                        <span>⭐</span> Бонусы за отзывы
                    </Link>
                    <Link href="/admin/bonus-logs"
                          class="flex items-center gap-4 px-5 py-3.5 rounded-2xl border transition-all text-[13px] font-semibold uppercase tracking-wide"
                          :class="isActive('/admin/bonus-logs') ? 'bg-cyan-500/10 border-cyan-500/30 text-cyan-500' : 'bg-transparent border-transparent text-white/55 hover:text-white hover:bg-white/[0.02]'">
                        <span>📜</span> Реестр бонусов
                    </Link>
                </div>

                <!-- СЕКЦИЯ: ЭКОНОМИКА (supervisor+ / owner) -->
                <div v-if="canAccessClub && isSupervisorPlus" class="space-y-2">
                    <div class="text-[11px] text-white/45 font-semibold uppercase tracking-[0.16em] pl-4 mb-3">Экономика</div>
                    <Link href="/admin/tariffs"
                          class="flex items-center gap-4 px-5 py-3.5 rounded-2xl border transition-all text-[13px] font-semibold uppercase tracking-wide"
                          :class="isActive('/admin/tariffs') ? 'bg-cyan-500/10 border-cyan-500/30 text-cyan-500' : 'bg-transparent border-transparent text-white/55 hover:text-white hover:bg-white/[0.02]'">
                        <span>🏷️</span> Тарифы и пакеты
                    </Link>
                    <Link href="/admin/booking-settings"
                          class="flex items-center gap-4 px-5 py-3.5 rounded-2xl border transition-all text-[13px] font-semibold uppercase tracking-wide"
                          :class="isActive('/admin/booking-settings') ? 'bg-cyan-500/10 border-cyan-500/30 text-cyan-500' : 'bg-transparent border-transparent text-white/55 hover:text-white hover:bg-white/[0.02]'">
                        <span>⏱️</span> Правила брони
                    </Link>
                    <Link href="/admin/analytics"
                          class="flex items-center gap-4 px-5 py-3.5 rounded-2xl border transition-all text-[13px] font-semibold uppercase tracking-wide"
                          :class="isActive('/admin/analytics') ? 'bg-cyan-500/10 border-cyan-500/30 text-cyan-500' : 'bg-transparent border-transparent text-white/55 hover:text-white hover:bg-white/[0.02]'">
                        <span>📊</span> Аналитика
                    </Link>
                    <Link v-if="isOwner"
                          href="/admin/taxes"
                          class="flex items-center gap-4 px-5 py-3.5 rounded-2xl border transition-all text-[13px] font-semibold uppercase tracking-wide"
                          :class="isActive('/admin/taxes') ? 'bg-cyan-500/10 border-cyan-500/30 text-cyan-500' : 'bg-transparent border-transparent text-white/55 hover:text-white hover:bg-white/[0.02]'">
                        <span>🧾</span> Налоги
                    </Link>
                    <Link v-if="isOwner"
                          href="/admin/staff"
                          class="flex items-center gap-4 px-5 py-3.5 rounded-2xl border transition-all text-[13px] font-semibold uppercase tracking-wide"
                          :class="isActive('/admin/staff') ? 'bg-cyan-500/10 border-cyan-500/30 text-cyan-500' : 'bg-transparent border-transparent text-white/55 hover:text-white hover:bg-white/[0.02]'">
                        <span>👥</span> Штат
                    </Link>
                </div>

                <!-- СЕКЦИЯ: КОНФИГУРАЦИЯ (supervisor+) -->
                <div v-if="canAccessClub && isSupervisorPlus" class="space-y-2">
                    <div class="text-[11px] text-white/45 font-semibold uppercase tracking-[0.16em] pl-4 mb-3">Конфигурация</div>
                    <Link href="/admin/zones"
                          class="flex items-center gap-4 px-5 py-3.5 rounded-2xl border transition-all text-[13px] font-semibold uppercase tracking-wide"
                          :class="isActive('/admin/zones') ? 'bg-cyan-500/10 border-cyan-500/30 text-cyan-500' : 'bg-transparent border-transparent text-white/55 hover:text-white hover:bg-white/[0.02]'">
                        <span>📍</span> Топология залов
                    </Link>
                    <Link href="/admin/fans"
                          class="flex items-center gap-4 px-5 py-3.5 rounded-2xl border transition-all text-[13px] font-semibold uppercase tracking-wide"
                          :class="isActive('/admin/fans') ? 'bg-cyan-500/10 border-cyan-500/30 text-cyan-500' : 'bg-transparent border-transparent text-white/55 hover:text-white hover:bg-white/[0.02]'">
                        <span>🌀</span> Вентиляторы
                    </Link>
                    <Link href="/admin/lights"
                          class="flex items-center gap-4 px-5 py-3.5 rounded-2xl border transition-all text-[13px] font-semibold uppercase tracking-wide"
                          :class="isActive('/admin/lights') ? 'bg-cyan-500/10 border-cyan-500/30 text-cyan-500' : 'bg-transparent border-transparent text-white/55 hover:text-white hover:bg-white/[0.02]'">
                        <span>💡</span> Свет DMX
                    </Link>
                    <Link href="/admin/map-builder"
                          class="flex items-center gap-4 px-5 py-3.5 rounded-2xl border transition-all text-[13px] font-semibold uppercase tracking-wide"
                          :class="isActive('/admin/map-builder') ? 'bg-cyan-500/10 border-cyan-500/30 text-cyan-500' : 'bg-transparent border-transparent text-white/55 hover:text-white hover:bg-white/[0.02]'">
                        <span>🗺️</span> Редактор карты
                    </Link>
                    <Link href="/admin/overlays"
                          class="flex items-center gap-4 px-5 py-3.5 rounded-2xl border transition-all text-[13px] font-semibold uppercase tracking-wide"
                          :class="isActive('/admin/overlays') ? 'bg-cyan-500/10 border-cyan-500/30 text-cyan-500' : 'bg-transparent border-transparent text-white/55 hover:text-white hover:bg-white/[0.02]'">
                        <span>🖥️</span> Shell Оверлеи
                    </Link>
                    <Link href="/admin/licenses"
                          class="flex items-center gap-4 px-5 py-3.5 rounded-2xl border transition-all text-[13px] font-semibold uppercase tracking-wide"
                          :class="isActive('/admin/licenses') ? 'bg-cyan-500/10 border-cyan-500/30 text-cyan-500' : 'bg-transparent border-transparent text-white/55 hover:text-white hover:bg-white/[0.02]'">
                        <span>🎮</span> Игры и лицензии
                    </Link>
                    <Link href="/admin/quick-apps"
                          class="flex items-center gap-4 px-5 py-3.5 rounded-2xl border transition-all text-[13px] font-semibold uppercase tracking-wide"
                          :class="isActive('/admin/quick-apps') ? 'bg-cyan-500/10 border-cyan-500/30 text-cyan-500' : 'bg-transparent border-transparent text-white/55 hover:text-white hover:bg-white/[0.02]'">
                        <span>⚡</span> Быстрый софт
                    </Link>
                    <Link href="/admin/video-surveillance"
                          class="flex items-center gap-4 px-5 py-3.5 rounded-2xl border transition-all text-[13px] font-semibold uppercase tracking-wide"
                          :class="isActive('/admin/video-surveillance') ? 'bg-cyan-500/10 border-cyan-500/30 text-cyan-500' : 'bg-transparent border-transparent text-white/55 hover:text-white hover:bg-white/[0.02]'">
                        <span>📹</span> Видео-метки
                    </Link>
                    <Link href="/admin/ai-assistant"
                          class="flex items-center gap-4 px-5 py-3.5 rounded-2xl border transition-all text-[13px] font-semibold uppercase tracking-wide"
                          :class="isActive('/admin/ai-assistant') ? 'bg-cyan-500/10 border-cyan-500/30 text-cyan-500' : 'bg-transparent border-transparent text-white/55 hover:text-white hover:bg-white/[0.02]'">
                        <span>🎙️</span> ИИ-ассистент
                    </Link>
                </div>

                <!-- СЕКЦИЯ: БЕЗОПАСНОСТЬ -->
                <div v-if="canAccessClub" class="space-y-2">
                    <div class="text-[11px] text-white/45 font-semibold uppercase tracking-[0.16em] pl-4 mb-3">Безопасность</div>
                    <Link href="/admin/incidents"
                          class="flex items-center gap-4 px-5 py-3.5 rounded-2xl border transition-all text-[13px] font-semibold uppercase tracking-wide"
                          :class="isActive('/admin/incidents') ? 'bg-red-500/10 border-red-500/30 text-red-500' : 'bg-transparent border-transparent text-white/55 hover:text-white hover:bg-white/[0.02]'">
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
                    <div class="text-[11px] text-white/45 font-semibold uppercase tracking-[0.16em] pl-4 mb-3">Справка</div>
                    <Link v-if="!canAccessClub" href="/admin/salary"
                          class="flex items-center gap-4 px-5 py-3.5 rounded-2xl border transition-all text-[13px] font-semibold uppercase tracking-wide"
                          :class="isActive('/admin/salary') ? 'bg-[#22c55e]/10 border-[#22c55e]/30 text-[#22c55e]' : 'bg-transparent border-transparent text-white/55 hover:text-white hover:bg-white/[0.02]'">
                        <span>💰</span> Моя зарплата
                    </Link>
                    <Link href="/admin/docs"
                          class="flex items-center gap-4 px-5 py-3.5 rounded-2xl border transition-all text-[13px] font-semibold uppercase tracking-wide"
                          :class="isActive('/admin/docs') ? 'bg-[#22c55e]/10 border-[#22c55e]/30 text-[#22c55e]' : 'bg-transparent border-transparent text-white/55 hover:text-white hover:bg-white/[0.02]'">
                        <span>📖</span> О системе
                    </Link>
                </div>

            </div>
        </aside>

        <div class="relative z-10 flex-1 flex flex-col min-h-screen overflow-hidden">

            <header class="h-24 border-b border-white/5 flex items-center justify-between px-10 select-none bg-[#020202]/50 backdrop-blur-md shrink-0">
                <div class="flex items-center gap-4 text-xs uppercase font-semibold tracking-wider text-white/50">
                    <span>Node: <span class="text-cyan-400">{{ currentUrl }}</span></span>
                    <div v-if="isOwner" class="flex items-center gap-2">
                        <span class="text-white/40">Локация</span>
                        <select
                            v-if="locations.length > 0"
                            class="bg-black/40 border border-cyan-500/30 rounded-xl px-3 py-2 text-xs font-semibold uppercase tracking-wider text-cyan-300 outline-none"
                            :value="location?.id || ''"
                            :disabled="switching"
                            @change="switchLocation(($event.target as HTMLSelectElement).value)"
                        >
                            <option v-for="loc in locations" :key="loc.id" :value="loc.id">
                                {{ loc.name }} ({{ loc.type }})
                            </option>
                        </select>
                        <Link href="/admin/store/locations"
                              class="px-3 py-2 border border-white/10 hover:border-cyan-500/40 text-white/50 hover:text-cyan-300 rounded-xl text-xs font-semibold uppercase transition-all">
                            Управление
                        </Link>
                    </div>
                </div>

                <div class="flex items-center gap-6">
                    <div class="text-right">
                        <div class="text-sm font-semibold uppercase text-white">{{ adminName }}</div>
                        <div class="text-[11px] font-medium uppercase tracking-wider"
                             :class="shiftIsActive ? 'text-[#22c55e]' : 'text-white/30'">
                            {{ shiftLabel }}
                        </div>
                    </div>
                    <Link href="/admin/logout" method="post" as="button" class="px-4 py-2 border border-white/10 hover:border-red-500/40 text-white/50 hover:text-red-500 rounded-xl text-xs font-semibold uppercase transition-all">
                        Exit Node
                    </Link>
                </div>
            </header>

            <main class="flex-1 overflow-y-auto custom-scrollbar bg-transparent">
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
