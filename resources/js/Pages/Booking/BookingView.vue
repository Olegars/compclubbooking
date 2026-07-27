<script setup lang="ts">
import { ref, computed, nextTick, onMounted, onUnmounted, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'

import MainLayout from '@/Layouts/MainLayout.vue'
import TerminalLayout from '@/Layouts/TerminalLayout.vue'

import ClubMap from '@/Components/ClubMap.vue'
import ConfirmModal from '@/Components/ConfirmModal.vue'
import SmsModal from '@/Components/SmsModal.vue'
import PaymentModal from '@/Components/PaymentModal.vue'
import ZoneInfoModal from '@/Components/ZoneInfoModal.vue'
import TariffsModal from '@/Components/TariffsModal.vue'
import AgeWarningModal from '@/Components/AgeWarningModal.vue'

const props = withDefaults(defineProps<{
    clubData: {
        id: number;
        name: string;
        map_config: string;
        viewbox: string;
    };
    computersList: any[];
    zonesList: any[];
    zoneRectsList: any[];
    isTerminal?: boolean;
}>(), {
    isTerminal: false
})

type BookingGame = {
    id: number;
    club_game_id?: number;
    title: string;
    platform?: string;
    poster?: string | null;
    is_paid?: boolean;
    billing_mode?: string;
    unit_price_minor?: number;
    billing_unit_minutes?: number;
    currency?: string;
    available_accounts?: number;
    required_accounts?: number;
    is_installed?: boolean;
    is_available: boolean;
}

const layout = computed(() => props.isTerminal ? TerminalLayout : MainLayout)

// --- ПАРСИНГ КАРТЫ ---
const cleanMapConfig = computed(() => {
    try {
        let config = typeof props.clubData.map_config === 'string'
            ? JSON.parse(props.clubData.map_config)
            : props.clubData.map_config;
        if (!config) return { walls: [], zoneRects: [], labels: [] };
        return {
            ...config,
            walls: Array.isArray(config.walls) ? config.walls.filter((w: any) => w && w.d) : [],
            zoneRects: Array.isArray(config.zoneRects) ? config.zoneRects.filter((z: any) => z && z.w) : [],
            labels: Array.isArray(config.labels)
                ? config.labels.filter((l: any) => {
                    const t = String(l?.content ?? '').trim().toUpperCase()
                    return l && t && t !== 'ТЕКСТ' && t !== 'TEXT'
                })
                : [],
            viewbox: config.viewbox || props.clubData.viewbox || '-10 -10 120 200',
        };
    } catch (e) {
        return { walls: [], zoneRects: [], labels: [] };
    }
});

// --- ВЫБОР МЕСТ ---
const bookedOccupiedIds = ref<string[]>([])
const isSeatsLoading = ref(false)
let seatsRequestId = 0

const staticOccupiedIds = computed(() =>
    props.computersList.filter(pc => pc.status !== 'available').map(pc => pc.id.toString())
)
const occupiedIds = computed(() =>
    [...new Set([...staticOccupiedIds.value, ...bookedOccupiedIds.value])]
)
const selectedIds = ref<string[]>([])
const seatError = ref(false)
let errorTimer: ReturnType<typeof setTimeout> | null = null

const handleSeatError = () => {
    seatError.value = true
    if (errorTimer) clearTimeout(errorTimer)
    errorTimer = setTimeout(() => { seatError.value = false }, 1500)
}

const fetchOccupiedSeats = async () => {
    const requestId = ++seatsRequestId
    if (duration.value <= 0 || !props.clubData?.id) {
        bookedOccupiedIds.value = []
        return
    }

    isSeatsLoading.value = true
    try {
        const response = await axios.post('/api/booking/computers/availability', {
            club_id: props.clubData.id,
            ...bookingInterval.value
        })
        if (requestId !== seatsRequestId) return

        const ids = Array.isArray(response.data?.occupied_pc_ids)
            ? response.data.occupied_pc_ids.map((id: number | string) => id.toString())
            : []
        bookedOccupiedIds.value = ids
        selectedIds.value = selectedIds.value.filter(id => !ids.includes(id))
    } catch (e) {
        if (requestId !== seatsRequestId) return
        console.error('Ошибка загрузки занятости мест', e)
        // При сбое не разблокируем занятые — оставляем прошлый снимок + offline.
    } finally {
        if (requestId === seatsRequestId) isSeatsLoading.value = false
    }
}

// --- СОСТОЯНИЕ МОДАЛОК ---
const showOverlay = ref(false)
const showConfirmModal = ref(false)
const showSmsModal = ref(false)
const showAgeWarning = ref(false)
const showSuccessModal = ref(false)
const showInfoModal = ref(false)
const showTariffsModal = ref(false)

const selectedZoneForInfo = ref('PRO')
const userPhone = ref('')
const isProcessing = ref(false)

// --- ВРЕМЯ И ТАРИФЫ ---
const selectedDate = ref(new Date().toDateString())
const bookingMode = ref('hourly')
const selectedPackage = ref<any>(null)

const timeSteps = Array.from({ length: 96 }, (_, i) => i * 0.25)
const formatTimeLabel = (h: number) => {
    const hours = Math.floor(h).toString().padStart(2, '0')
    const mins = Math.round((h % 1) * 60).toString().padStart(2, '0')
    return `${hours}:${mins}`
}
const getIndexByTime = (time: number) => {
    const val = Math.round(time * 4) / 4
    const idx = timeSteps.indexOf(val)
    return idx === -1 ? 0 : idx
}
const getNextQuarter = () => {
    const d = new Date(), h = d.getHours(), m = d.getMinutes()
    if (m < 15) return h + 0.25; if (m < 30) return h + 0.5; if (m < 45) return h + 0.75;
    return (h + 1) % 24
}
const mod24 = (n: number) => ((n % 24) + 24) % 24

const startH = ref(getNextQuarter())
const endH = ref(mod24(startH.value + 1))

const duration = computed(() => {
    if (bookingMode.value === 'packages' && selectedPackage.value) return selectedPackage.value.hours
    const d = mod24(endH.value - startH.value)
    return d === 0 ? 24 : d
})

const bookingInterval = computed(() => {
    const startsAt = new Date(selectedDate.value)
    const hours = Math.floor(startH.value)
    const minutes = Math.round((startH.value - hours) * 60)
    startsAt.setHours(hours, minutes, 0, 0)

    const endsAt = new Date(startsAt.getTime() + Math.round(duration.value * 60) * 60_000)
    return {
        starts_at: startsAt.toISOString(),
        ends_at: endsAt.toISOString()
    }
})

// --- ИГРЫ И ДИНАМИЧЕСКИЙ РАСЧЕТ СТОИМОСТИ ---
const availableGames = ref<BookingGame[]>([])
const selectedGameIds = ref<number[]>([])
const isGamesLoading = ref(false)
const gamesError = ref('')
let availabilityRequestId = 0

const bookingPayload = computed(() => ({
    club_id: props.clubData.id,
    pc_ids: [...selectedIds.value],
    game_ids: [...selectedGameIds.value],
    ...bookingInterval.value
}))

const fetchGamesAvailability = async () => {
    const requestId = ++availabilityRequestId
    gamesError.value = ''

    if (selectedIds.value.length === 0 || duration.value <= 0) {
        availableGames.value = []
        selectedGameIds.value = []
        isGamesLoading.value = false
        return
    }

    isGamesLoading.value = true
    try {
        const response = await axios.post('/api/booking/games/availability', {
            club_id: props.clubData.id,
            pc_ids: [...selectedIds.value],
            ...bookingInterval.value
        })
        if (requestId !== availabilityRequestId) return

        const games: BookingGame[] = Array.isArray(response.data?.games) ? response.data.games : []
        // Недоступные игры остаются в списке (но невыбираемыми), чтобы пользователь видел ассортимент клуба.
        availableGames.value = [...games].sort((a, b) => Number(b.is_available) - Number(a.is_available))
        const availableIds = new Set(games.filter(game => game.is_available).map(game => game.id))
        selectedGameIds.value = selectedGameIds.value.filter(id => availableIds.has(id))
    } catch (e) {
        if (requestId !== availabilityRequestId) return
        console.error('Ошибка загрузки доступных игр', e)
        availableGames.value = []
        selectedGameIds.value = []
        gamesError.value = 'Игры временно недоступны'
    } finally {
        if (requestId === availabilityRequestId) isGamesLoading.value = false
    }
}

const totalAmount = ref(0)
const computersTotalMinor = ref(0)
const gamesTotalMinor = ref(0)
const isPriceLoading = ref(false)
const priceError = ref('')
let priceRequestId = 0

const fetchServerPrice = async () => {
    const requestId = ++priceRequestId
    priceError.value = ''
    if (selectedIds.value.length === 0 || duration.value <= 0 || isGamesLoading.value) {
        isPriceLoading.value = false
        if (selectedIds.value.length === 0 || duration.value <= 0) {
            totalAmount.value = 0
            computersTotalMinor.value = 0
            gamesTotalMinor.value = 0
        }
        return
    }

    isPriceLoading.value = true
    try {
        const response = await axios.post('/api/booking/calculate-price', bookingPayload.value)
        if (requestId !== priceRequestId) return

        const totalMinor = Number(response.data?.total_minor ?? 0)
        totalAmount.value = Number(response.data?.total_price ?? totalMinor / 100)
        computersTotalMinor.value = Number(response.data?.computers_total_minor ?? 0)
        gamesTotalMinor.value = Number(response.data?.games_total_minor ?? 0)
    } catch (e: any) {
        if (requestId !== priceRequestId) return
        console.error('Ошибка расчета стоимости', e)
        totalAmount.value = 0
        computersTotalMinor.value = 0
        gamesTotalMinor.value = 0
        const errors = e?.response?.data?.errors
        priceError.value = errors?.pc_ids?.[0]
            || errors?.starts_at?.[0]
            || errors?.ends_at?.[0]
            || errors?.game_ids?.[0]
            || e?.response?.data?.message
            || 'Не удалось рассчитать стоимость'
    } finally {
        if (requestId === priceRequestId) isPriceLoading.value = false
    }
}

const formatMoney = (value: number) =>
    new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(value)

const formatBillingUnit = (minutes?: number) => {
    const unit = Number(minutes ?? 60) || 60
    if (unit === 60) return 'час'
    if (unit % 60 === 0) return `${unit / 60} ч`
    return `${unit} мин`
}

const isGameFree = (game: BookingGame) =>
    game.is_paid === false || game.billing_mode === 'free' || Number(game.unit_price_minor ?? 0) <= 0

const formatGamePrice = (game: BookingGame) => {
    if (isGameFree(game)) return 'БЕСПЛАТНО'
    const money = `${formatMoney(Number(game.unit_price_minor) / 100)} ₽`
    // Тарификация за единицу времени — только у почасовых режимов, остальные списываются разово.
    return ['per_seat_hour', 'per_booking_hour'].includes(game.billing_mode || '')
        ? `${money} / ${formatBillingUnit(game.billing_unit_minutes)}`
        : money
}

const gameBillingNote = (game: BookingGame) => {
    if (isGameFree(game)) return 'включено в бронь'
    switch (game.billing_mode) {
        case 'per_seat_hour': return 'за каждое место'
        case 'per_seat_booking': return 'за каждое место, разово'
        case 'per_booking_hour': return 'за всю бронь'
        case 'fixed': return 'за всю бронь, разово'
        default: return ''
    }
}

const gameBlockReason = (game: BookingGame) => {
    if (game.is_installed === false) return 'Установлена не на всех выбранных ПК'
    const required = Number(game.required_accounts ?? 0)
    const available = Number(game.available_accounts ?? 0)
    if (required > 0 && available < required) {
        return `Свободных аккаунтов: ${available} из ${required}`
    }
    return 'Недоступна на выбранный интервал'
}

const isBookingLoading = computed(() => isGamesLoading.value || isPriceLoading.value)

// --- ОБНАРУЖИВАЕМОСТЬ БЛОКА ИГР ---
const selectedGamesCount = computed(() => selectedGameIds.value.length)
const selectableGamesCount = computed(() => availableGames.value.filter(game => game.is_available).length)

const panelScroller = ref<HTMLElement | null>(null)
const gamesSection = ref<HTMLElement | null>(null)
const hasHiddenPanelContent = ref(false)
const highlightGames = ref(false)
let highlightTimer: ReturnType<typeof setTimeout> | null = null

const updatePanelScrollState = () => {
    const el = panelScroller.value
    if (!el) {
        hasHiddenPanelContent.value = false
        return
    }
    hasHiddenPanelContent.value = el.scrollHeight - el.clientHeight - el.scrollTop > 8
}

const pulseGames = () => {
    highlightGames.value = true
    if (highlightTimer) clearTimeout(highlightTimer)
    highlightTimer = setTimeout(() => { highlightGames.value = false }, 1400)
}

// Автопоказ двигает только внутреннюю панель: дёргать скролл всей страницы
// (мобильная раскладка) во время выбора мест нельзя.
const revealGamesInPanel = () => {
    pulseGames()
    const scroller = panelScroller.value
    const section = gamesSection.value
    if (!scroller || !section) return
    const bottomOverflow = section.offsetTop + section.offsetHeight - scroller.clientHeight
    if (bottomOverflow > scroller.scrollTop) {
        scroller.scrollTo({ top: bottomOverflow, behavior: 'smooth' })
    }
}

// Как только у клуба появляются доступные игры — подсвечиваем секцию, чтобы её не пролистали.
watch(selectableGamesCount, async (count, previous) => {
    if (count > 0 && !previous) {
        await nextTick()
        revealGamesInPanel()
    }
})

watch([availableGames, selectedIds, isGamesLoading, gamesError], async () => {
    await nextTick()
    updatePanelScrollState()
}, { deep: true })

onMounted(() => {
    updatePanelScrollState()
    window.addEventListener('resize', updatePanelScrollState)
})

watch([() => props.clubData.id, selectedDate, startH, duration], fetchOccupiedSeats, {
    deep: true,
    immediate: true
})

watch([() => props.clubData.id, selectedIds, selectedDate, startH, duration], fetchGamesAvailability, {
    deep: true,
    immediate: true
})

watch([() => props.clubData.id, selectedIds, selectedGameIds, selectedDate, startH, duration, isGamesLoading], fetchServerPrice, {
    deep: true,
    immediate: true
})


// --- ЛОГИКА ВОЗРАСТНОГО КОНТРОЛЯ ---
const checkAgeRestriction = () => {
    const nightStart = 22;
    const nightEnd = 6;
    const start = startH.value;
    const end = endH.value;

    const isNight = start >= nightStart || start < nightEnd || end > nightStart || end <= nightEnd || duration.value > 12;

    if (isNight) {
        showAgeWarning.value = true;
        showOverlay.value = true;
    } else {
        showConfirmModal.value = true;
        showOverlay.value = true;
    }
}

const handleAgeConfirm = () => {
    showAgeWarning.value = false;
    showConfirmModal.value = true;
}

// --- ДАННЫЕ ДЛЯ МОДАЛКИ ---
const getComputerData = (id: string | number) => {
    const pc = props.computersList.find(c => c.id.toString() === id.toString());
    if (!pc) return { zoneName: 'STANDARD', pcName: id };
    let zoneName = 'STANDARD';
    const kind = String(pc.kind || 'pc')
    if (kind === 'tv') zoneName = 'TV'
    if (kind === 'ps5') zoneName = 'TV+PS5'
    const pcX = Number(pc.x); const pcY = Number(pc.y);
    const rects = cleanMapConfig.value.zoneRects || [];
    for (const z of rects) {
        if (pcX >= z.x && pcX <= z.x + z.w && pcY >= z.y && pcY <= z.y + z.h) {
            if (z.type) zoneName = String(z.type).toUpperCase()
            else if (z.c === '#fbbf24') zoneName = 'VIP';
            else if (z.c === '#ef4444') zoneName = 'BOOTCAMP';
            else if (z.c === '#3b82f6') zoneName = 'PRO';
            else if (z.c === '#a855f7') zoneName = 'STREAM';
            if (kind === 'ps5') zoneName = `${zoneName}+PS5`
            else if (kind === 'tv') zoneName = zoneName === 'STANDARD' ? 'TV' : zoneName
            break;
        }
    }
    return { zoneName, pcName: pc.name };
}

const toggleSeatSelection = (id: string) => {
    const index = selectedIds.value.indexOf(id)
    if (index !== -1) {
        selectedIds.value.splice(index, 1)
        return
    }

    const pc = props.computersList.find(c => c.id.toString() === id)
    const boothId = pc?.booth_id ? String(pc.booth_id) : null
    if (boothId) {
        selectedIds.value = selectedIds.value.filter((selectedId) => {
            const other = props.computersList.find(c => c.id.toString() === selectedId)
            return !other?.booth_id || String(other.booth_id) !== boothId
        })
    }
    selectedIds.value.push(id)
}

const selectedPlacesText = computed(() =>
    selectedIds.value.length === 0 ? 'не выбрано' : selectedIds.value.map(id => {
        const data = getComputerData(id);
        return `${data.zoneName} №${data.pcName}`;
    }).join(', ')
)

const bookingDataForModal = computed(() => ({
    pcNumber: selectedPlacesText.value,
    date: new Date(selectedDate.value).toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' }).toUpperCase(),
    startTime: formatTimeLabel(startH.value),
    endTime: formatTimeLabel(endH.value),
    price: totalAmount.value.toFixed(0),
    computersPrice: (computersTotalMinor.value / 100).toFixed(0),
    gamesPrice: (gamesTotalMinor.value / 100).toFixed(0),
    breakdown: {
        computers_total_minor: computersTotalMinor.value,
        games_total_minor: gamesTotalMinor.value
    },
    games: availableGames.value
        .filter(game => selectedGameIds.value.includes(game.id))
        .map(game => ({ id: game.id, title: game.title }))
}))

// --- УПРАВЛЕНИЕ БРОНИРОВАНИЕМ ---
const handleConfirmBooking = async (payload: any) => {
    showConfirmModal.value = false
    if (props.isTerminal) {
        userPhone.value = payload.phone || payload || ''
        setTimeout(() => { showSmsModal.value = true }, 200)
    } else {
        isProcessing.value = true
        try {
            await axios.post('/api/booking/reserve', bookingPayload.value)
            showSuccessModal.value = true
        } catch (error: any) {
            const errors = error.response?.data?.errors
            const message = errors?.booking?.[0]
                || errors?.pc_ids?.[0]
                || errors?.game_ids?.[0]
                || errors?.balance?.[0]
                || errors?.starts_at?.[0]
                || error.response?.data?.message
                || 'Ошибка транзакции.'
            alert(message)
            closeAllModals()
        } finally { isProcessing.value = false }
    }
}

const closeAllModals = () => {
    showConfirmModal.value = false; showSmsModal.value = false; showSuccessModal.value = false;
    showInfoModal.value = false; showTariffsModal.value = false; showAgeWarning.value = false;
    showOverlay.value = false;
}

const handleFinalClose = () => {
    closeAllModals(); selectedIds.value = [];
    if (!props.isTerminal) router.visit('/account/dashboard');
}

const handleWheel = (e: any, type: 'start' | 'end') => {
    if (bookingMode.value === 'packages' && type === 'end') return
    const delta = (e.deltaY || e) > 0 ? 0.25 : -0.25
    if (type === 'start') {
        const next = mod24(startH.value + delta)
        if (selectedDate.value === new Date().toDateString() && next < getNextQuarter()) return
        startH.value = next
        if (bookingMode.value === 'packages' && selectedPackage.value) endH.value = mod24(next + selectedPackage.value.hours)
        else if (mod24(endH.value - next) < 1) endH.value = mod24(next + 1)
    } else {
        if (delta < 0 && (mod24(endH.value - startH.value) || 24) <= 1) return
        endH.value = mod24(endH.value + delta)
    }
}

const days = computed(() => {
    const today = new Date();
    return Array.from({ length: 14 }, (_, i) => {
        const d = new Date(); d.setDate(today.getDate() + i)
        return {
            full: d.toDateString(),
            dayNum: d.getDate(),
            dayName: d.toLocaleDateString('ru-RU', { weekday: 'short' }).toUpperCase()
        }
    })
})

const vFitText = {
    mounted(el: HTMLElement) { adjustFont(el) },
    updated(el: HTMLElement) { adjustFont(el) }
}
const adjustFont = (el: HTMLElement) => {
    el.style.fontSize = '24px'
    setTimeout(() => {
        let size = 24
        while ((el.scrollHeight > el.clientHeight || el.scrollWidth > el.clientWidth) && size > 10) {
            size--; el.style.fontSize = size + 'px'
        }
    }, 0)
}

onUnmounted(() => {
    closeAllModals()
    if (highlightTimer) clearTimeout(highlightTimer)
    window.removeEventListener('resize', updatePanelScrollState)
})
</script>

<template>
    <component :is="layout">
        <div class="booking-frame flex flex-col lg:flex-row bg-black rounded-[28px] lg:rounded-[40px] border border-[#22c55e]/30 p-2 mx-auto w-full lg:w-fit h-auto lg:h-[880px] relative shadow-[0_0_50px_rgba(34,197,94,0.1)] overflow-visible lg:overflow-hidden select-none">

            <section class="p-3 sm:p-6 lg:p-8 border-b lg:border-b-0 lg:border-r border-[#22c55e]/30 flex bg-[#080808] rounded-t-[26px] lg:rounded-t-none lg:rounded-l-[38px] w-full lg:w-auto lg:min-w-[960px] lg:h-full lg:min-h-0 relative">
                <div class="w-full h-[min(70vh,640px)] lg:h-full min-h-[320px]">
                    <ClubMap
                        :selectedIds="selectedIds"
                        :occupiedIds="occupiedIds"
                        :computers="props.computersList"
                        :zones="props.zonesList"
                        :zoneRects="props.zoneRectsList"
                        :mapConfig="cleanMapConfig"
                        :viewbox="cleanMapConfig.viewbox || props.clubData.viewbox"
                        @show-info="(id) => { selectedZoneForInfo = id; showOverlay = true; showInfoModal = true }"
                        @seat-error="handleSeatError"
                        @toggle-seat="toggleSeatSelection"
                    />
                </div>
            </section>

            <aside class="w-full lg:w-[460px] p-5 sm:p-6 lg:p-8 flex flex-col bg-[#050505] rounded-b-[26px] lg:rounded-b-none lg:rounded-r-[38px] lg:h-full lg:min-h-0">

                <div class="mb-4 flex justify-between items-end px-2 shrink-0">
                    <h3 class="text-[#22c55e] text-xl font-black uppercase italic tracking-widest leading-none">{{ props.clubData.name }}</h3>
                    <div class="font-mono text-[10px] flex items-center gap-1" :class="seatError ? 'text-red-500 animate-pulse' : 'text-[#22c55e]'">
                        ● {{ seatError ? 'ОТКАЗ: ЗАНЯТО' : 'СИСТЕМА АКТИВНА' }}
                    </div>
                </div>

                <div class="relative lg:flex-1 lg:min-h-0">
                    <div ref="panelScroller" @scroll="updatePanelScrollState"
                         class="panel-scroll lg:h-full lg:overflow-y-auto lg:pr-2">

                        <p class="step-label"><span class="step-num">01</span> Места</p>
                        <div class="mb-4 h-[92px] shrink-0 bg-white/[0.02] border border-white/5 px-4 py-3 rounded-2xl relative shadow-inner flex flex-col">
                            <div class="flex justify-between text-[9px] text-slate-500 font-black uppercase italic tracking-widest mb-2 shrink-0">
                                <span>Ваш выбор</span>
                                <button v-if="selectedIds.length" @click="selectedIds = []" class="text-red-500 cursor-pointer uppercase">сброс ✕</button>
                            </div>
                            <div class="flex-1 min-h-0 w-full overflow-hidden relative">
                                <div v-fit-text class="absolute inset-0 font-black italic font-mono leading-tight break-words flex items-start"
                                     :class="selectedIds.length ? 'text-white uppercase' : 'text-white/20'">
                                    {{ selectedPlacesText }}
                                </div>
                            </div>
                        </div>

                        <p class="step-label"><span class="step-num">02</span> Дата и время</p>
                        <div class="mb-2.5 shrink-0">
                            <div class="flex gap-2 overflow-x-auto pb-2 no-scrollbar flex-nowrap scroll-smooth">
                                <div v-for="d in days" :key="d.full" @click="selectedDate = d.full"
                                     :class="['min-w-[48px] h-[56px] flex flex-col items-center justify-center rounded-xl border transition-all cursor-pointer',
                                      selectedDate === d.full ? 'bg-[#22c55e] border-[#22c55e]' : 'bg-white/5 border-white/10']">
                                    <span :class="['text-[8px] font-black uppercase', selectedDate === d.full ? 'text-black/70' : 'text-slate-400']">{{ d.dayName }}</span>
                                    <span :class="['text-[16px] font-mono font-black', selectedDate === d.full ? 'text-black' : 'text-white']">{{ d.dayNum }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-2 p-1 bg-white/5 rounded-2xl border border-white/5 mb-2.5">
                            <button @click="bookingMode='hourly'" :class="['py-2.5 rounded-xl text-[10px] font-black uppercase', bookingMode==='hourly' ? 'bg-[#22c55e] text-black shadow-lg' : 'text-white/40']">ПОЧАСОВОЙ</button>
                            <button @click="bookingMode='packages'" :class="['py-2.5 rounded-xl text-[10px] font-black uppercase', bookingMode==='packages' ? 'bg-[#22c55e] text-black shadow-lg' : 'text-white/40']">ПАКЕТЫ</button>
                        </div>

                        <div class="bg-black border border-white/10 rounded-[32px] p-4 mb-4 relative overflow-hidden min-h-[150px] flex flex-col justify-center shrink-0">
                            <div class="flex justify-between items-center h-[60px] px-2 mb-2">
                                <div class="flex-1 h-full wheel-container touch-none" @wheel.prevent="handleWheel($event, 'start')">
                                    <div class="wheel-strip" :style="{ transform: `translateY(-${getIndexByTime(startH) * 60}px)` }">
                                        <div v-for="s in timeSteps" :key="'s'+s" class="time-cell">{{ formatTimeLabel(s) }}</div>
                                    </div>
                                </div>
                                <div class="text-[#22c55e] font-black text-xl px-2 opacity-50">/</div>
                                <div class="flex-1 h-full wheel-container touch-none" @wheel.prevent="handleWheel($event, 'end')">
                                    <div class="wheel-strip" :style="{ transform: `translateY(-${getIndexByTime(endH) * 60}px)` }">
                                        <div v-for="e in timeSteps" :key="'e'+e" class="time-cell">{{ formatTimeLabel(e) }}</div>
                                    </div>
                                </div>
                            </div>
                            <div class="flex justify-center items-baseline gap-2 font-black text-[#22c55e]">
                                <span class="text-white/40 uppercase tracking-widest text-[10px] mr-2 italic">Длительность:</span>
                                <span class="text-4xl font-mono leading-none">{{ Math.floor(duration) }}</span><span class="text-lg">ч</span>
                            </div>
                        </div>

                        <section ref="gamesSection"
                                 :class="['mb-4 shrink-0 rounded-2xl border overflow-hidden transition-all duration-500 scroll-mt-1',
                                          highlightGames ? 'border-[#22c55e] shadow-[0_0_0_3px_rgba(34,197,94,0.25)]'
                                          : selectableGamesCount ? 'border-[#22c55e]/40' : 'border-white/10',
                                          selectableGamesCount ? 'bg-[#22c55e]/[0.04]' : 'bg-white/[0.02]']">
                            <header class="lg:sticky lg:top-0 z-20 flex items-center justify-between gap-2 px-4 py-2.5 border-b border-white/10 bg-[#0b0f0c]/95 backdrop-blur-md">
                                <p class="step-label step-label-active"><span class="step-num">03</span> Платные игры</p>
                                <span :class="['px-2 py-1 rounded-lg text-[8px] font-black uppercase tracking-widest whitespace-nowrap',
                                               selectedGamesCount ? 'bg-[#22c55e] text-black' : 'bg-white/5 text-white/40']">
                                    {{ selectedGamesCount ? `${selectedGamesCount} выбрано` : 'не выбрано' }}
                                </span>
                            </header>

                            <div class="px-4">
                                <p v-if="!selectedIds.length" class="py-4 text-center text-[9px] text-white/25 uppercase tracking-widest leading-relaxed">
                                    Выберите места на карте,<br>чтобы добавить игры к брони
                                </p>
                                <p v-else-if="isGamesLoading" class="py-4 text-center text-[9px] text-white/30 uppercase tracking-widest animate-pulse">
                                    Проверяем доступность...
                                </p>
                                <p v-else-if="gamesError" class="py-4 text-center text-[9px] text-red-400 uppercase tracking-widest">
                                    {{ gamesError }}
                                </p>
                                <p v-else-if="!availableGames.length" class="py-4 text-center text-[9px] text-white/20 uppercase tracking-widest">
                                    Нет платных игр — бесплатные берутся в shell
                                </p>
                                <template v-else>
                                    <label v-for="game in availableGames" :key="game.id"
                                           :class="['flex items-start gap-3 py-2.5 border-t border-white/5 first:border-t-0 group',
                                                    game.is_available ? 'cursor-pointer' : 'cursor-not-allowed opacity-50']">
                                        <input v-model="selectedGameIds" type="checkbox" :value="game.id"
                                               :disabled="!game.is_available"
                                               class="mt-0.5 size-4 accent-[#22c55e] disabled:cursor-not-allowed"
                                               :class="game.is_available ? 'cursor-pointer' : ''">
                                        <div class="min-w-0 flex-1">
                                            <div :class="['text-[11px] font-black uppercase truncate transition-colors',
                                                          game.is_available ? 'text-white group-hover:text-[#22c55e]' : 'text-white/50 line-through']">
                                                {{ game.title }}
                                            </div>
                                            <div v-if="game.is_available" class="text-[8px] text-white/25 uppercase truncate">
                                                {{ [game.platform, gameBillingNote(game)].filter(Boolean).join(' · ') }}
                                            </div>
                                            <div v-else class="text-[8px] text-amber-500/80 uppercase leading-snug">
                                                {{ gameBlockReason(game) }}
                                            </div>
                                        </div>
                                        <span :class="['text-[9px] font-black whitespace-nowrap shrink-0 mt-0.5',
                                                       game.is_available ? 'text-[#22c55e]' : 'text-white/30']">
                                            {{ formatGamePrice(game) }}
                                        </span>
                                    </label>
                                </template>
                            </div>
                        </section>
                    </div>

                    <div v-if="hasHiddenPanelContent"
                         class="hidden lg:flex pointer-events-none absolute bottom-0 left-0 right-2 h-12 items-end justify-center bg-gradient-to-t from-[#050505] via-[#050505]/80 to-transparent">
                        <span class="text-[8px] font-black uppercase tracking-[0.3em] text-white/30 animate-pulse">↓ прокрутите</span>
                    </div>
                </div>

                <div class="shrink-0 pt-3 sticky bottom-0 z-30 -mx-5 sm:-mx-6 lg:mx-0 px-5 sm:px-6 lg:px-0 pb-1 lg:pb-0 bg-[#050505] lg:static border-t border-white/5 lg:border-t-0">
                    <p v-if="priceError" class="mb-2 text-[9px] text-red-400 uppercase tracking-widest leading-snug">
                        {{ priceError }}
                    </p>

                    <button @click="selectedIds.length && !isBookingLoading && checkAgeRestriction()"
                            :disabled="isProcessing || isBookingLoading || !selectedIds.length"
                            :class="['group w-full p-1 bg-[#22c55e] rounded-[2.5rem] transition-all active:scale-95', !selectedIds.length || isProcessing || isBookingLoading ? 'opacity-30 grayscale cursor-not-allowed' : 'cursor-pointer shadow-[0_10px_30px_rgba(34,197,94,0.2)]']">
                        <div class="bg-[#0a0a0a] rounded-[2.3rem] p-5 lg:p-6 flex justify-between items-center border border-white/10 group-hover:bg-transparent transition-all">
                            <span class="font-black uppercase text-sm text-white group-hover:text-black italic tracking-widest">
                                <template v-if="isBookingLoading">Расчет...</template>
                                <template v-else-if="isProcessing">Связь...</template>
                                <template v-else>{{ isTerminal ? 'ОПЛАТИТЬ И ИГРАТЬ' : 'Подтвердить' }}</template>
                            </span>
                            <div class="flex flex-col items-end text-[#22c55e] group-hover:text-black leading-none font-black italic">
                                <div class="text-4xl lg:text-5xl tracking-tighter leading-none">
                                    {{ isBookingLoading ? '...' : totalAmount.toFixed(0) }}
                                </div>
                                <span class="text-[8px] uppercase mt-1">РУБ</span>
                            </div>
                        </div>
                    </button>
                </div>
            </aside>

            <Teleport to="body">
                <div v-if="showOverlay" class="fixed inset-0 bg-black/95 backdrop-blur-xl z-[9999990]" @click="closeAllModals"></div>

                <AgeWarningModal
                    :isOpen="showAgeWarning"
                    @close="closeAllModals"
                    @confirm="handleAgeConfirm"
                />

                <ConfirmModal v-if="showConfirmModal" :isOpen="showConfirmModal" :mode="isTerminal ? 'auth' : 'booking'" :data="bookingDataForModal" @close="closeAllModals" @confirm="handleConfirmBooking" />
                <SmsModal v-if="showSmsModal" :is-open="showSmsModal" :phone="userPhone" :is-terminal="isTerminal" @close="showSmsModal = false" @verify="() => { showSmsModal = false; showSuccessModal = true }" />
                <PaymentModal v-if="showSuccessModal" :isOpen="showSuccessModal" mode="booking" :data="bookingDataForModal" @close="handleFinalClose" />
                <ZoneInfoModal v-if="showInfoModal" :isOpen="showInfoModal" :zoneId="selectedZoneForInfo" @close="closeAllModals" />
                <TariffsModal v-if="showTariffsModal" :isOpen="showTariffsModal" @close="closeAllModals" />
            </Teleport>
        </div>
    </component>
