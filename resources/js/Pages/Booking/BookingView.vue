<script setup lang="ts">
import { ref, computed, nextTick, onMounted, onUnmounted, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
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
import GamesBookingModal from '@/Components/GamesBookingModal.vue'

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
    tariffShowcase?: {
        rates?: Array<{ zone: string; slug?: string; price: string; color: string }>
        packages?: Array<{ id?: number; name: string; discount: string; hours?: number; cost?: number; category?: string }>
    } | null;
    isTerminal?: boolean;
    preselectSeatIds?: string[] | null;
    preselectStart?: string | null;
    preselectDuration?: number | null;
}>(), {
    isTerminal: false,
    tariffShowcase: null,
    preselectSeatIds: null,
    preselectStart: null,
    preselectDuration: null,
})

const page = usePage()
const isAuthenticated = computed(() => !!(page.props.auth?.user || page.props.user))

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
            zoneRects: Array.isArray(config.zoneRects)
                ? config.zoneRects.filter((z: any) => z && Number(z.w) >= 0.5 && Number(z.h) >= 0.5)
                : [],
            labels: Array.isArray(config.labels)
                ? config.labels.filter((l: any) => {
                    const t = String(l?.content ?? '').trim().toUpperCase()
                    if (!l || !t || t === 'ТЕКСТ' || t === 'TEXT') return false
                    // Старые заголовки секций / дубли типов — автоподпись зон их заменяет.
                    return !['STANDART', 'STANDARD', 'СТАНДАРТ', 'VIP', 'SOLO', 'SINGL', 'DUO', 'TRIO', 'KVATRO',
                        'BOOTCAMP', 'BOOTKAMP', 'BOTKAMP', 'BOTKAMP-PROFI', 'BOOTKAMP-PROFI', 'BOOTCAMP-PROFI',
                        'TV', 'PS5', 'PS', 'WC'].includes(t)
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
const selectedIds = ref<string[]>(
    (props.preselectSeatIds || []).filter(id => !staticOccupiedIds.value.includes(id))
)
/** Выбранные optional-допы (PS). Привязка к местам комнаты. */
const selectedAddonLinks = ref<Array<{ addonId: number; seatIds: string[] }>>([])
const addonLinkKey = (addonId: number, seatIds: string[]) =>
    `${addonId}:${[...seatIds].map(String).sort().join(',')}`
const selectedAddonIds = computed(() =>
    [...new Set(selectedAddonLinks.value.map(l => l.addonId))]
)
const selectedAddonKeys = computed(() =>
    selectedAddonLinks.value.map(l => addonLinkKey(l.addonId, l.seatIds))
)

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
const showGamesModal = ref(false)

const selectedRoomInfo = ref<{
    title?: string
    color?: string
    kind?: 'pc' | 'tv'
    info?: Record<string, string | null | undefined> | null
} | null>(null)
const userPhone = ref('')
const isProcessing = ref(false)

// --- ВРЕМЯ И ТАРИФЫ ---
const mod24 = (n: number) => ((n % 24) + 24) % 24

// Ближайший слот кратный 15 минутам. После 23:45 он попадает уже на следующие сутки.
const nextQuarterSlot = () => {
    const now = new Date()
    const m = now.getMinutes()
    const step = m < 15 ? 0.25 : m < 30 ? 0.5 : m < 45 ? 0.75 : 1
    const raw = now.getHours() + step
    return { time: mod24(raw), rollsOverMidnight: raw >= 24 }
}

const initialSlot = nextQuarterSlot()
const initialDate = new Date()
if (initialSlot.rollsOverMidnight) initialDate.setDate(initialDate.getDate() + 1)

const selectedDate = ref(initialDate.toDateString())
const bookingMode = ref<'hourly' | 'packages'>('hourly')
const selectedPackage = ref<{ id: number; title: string; hours: number; cost: number } | null>(null)
const zonePackages = ref<Array<{ id: number; title: string; hours: number; cost: number; finished_at?: string | null }>>([])
const zoneHourlyRate = ref<number | null>(null)
const zoneCategory = ref<string | null>(null)
const tariffGridError = ref('')
let tariffGridRequestId = 0

const timeSteps = Array.from({ length: 96 }, (_, i) => i * 0.25)
const TIME_CELL_PX = 48
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
// Возврат после входа по SMS: восстанавливаем время, если оно ещё не прошло.
const parseTimeParam = (value?: string | null) => {
    const match = /^([01]?\d|2[0-3]):([0-5]\d)$/.exec(value || '')
    if (!match) return null
    const restored = Number(match[1]) + Number(match[2]) / 60
    if (!initialSlot.rollsOverMidnight && restored < initialSlot.time) return null
    return restored
}

const startH = ref(parseTimeParam(props.preselectStart) ?? initialSlot.time)
const endH = ref(mod24(startH.value + (props.preselectDuration || 1)))

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
    addon_ids: [...selectedAddonIds.value],
    game_ids: [...selectedGameIds.value],
    mode: bookingMode.value,
    tariff_id: bookingMode.value === 'packages' ? selectedPackage.value?.id ?? null : null,
    ...bookingInterval.value
}))