</template>

<style scoped>
.no-scrollbar::-webkit-scrollbar { display: none; }

.step-label {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    margin-bottom: 0.375rem;
    padding-left: 0.25rem;
    font-size: 9px;
    font-weight: 900;
    font-style: italic;
    letter-spacing: 0.2em;
    text-transform: uppercase;
    color: #64748b;
}
.step-label-active { margin-bottom: 0; padding-left: 0; color: #fff; }
.step-num {
    font-family: ui-monospace, monospace;
    font-style: normal;
    letter-spacing: 0;
    color: rgba(34, 197, 94, 0.6);
}

/* Видимый скроллбар: раньше панель использовала no-scrollbar,
   из-за чего скрытый ниже сгиба блок игр не имел никаких визуальных признаков. */
.panel-scroll { scrollbar-width: thin; scrollbar-color: rgba(34, 197, 94, 0.45) transparent; }
.panel-scroll::-webkit-scrollbar { width: 6px; }
.panel-scroll::-webkit-scrollbar-track { background: rgba(255, 255, 255, 0.04); border-radius: 999px; }
.panel-scroll::-webkit-scrollbar-thumb { background: rgba(34, 197, 94, 0.45); border-radius: 999px; }
.panel-scroll::-webkit-scrollbar-thumb:hover { background: rgba(34, 197, 94, 0.7); }
.wheel-container { overflow: hidden; height: 60px; position: relative; display: flex; justify-content: center; z-index: 20; }
.wheel-strip { display: flex; flex-direction: column; align-items: center; transition: transform 0.5s cubic-bezier(0.23, 1, 0.32, 1); will-change: transform; width: 100%; }
.time-cell { height: 60px; min-height: 60px; display: flex; align-items: center; justify-content: center; font-size: 2.8rem; font-weight: 900; color: #22c55e; font-family: ui-monospace, monospace; text-shadow: 0 0 10px rgba(34, 197, 94, 0.4); }

.animate-in { animation: zoom-in 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
@keyframes zoom-in { from { opacity: 0; transform: scale(0.95) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }
</style>