const fetchTariffGrid = async () => {
    const requestId = ++tariffGridRequestId
    tariffGridError.value = ''

    if (selectedIds.value.length === 0) {
        zonePackages.value = []
        zoneHourlyRate.value = null
        zoneCategory.value = null
        selectedPackage.value = null
        return
    }

    try {
        const response = await axios.post('/api/booking/tariff-grid', {
            club_id: props.clubData.id,
            pc_ids: [...selectedIds.value],
            starts_at: bookingInterval.value.starts_at,
        })
        if (requestId !== tariffGridRequestId) return

        zoneCategory.value = response.data?.category ?? null
        zoneHourlyRate.value = Number(response.data?.hourly_rate ?? 0) || null
        const packages = Array.isArray(response.data?.packages) ? response.data.packages : []
        zonePackages.value = packages.map((pkg: any) => ({
            id: Number(pkg.id),
            title: String(pkg.title ?? pkg.name ?? `${pkg.hours}ч`),
            hours: Number(pkg.hours),
            cost: Number(pkg.cost),
            finished_at: pkg.finished_at ?? null,
        }))

        if (selectedPackage.value) {
            const still = zonePackages.value.find(p => p.id === selectedPackage.value!.id)
            selectedPackage.value = still ?? null
            if (!still && bookingMode.value === 'packages') {
                bookingMode.value = 'hourly'
            }
        }
    } catch (e: any) {
        if (requestId !== tariffGridRequestId) return
        console.error('Ошибка загрузки тарифной сетки', e)
        zonePackages.value = []
        tariffGridError.value = e?.response?.data?.errors?.pc_ids?.[0]
            || e?.response?.data?.message
            || 'Не удалось загрузить пакеты'
    }
}

const selectPackage = (pkg: { id: number; title: string; hours: number; cost: number }) => {
    selectedPackage.value = pkg
    bookingMode.value = 'packages'
    endH.value = mod24(startH.value + pkg.hours)
}

const setBookingMode = (mode: 'hourly' | 'packages') => {
    bookingMode.value = mode
    if (mode === 'hourly') {
        selectedPackage.value = null
        return
    }
    if (!selectedPackage.value && zonePackages.value.length) {
        selectPackage(zonePackages.value[0])
    }
}

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
        selectedGameIds.value = selectedGameIds.value.filter(id => availableIds.has(id)).slice(0, 1)
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
const addonsTotalMinor = ref(0)
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
            addonsTotalMinor.value = 0
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
        const addonsMinor = Number(response.data?.addons_total_minor ?? 0)
        addonsTotalMinor.value = addonsMinor
        // База мест без допов (если сервер отдал computers_base_minor).
        const baseMinor = response.data?.computers_base_minor != null
            ? Number(response.data.computers_base_minor)
            : Math.max(0, Number(response.data?.computers_total_minor ?? 0) - addonsMinor)
        computersTotalMinor.value = baseMinor
        gamesTotalMinor.value = Number(response.data?.games_total_minor ?? 0)
    } catch (e: any) {
        if (requestId !== priceRequestId) return
        console.error('Ошибка расчета стоимости', e)
        totalAmount.value = 0
        computersTotalMinor.value = 0
        addonsTotalMinor.value = 0
        gamesTotalMinor.value = 0
        if (e?.response?.status === 401) {
            priceError.value = 'Войдите по номеру телефона, чтобы продолжить'
            return
        }
        const errors = e?.response?.data?.errors
        priceError.value = errors?.pc_ids?.[0]
            || errors?.addon_ids?.[0]
            || errors?.tariff_id?.[0]
            || errors?.duration?.[0]
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
const gamesBanner = ref<HTMLElement | null>(null)
const hasHiddenPanelContent = ref(false)
const highlightGames = ref(false)
let highlightTimer: ReturnType<typeof setTimeout> | null = null

const mapPanViewport = ref<HTMLElement | null>(null)
const mapCanPanLeft = ref(false)
const mapCanPanRight = ref(false)

type MapPanAxis = null | 'x' | 'y'
let mapPanDrag: {
    pointerId: number
    lastX: number
    startX: number
    startY: number
    axis: MapPanAxis
} | null = null

const isNarrowBooking = () =>
    typeof window !== 'undefined' && window.matchMedia('(max-width: 1023px)').matches

const syncMapPanHints = () => {
    const el = mapPanViewport.value
    if (!el || !isNarrowBooking()) {
        mapCanPanLeft.value = false
        mapCanPanRight.value = false
        return
    }
    mapCanPanLeft.value = el.scrollLeft > 4
    mapCanPanRight.value = el.scrollLeft < el.scrollWidth - el.clientWidth - 4
}

const onMapPanScroll = () => syncMapPanHints()

const onMapPointerDown = (e: PointerEvent) => {
    if (!isNarrowBooking()) return
    if (e.pointerType === 'mouse' && e.button !== 0) return
    mapPanDrag = {
        pointerId: e.pointerId,
        lastX: e.clientX,
        startX: e.clientX,
        startY: e.clientY,
        axis: null,
    }
}

const onMapPointerMove = (e: PointerEvent) => {
    if (!mapPanDrag || mapPanDrag.pointerId !== e.pointerId) return
    const el = mapPanViewport.value
    if (!el) return

    const dx = e.clientX - mapPanDrag.startX
    const dy = e.clientY - mapPanDrag.startY

    if (!mapPanDrag.axis) {
        if (Math.abs(dx) < 10 && Math.abs(dy) < 10) return
        // Вертикаль — отдаём жест странице; горизонталь — двигаем карту.
        mapPanDrag.axis = Math.abs(dx) > Math.abs(dy) * 1.15 ? 'x' : 'y'
        if (mapPanDrag.axis === 'x') {
            el.setPointerCapture?.(e.pointerId)
        } else {
            mapPanDrag = null
            return
        }
    }

    if (mapPanDrag.axis !== 'x') return
    e.preventDefault()
    el.scrollLeft -= e.clientX - mapPanDrag.lastX
    mapPanDrag.lastX = e.clientX
    syncMapPanHints()
}

const onMapPointerUp = (e: PointerEvent) => {
    if (!mapPanDrag || mapPanDrag.pointerId !== e.pointerId) return
    mapPanDrag = null
}

const nudgeMap = (dir: -1 | 1) => {
    const el = mapPanViewport.value
    if (!el) return
    el.scrollBy({ left: dir * Math.max(120, el.clientWidth * 0.4), behavior: 'smooth' })
    window.setTimeout(syncMapPanHints, 280)
}

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
    highlightTimer = setTimeout(() => { highlightGames.value = false }, 1800)
}

const openGamesModal = () => {
    showGamesModal.value = true
    pulseGames()
}

const closeGamesModal = () => {
    showGamesModal.value = false
}

const confirmGamesSelection = () => {
    closeGamesModal()
}

// Подсветка баннера, когда появились доступные игры после выбора мест
watch(selectableGamesCount, async (count, previous) => {
    if (count > 0 && !previous) {
        await nextTick()
        pulseGames()
    }
})

watch([availableGames, selectedIds, isGamesLoading, gamesError], async () => {
    await nextTick()
    updatePanelScrollState()
}, { deep: true })

onMounted(async () => {
    updatePanelScrollState()
    window.addEventListener('resize', updatePanelScrollState)
    window.addEventListener('resize', syncMapPanHints)
    await nextTick()
    syncMapPanHints()
    requestAnimationFrame(syncMapPanHints)
})

watch([() => props.clubData.id, selectedDate, startH, duration], fetchOccupiedSeats, {
    deep: true,
    immediate: true
})

watch([() => props.clubData.id, selectedIds, selectedDate, startH, duration], fetchGamesAvailability, {
    deep: true,
    immediate: true
})

watch([() => props.clubData.id, selectedIds, selectedDate, startH], fetchTariffGrid, {
    immediate: true,
    deep: true,
})

watch([() => props.clubData.id, selectedIds, selectedAddonIds, selectedGameIds, selectedDate, startH, duration, bookingMode, selectedPackage, isGamesLoading], fetchServerPrice, {

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

/** Места, сгруппированные по зонам: на телефоне карта слишком мелкая для точного нажатия. */
const seatGroups = computed(() => {
    const groups = new Map<string, { name: string; seats: Array<{ id: string; name: string; occupied: boolean }> }>()

    for (const pc of props.computersList) {
        const id = pc.id.toString()
        const zoneName = getComputerData(id).zoneName
        if (!groups.has(zoneName)) groups.set(zoneName, { name: zoneName, seats: [] })
        groups.get(zoneName)!.seats.push({
            id,
            name: String(pc.name ?? id),
            occupied: occupiedIds.value.includes(id),
        })
    }

    return [...groups.values()].map(group => ({
        name: group.name,
        seats: [...group.seats].sort((a, b) => a.name.localeCompare(b.name, 'ru', { numeric: true })),
        freeCount: group.seats.filter(seat => !seat.occupied).length,
    }))
})

const clearAddonsForSeats = (seatIds: string[]) => {
    const drop = new Set(seatIds.map(String))
    selectedAddonLinks.value = selectedAddonLinks.value.filter(
        link => !link.seatIds.some(id => drop.has(String(id)))
    )
}

const toggleSeatSelection = (id: string) => {
    const index = selectedIds.value.indexOf(id)
    if (index === -1 && occupiedIds.value.includes(id)) {
        handleSeatError()
        return
    }
    if (index !== -1) {
        selectedIds.value.splice(index, 1)
        // Сняли комнату → доп этой комнаты тоже убираем
        clearAddonsForSeats([id])
        return
    }

    const pc = props.computersList.find(c => c.id.toString() === id)
    const boothId = pc?.booth_id ? String(pc.booth_id) : null
    if (boothId) {
        const removed: string[] = []
        selectedIds.value = selectedIds.value.filter((selectedId) => {
            const other = props.computersList.find(c => c.id.toString() === selectedId)
            const sameBooth = other?.booth_id && String(other.booth_id) === boothId
            if (sameBooth) removed.push(selectedId)
            return !sameBooth
        })
        if (removed.length) clearAddonsForSeats(removed)
    }
    selectedIds.value.push(id)
    // Клик по месту / TV — только комната, без допа
}

/** Клик по PS → комната + доп. Повторный клик снимает только доп (комната остаётся). */
const toggleAddonSeats = (payload: { addonId: number; seatIds: string[] }) => {
    const freeIds = (payload.seatIds || []).filter(id => !occupiedIds.value.includes(id))
    if (!freeIds.length) {
        handleSeatError()
        return
    }

    const preferred = freeIds.filter((id) => {
        const pc = props.computersList.find(c => c.id.toString() === id)
        const kind = String(pc?.kind || 'pc')
        return kind === 'tv' || kind === 'ps5'
    })
    const targetIds = preferred.length ? preferred : freeIds
    const key = addonLinkKey(payload.addonId, targetIds)
    const existingIdx = selectedAddonLinks.value.findIndex(
        l => addonLinkKey(l.addonId, l.seatIds) === key
    )

    if (existingIdx >= 0) {
        selectedAddonLinks.value.splice(existingIdx, 1)
        return
    }

    for (const id of targetIds) {
        const pc = props.computersList.find(c => c.id.toString() === id)
        const boothId = pc?.booth_id ? String(pc.booth_id) : null
        if (boothId) {
            const removed: string[] = []
            selectedIds.value = selectedIds.value.filter((selectedId) => {
                const other = props.computersList.find(c => c.id.toString() === selectedId)
                const sameBooth = other?.booth_id && String(other.booth_id) === boothId
                if (sameBooth && !targetIds.includes(selectedId)) removed.push(selectedId)
                return !sameBooth || targetIds.includes(selectedId)
            })
            if (removed.length) clearAddonsForSeats(removed)
        }
    }
    for (const id of targetIds) {
        if (!selectedIds.value.includes(id)) selectedIds.value.push(id)
    }

    // Убираем прошлый доп на тех же местах (один optional на комнату).
    clearAddonsForSeats(targetIds)
    selectedAddonLinks.value.push({ addonId: payload.addonId, seatIds: [...targetIds] })
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

// --- ВХОД ГОСТЯ ПЕРЕД БРОНИРОВАНИЕМ ---
// Цену и занятость гость видит без входа, но саму бронь оформляем только после SMS.
const showGuestPhoneModal = ref(false)
const isGuestAuthFlow = ref(false)
const authError = ref('')

const buildReturnUrl = () => {
    const params = new URLSearchParams()
    if (selectedIds.value.length) params.set('seat', selectedIds.value.join(','))
    params.set('start', formatTimeLabel(startH.value))
    params.set('dur', String(duration.value))
    return `${window.location.pathname}?${params.toString()}`
}

const startBooking = () => {
    if (!selectedIds.value.length || isBookingLoading.value || isProcessing.value) return

    if (!props.isTerminal && !isAuthenticated.value) {
        authError.value = ''
        isGuestAuthFlow.value = true
        showGuestPhoneModal.value = true
        showOverlay.value = true
        return
    }

    checkAgeRestriction()
}

const handleGuestPhone = async (payload: any) => {
    const phone = payload?.phone || payload || ''
    userPhone.value = phone
    authError.value = ''

    try {
        await axios.post('/auth/send-code', { phone })
        showGuestPhoneModal.value = false
        showSmsModal.value = true
    } catch (e: any) {
        authError.value = e?.response?.data?.message || 'Не удалось отправить код'
    }
}

const handleSmsVerify = (code: string) => {
    if (!isGuestAuthFlow.value) {
        // Терминал: подтверждение по SMS без входа в аккаунт.
        showSmsModal.value = false
        showSuccessModal.value = true
        return
    }

    router.post('/auth/verify-code', {
        phone: userPhone.value,
        code,
        redirect_to: buildReturnUrl(),
    })
}

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
    showGamesModal.value = false; showGuestPhoneModal.value = false;
    isGuestAuthFlow.value = false;
    showOverlay.value = false;
}

const handleFinalClose = () => {
    closeAllModals(); selectedIds.value = [];
    if (!props.isTerminal) router.visit('/account/dashboard');
}

/** Шаг 15 минут. Используется колесом мыши, свайпом и кнопками ±. */
const stepTime = (type: 'start' | 'end', direction: 1 | -1) => {
    if (bookingMode.value === 'packages' && type === 'end') return
    const delta = 0.25 * direction

    if (type === 'start') {
        const next = mod24(startH.value + delta)
        if (selectedDate.value === new Date().toDateString()) {
            const slot = nextQuarterSlot()
            if (slot.rollsOverMidnight || next < slot.time) return
        }
        startH.value = next
        if (bookingMode.value === 'packages' && selectedPackage.value) endH.value = mod24(next + selectedPackage.value.hours)
        else if (mod24(endH.value - next) < 1) endH.value = mod24(next + 1)
        return
    }

    if (direction < 0 && (mod24(endH.value - startH.value) || 24) <= 1) return
    endH.value = mod24(endH.value + delta)
}

const handleWheel = (e: WheelEvent, type: 'start' | 'end') => {
    stepTime(type, (e.deltaY ?? 0) > 0 ? 1 : -1)
}

const startWheelShift = ref(0)
const endWheelShift = ref(0)
const draggingWheel = ref<'start' | 'end' | null>(null)

const wheelShiftOf = (type: 'start' | 'end') => (type === 'start' ? startWheelShift : endWheelShift)

const wheelTranslate = (time: number, shift: number) =>
    TIME_CELL_PX - (getIndexByTime(time) + shift) * TIME_CELL_PX

const activeWheelIndex = (time: number, shift: number) => {
    const idx = getIndexByTime(time) + Math.round(shift)
    return Math.max(0, Math.min(timeSteps.length - 1, idx))
}

let dragState: { type: 'start' | 'end'; lastY: number } | null = null

const startTimeDrag = (e: PointerEvent, type: 'start' | 'end') => {
    if (bookingMode.value === 'packages' && type === 'end') return
    dragState = { type, lastY: e.clientY }
    draggingWheel.value = type
    wheelShiftOf(type).value = 0
    ;(e.currentTarget as HTMLElement)?.setPointerCapture?.(e.pointerId)
}

const moveTimeDrag = (e: PointerEvent) => {
    if (!dragState) return
    const type = dragState.type
    const shiftRef = wheelShiftOf(type)
    const idx = getIndexByTime(type === 'start' ? startH.value : endH.value)
    shiftRef.value += (dragState.lastY - e.clientY) / TIME_CELL_PX
    dragState.lastY = e.clientY
    const minShift = -idx
    const maxShift = timeSteps.length - 1 - idx
    if (shiftRef.value < minShift) shiftRef.value = minShift
    if (shiftRef.value > maxShift) shiftRef.value = maxShift
}

const endTimeDrag = (e: PointerEvent) => {
    if (!dragState) return
    const type = dragState.type
    const steps = Math.round(wheelShiftOf(type).value)
    wheelShiftOf(type).value = 0
    draggingWheel.value = null
    ;(e.currentTarget as HTMLElement)?.releasePointerCapture?.(e.pointerId)
    dragState = null
    const dir: 1 | -1 = steps > 0 ? 1 : -1
    for (let i = 0; i < Math.abs(steps); i++) stepTime(type, dir)
}

const formatDuration = (hours: number) => {
    const totalMinutes = Math.round(hours * 60)
    const h = Math.floor(totalMinutes / 60)
    const m = totalMinutes % 60
    if (!m) return `${h} ч`
    return h ? `${h} ч ${m} мин` : `${m} мин`
}

const days = computed(() => {
    const today = new Date();
    const todayExhausted = nextQuarterSlot().rollsOverMidnight
    return Array.from({ length: 14 }, (_, i) => {
        const d = new Date(); d.setDate(today.getDate() + i)
        return {
            full: d.toDateString(),
            dayNum: d.getDate(),
            dayName: d.toLocaleDateString('ru-RU', { weekday: 'short' }).toUpperCase(),
            disabled: i === 0 && todayExhausted
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
    window.removeEventListener('resize', syncMapPanHints)
})
</script>

<template>
    <component :is="layout">
        <div class="booking-frame flex flex-col lg:flex-row bg-white/5 lg:bg-black rounded-[10px] sm:rounded-[12px] lg:rounded-[16px] border border-white/10 lg:border-[#22c55e]/30 p-0 sm:p-2 mx-auto w-full lg:w-fit h-auto lg:h-[880px] relative shadow-[0_0_50px_rgba(34,197,94,0.1)] overflow-visible lg:overflow-hidden select-none">

            <section class="px-2 py-3 sm:p-5 lg:p-6 border-b lg:border-b-0 lg:border-r border-white/10 lg:border-[#22c55e]/30 flex flex-col gap-4 sm:gap-3 bg-transparent lg:bg-[#080808] rounded-t-[10px] sm:rounded-t-[12px] lg:rounded-t-none lg:rounded-l-[16px] w-full lg:w-auto lg:min-w-[960px] lg:h-full lg:min-h-0 relative">
                <div ref="gamesBanner"
                     :class="['shrink-0 flex flex-col sm:flex-row sm:items-center gap-3 sm:gap-4 rounded-2xl border px-3 sm:px-4 py-3 transition-all duration-500',
                              highlightGames ? 'border-[#22c55e] shadow-[0_0_0_3px_rgba(34,197,94,0.22)] bg-[#22c55e]/[0.08]'
                              : 'border-[#22c55e]/25 bg-[#22c55e]/[0.04]']">
                    <div class="min-w-0 flex-1">
                        <p class="text-[10px] sm:text-[11px] font-black uppercase tracking-wide text-[#22c55e] leading-snug">
                            <span class="text-red-500">Внимание!</span> Платную игру лучше забронировать заранее
                        </p>
                        <p class="mt-1 text-[9px] sm:text-[10px] text-white/45 leading-relaxed">
                            Так аккаунт будет закреплён за вашей сессией — без риска что его займут раньше.
                        </p>
                        <p v-if="selectedGamesCount" class="mt-1.5 text-[9px] text-[#22c55e]/90 font-black uppercase tracking-widest">
                            В брони: {{ selectedGamesCount }}
                            <button type="button" class="ml-2 underline underline-offset-2 text-white/40 hover:text-white" @click="selectedGameIds = []">сбросить</button>
                        </p>
                    </div>
                    <button type="button"
                            @click="openGamesModal"
                            class="shrink-0 inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl bg-[#22c55e] text-black text-[10px] font-black uppercase tracking-widest hover:bg-[#1ea34d] transition-colors cursor-pointer shadow-[0_0_20px_rgba(34,197,94,0.2)]">
                        {{ selectedGamesCount ? 'Изменить' : 'Выбрать' }}
                        <span v-if="selectedGamesCount" class="px-1.5 py-0.5 rounded-md bg-black/15 text-[9px]">{{ selectedGamesCount }}</span>
                    </button>
                </div>

                <div class="map-pan relative w-full shrink-0 h-[min(64vh,560px)] min-h-[380px] max-h-[600px] lg:h-auto lg:min-h-0 lg:max-h-none lg:flex-1">
                    <div
                        ref="mapPanViewport"
                        class="map-pan-viewport h-full w-full overflow-x-auto overflow-y-hidden overscroll-x-contain lg:overflow-visible"
                        style="touch-action: pan-y; -webkit-overflow-scrolling: touch;"
                        @scroll="onMapPanScroll"
                        @pointerdown="onMapPointerDown"
                        @pointermove="onMapPointerMove"
                        @pointerup="onMapPointerUp"
                        @pointercancel="onMapPointerUp"
                    >
                        <div class="map-pan-canvas h-full w-[200%] min-w-[200%] lg:w-full lg:min-w-0">
                            <ClubMap
                                :selectedIds="selectedIds"
                                :selectedAddonKeys="selectedAddonKeys"
                                :occupiedIds="occupiedIds"
                                :computers="props.computersList"
                                :zones="props.zonesList"
                                :zoneRects="props.zoneRectsList"
                                :mapConfig="cleanMapConfig"
                                :viewbox="cleanMapConfig.viewbox || props.clubData.viewbox"
                                @show-info="(room) => { selectedRoomInfo = room; showOverlay = true; showInfoModal = true }"
                                @seat-error="handleSeatError"
                                @toggle-seat="toggleSeatSelection"
                                @toggle-addon-seats="toggleAddonSeats"
                            />
                        </div>
                    </div>
                    <button
                        v-if="mapCanPanLeft"
                        type="button"
                        class="lg:hidden absolute left-1 top-1/2 -translate-y-1/2 z-10 w-9 h-9 rounded-full border border-white/15 bg-black/75 text-white/80 text-lg leading-none flex items-center justify-center"
                        aria-label="Сдвинуть карту влево"
                        @click="nudgeMap(-1)"
                    >‹</button>
                    <button
                        v-if="mapCanPanRight"
                        type="button"
                        class="lg:hidden absolute right-1 top-1/2 -translate-y-1/2 z-10 w-9 h-9 rounded-full border border-white/15 bg-black/75 text-white/80 text-lg leading-none flex items-center justify-center"
                        aria-label="Сдвинуть карту вправо"
                        @click="nudgeMap(1)"
                    >›</button>
                    <div
                        class="pointer-events-none absolute inset-y-2 right-0 w-10 bg-gradient-to-l from-[#080808] via-[#080808]/55 to-transparent lg:hidden"
                        aria-hidden="true"
                    />
                </div>
            </section>

            <aside class="w-full lg:w-[460px] px-2 py-4 sm:p-6 lg:p-8 flex flex-col bg-transparent lg:bg-[#050505] rounded-b-[10px] sm:rounded-b-[12px] lg:rounded-b-none lg:rounded-r-[16px] lg:h-full lg:min-h-0">

                <div class="mb-5 flex justify-between items-end shrink-0">
                    <h3 class="text-[#22c55e] text-xl font-black uppercase italic tracking-widest leading-none">{{ props.clubData.name }}</h3>
                    <div class="font-mono text-[10px] flex items-center gap-1" :class="seatError ? 'text-red-500 animate-pulse' : 'text-[#22c55e]'">
                        ● {{ seatError ? 'ОТКАЗ: ЗАНЯТО' : 'СИСТЕМА АКТИВНА' }}
                    </div>
                </div>

                <div class="relative lg:min-h-0">
                    <div ref="panelScroller" class="lg:overflow-visible">

                        <p class="step-label"><span class="step-num">01</span> Места</p>
                        <div class="mb-4 h-[72px] shrink-0 bg-white/[0.02] border border-white/5 px-4 py-2.5 rounded-2xl relative shadow-inner flex flex-col">
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
                                <div v-for="d in days" :key="d.full" @click="!d.disabled && (selectedDate = d.full)"
                                     :class="['min-w-[48px] h-[56px] flex flex-col items-center justify-center rounded-xl border transition-all',
                                      d.disabled ? 'opacity-30 cursor-not-allowed' : 'cursor-pointer',
                                      selectedDate === d.full ? 'bg-[#22c55e] border-[#22c55e]' : 'bg-white/5 border-white/10']">
                                    <span :class="['text-[8px] font-black uppercase', selectedDate === d.full ? 'text-black/70' : 'text-slate-400']">{{ d.dayName }}</span>
                                    <span :class="['text-[16px] font-mono font-black', selectedDate === d.full ? 'text-black' : 'text-white']">{{ d.dayNum }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-2 p-1 bg-white/5 rounded-2xl border border-white/5 mb-2.5">
                            <button @click="setBookingMode('hourly')" :class="['py-2.5 rounded-xl text-[10px] font-black uppercase', bookingMode==='hourly' ? 'bg-[#22c55e] text-black shadow-lg' : 'text-white/40']">ПОЧАСОВОЙ</button>
                            <button @click="setBookingMode('packages')" :class="['py-2.5 rounded-xl text-[10px] font-black uppercase', bookingMode==='packages' ? 'bg-[#22c55e] text-black shadow-lg' : 'text-white/40']">ПАКЕТЫ</button>
                        </div>

                        <div v-if="bookingMode === 'packages'" class="mb-2.5 space-y-2">
                            <p v-if="tariffGridError" class="text-[9px] text-red-400 uppercase tracking-widest">{{ tariffGridError }}</p>
                            <p v-else-if="!selectedIds.length" class="text-[9px] text-white/30 uppercase tracking-widest">Сначала выберите места</p>
                            <p v-else-if="!zonePackages.length" class="text-[9px] text-white/30 uppercase tracking-widest">
                                Для зоны {{ zoneCategory || '—' }} нет пакетов
                            </p>
                            <button
                                v-for="pkg in zonePackages"
                                :key="pkg.id"
                                type="button"
                                @click="selectPackage(pkg)"
                                :class="[
                                    'w-full flex items-center justify-between rounded-2xl border px-4 py-3 transition-all text-left',
                                    selectedPackage?.id === pkg.id
                                        ? 'border-[#22c55e] bg-[#22c55e]/10'
                                        : 'border-white/10 bg-black hover:border-white/25'
                                ]"
                            >
                                <div>
                                    <div class="text-white font-black text-xs uppercase tracking-widest">{{ pkg.title }}</div>
                                    <div class="text-white/40 text-[9px] font-bold uppercase tracking-widest mt-1">{{ pkg.hours }} ч · зона {{ zoneCategory }}</div>
                                </div>
                                <div class="text-[#22c55e] font-black italic text-lg leading-none">{{ Math.round(pkg.cost) }}₽</div>
                            </button>
                            <p v-if="zoneHourlyRate" class="text-[8px] text-white/25 uppercase tracking-widest px-1">
                                Докат сверх пакета: {{ Math.round(zoneHourlyRate) }} ₽/ч
                            </p>
                        </div>

                        <div class="bg-black border border-white/10 rounded-[14px] p-2 sm:p-3 mb-3 shrink-0">
                            <div class="grid grid-cols-2 gap-2 sm:gap-4">
                                <div class="flex flex-col items-center">
                                    <span class="time-label">Начало</span>
                                    <button type="button" class="wheel-chevron" @click="stepTime('start', -1)" aria-label="Начало раньше">⌃</button>
                                    <div class="w-full wheel-container touch-none"
                                         :class="{ 'is-dragging': draggingWheel === 'start' }"
                                         @wheel.prevent="handleWheel($event, 'start')"
                                         @pointerdown="startTimeDrag($event, 'start')"
                                         @pointermove="moveTimeDrag"
                                         @pointerup="endTimeDrag"
                                         @pointercancel="endTimeDrag">
                                        <div class="wheel-window" aria-hidden="true"></div>
                                        <div class="wheel-strip" :style="{ transform: `translateY(${wheelTranslate(startH, startWheelShift)}px)` }">
                                            <div v-for="s in timeSteps" :key="'s'+s"
                                                 class="time-cell"
                                                 :class="{ 'is-active': getIndexByTime(s) === activeWheelIndex(startH, startWheelShift) }">
                                                {{ formatTimeLabel(s) }}
                                            </div>
                                        </div>
                                    </div>
                                    <button type="button" class="wheel-chevron" @click="stepTime('start', 1)" aria-label="Начало позже">⌄</button>
                                    <div class="flex gap-1.5 w-full mt-1">
                                        <button type="button" class="time-step" @click="stepTime('start', -1)" aria-label="Начало на 15 минут раньше">−15</button>
                                        <button type="button" class="time-step" @click="stepTime('start', 1)" aria-label="Начало на 15 минут позже">+15</button>
                                    </div>
                                </div>

                                <div class="flex flex-col items-center">
                                    <span class="time-label">Конец</span>
                                    <button type="button" class="wheel-chevron" :disabled="bookingMode === 'packages'"
                                            @click="stepTime('end', -1)" aria-label="Конец раньше">⌃</button>
                                    <div class="w-full wheel-container touch-none"
                                         :class="{ 'is-locked': bookingMode === 'packages', 'is-dragging': draggingWheel === 'end' }"
                                         @wheel.prevent="handleWheel($event, 'end')"
                                         @pointerdown="startTimeDrag($event, 'end')"
                                         @pointermove="moveTimeDrag"
                                         @pointerup="endTimeDrag"
                                         @pointercancel="endTimeDrag">
                                        <div class="wheel-window" aria-hidden="true"></div>
                                        <div class="wheel-strip" :style="{ transform: `translateY(${wheelTranslate(endH, endWheelShift)}px)` }">
                                            <div v-for="e in timeSteps" :key="'e'+e"
                                                 class="time-cell"
                                                 :class="{ 'is-active': getIndexByTime(e) === activeWheelIndex(endH, endWheelShift) }">
                                                {{ formatTimeLabel(e) }}
                                            </div>
                                        </div>
                                    </div>
                                    <button type="button" class="wheel-chevron" :disabled="bookingMode === 'packages'"
                                            @click="stepTime('end', 1)" aria-label="Конец позже">⌄</button>
                                    <div class="flex gap-1.5 w-full mt-1">
                                        <button type="button" class="time-step" :disabled="bookingMode === 'packages'"
                                                @click="stepTime('end', -1)" aria-label="Конец на 15 минут раньше">−15</button>
                                        <button type="button" class="time-step" :disabled="bookingMode === 'packages'"
                                                @click="stepTime('end', 1)" aria-label="Конец на 15 минут позже">+15</button>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-3 pt-3 border-t border-white/5 flex justify-center items-baseline gap-2">
                                <span class="text-white/40 uppercase tracking-widest text-[10px] italic">Длительность:</span>
                                <span class="text-[#22c55e] font-black text-xl font-mono leading-none">{{ formatDuration(duration) }}</span>
                            </div>
                        </div>

                    </div>
                </div>

                <div class="shrink-0 mt-3 pt-0 sticky bottom-0 z-30 -mx-2 sm:-mx-6 lg:mx-0 px-2 sm:px-6 lg:px-0 pb-1 lg:pb-0 bg-white/5 lg:bg-[#050505] lg:static">
                    <p v-if="priceError" class="mb-2 text-[9px] text-red-400 uppercase tracking-widest leading-snug">
                        {{ priceError }}
                    </p>

                    <button @click="selectedIds.length && !isBookingLoading && checkAgeRestriction()"
                            :disabled="isProcessing || isBookingLoading || !selectedIds.length"
                            :class="['group w-full p-1 bg-[#22c55e] rounded-[1rem] transition-all active:scale-95', !selectedIds.length || isProcessing || isBookingLoading ? 'opacity-30 grayscale cursor-not-allowed' : 'cursor-pointer shadow-[0_10px_30px_rgba(34,197,94,0.2)]']">
                        <div class="bg-[#0a0a0a] rounded-[0.9375rem] p-4 lg:p-5 flex justify-between items-center border border-white/10 group-hover:bg-transparent transition-all">
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
                <ZoneInfoModal v-if="showInfoModal" :isOpen="showInfoModal" :room="selectedRoomInfo" @close="closeAllModals" />
                <TariffsModal v-if="showTariffsModal" :isOpen="showTariffsModal" :showcase="tariffShowcase" @close="closeAllModals" />
                <GamesBookingModal
                    :isOpen="showGamesModal"
                    :games="availableGames"
                    :selectedIds="selectedGameIds"
                    :hasSeats="selectedIds.length > 0"
                    :isLoading="isGamesLoading"
                    :error="gamesError"
                    :formatPrice="formatGamePrice"
                    :billingNote="gameBillingNote"
                    :blockReason="gameBlockReason"
                    @close="closeGamesModal"
                    @update:selectedIds="selectedGameIds = $event"
                    @confirm="confirmGamesSelection"
                />
            </Teleport>
        </div>
    </component>
</template>

<style scoped>
.no-scrollbar::-webkit-scrollbar { display: none; }

.map-pan-viewport {
    scrollbar-width: none;
}
.map-pan-viewport::-webkit-scrollbar { display: none; }

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
.wheel-container {
    overflow: hidden;
    height: 144px;
    position: relative;
    display: flex;
    justify-content: center;
    z-index: 20;
    cursor: ns-resize;
    user-select: none;
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.03);
    -webkit-mask-image: linear-gradient(to bottom, transparent 0%, #000 18%, #000 82%, transparent 100%);
    mask-image: linear-gradient(to bottom, transparent 0%, #000 18%, #000 82%, transparent 100%);
}
.wheel-container.is-locked { cursor: not-allowed; opacity: 0.4; }
.wheel-window {
    position: absolute;
    left: 10%;
    right: 10%;
    top: 50%;
    height: 48px;
    transform: translateY(-50%);
    border-top: 1px solid rgba(34, 197, 94, 0.45);
    border-bottom: 1px solid rgba(34, 197, 94, 0.45);
    border-radius: 8px;
    background: rgba(34, 197, 94, 0.07);
    box-shadow: inset 0 0 12px rgba(34, 197, 94, 0.08);
    pointer-events: none;
    z-index: 2;
}
.wheel-strip {
    display: flex;
    flex-direction: column;
    align-items: center;
    transition: transform 0.28s cubic-bezier(0.23, 1, 0.32, 1);
    will-change: transform;
    width: 100%;
    position: relative;
    z-index: 1;
}
.wheel-container.is-dragging .wheel-strip { transition: none; }
.time-cell {
    height: 48px;
    min-height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.05rem;
    font-weight: 600;
    color: rgba(34, 197, 94, 0.28);
    font-family: ui-sans-serif, system-ui, "Segoe UI", Arial, sans-serif;
    font-variant-numeric: tabular-nums;
    transform: scale(0.78);
    transition: color 0.15s, transform 0.15s, font-size 0.15s, font-weight 0.15s;
}
.time-cell.is-active {
    font-size: clamp(1.4rem, 5.5vw, 1.95rem);
    font-weight: 700;
    color: #22c55e;
    text-shadow: 0 0 10px rgba(34, 197, 94, 0.35);
    transform: scale(1);
}
.wheel-chevron {
    width: 100%;
    height: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: rgba(34, 197, 94, 0.55);
    font-size: 16px;
    line-height: 1;
    background: transparent;
    border: 0;
    cursor: pointer;
    opacity: 0.7;
}
.wheel-chevron:hover:not(:disabled) { color: #22c55e; opacity: 1; }
.wheel-chevron:disabled { opacity: 0.2; cursor: not-allowed; }

.time-label { font-size: 9px; font-weight: 900; font-style: italic; letter-spacing: 0.2em; text-transform: uppercase; color: rgba(255, 255, 255, 0.35); }
.time-step {
    flex: 1;
    min-height: 34px;
    border-radius: 8px;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.1);
    color: #22c55e;
    font-family: ui-monospace, monospace;
    font-size: 11px;
    font-weight: 900;
    transition: background-color 0.15s, color 0.15s;
}
.time-step:hover:not(:disabled) { background: rgba(34, 197, 94, 0.15); }
.time-step:active:not(:disabled) { background: #22c55e; color: #000; }
.time-step:disabled { opacity: 0.25; cursor: not-allowed; }

.seat-chip {
    min-width: 58px;
    min-height: 44px;
    padding: 0 0.5rem;
    border-radius: 8px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    background: rgba(255, 255, 255, 0.04);
    color: rgba(255, 255, 255, 0.75);
    font-family: ui-monospace, monospace;
    font-size: 12px;
    font-weight: 900;
    transition: all 0.15s;
}
.seat-chip.is-selected { background: #22c55e; border-color: #22c55e; color: #000; }
.seat-chip.is-occupied { opacity: 0.3; text-decoration: line-through; cursor: not-allowed; }

.animate-in { animation: zoom-in 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
@keyframes zoom-in { from { opacity: 0; transform: scale(0.95) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }
</style>
