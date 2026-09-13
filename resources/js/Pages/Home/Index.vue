<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'

import MainLayout from '@/Layouts/MainLayout.vue'
import ClubMap, { type RoomInfoShowPayload } from '@/Components/ClubMap.vue'
import ZoneInfoModal from '@/Components/ZoneInfoModal.vue'
import SiteFooter from '@/Components/SiteFooter.vue'
import { useClubName } from '@/Composables/useClubName'

const clubName = useClubName()

type SeatKinds = Record<string, { total: number; free: number }>

type LandingZone = {
    slug: string
    name: string
    color: string
    seats_total: number
    seats_free: number
    kinds: SeatKinds
    free_seat_id: number | null
    price_per_hour: number | null
    packages: Array<{
        id: number
        title: string
        hours: number
        cost: number
        hourly_equivalent: number
        discount_pct: number
    }>
}

type LandingGame = {
    id: number
    title: string
    platform: string
    poster: string | null
    is_paid: boolean
    price_rub: number
    unit_minutes: number
}

const props = withDefaults(defineProps<{
    club: { name: string; slug: string; address: string } | null
    occupancy: { total: number; free: number; busy: number; kinds: SeatKinds } | null
    zones: LandingZone[]
    games: LandingGame[]
    map: {
        config: any
        viewbox: string
        computers: any[]
        occupied_ids: string[]
    } | null
    contacts: any
    minAge?: number
    reviews?: Array<{
        id: number
        author: string
        text: string
        rating: number
        source: string
        url?: string | null
    }>
    reviews_map_url?: string | null
}>(), {
    zones: () => [],
    games: () => [],
    reviews: () => [],
    minAge: 0,
    reviews_map_url: null,
})

const KIND_LABELS: Record<string, string> = {
    pc: 'ПК',
    tv: 'ТВ-зона',
    ps5: 'PlayStation 5',
}

const bookingUrl = (seatId?: number | string | null) => {
    const base = props.club?.slug ? `/booking/${props.club.slug}` : '/booking'
    return seatId ? `${base}?seat=${seatId}` : base
}

const freePercent = computed(() => {
    const total = props.occupancy?.total || 0
    if (!total) return 0
    return Math.round(((props.occupancy?.free || 0) / total) * 100)
})

const hasSeats = computed(() => (props.occupancy?.total || 0) > 0)

const kindSummary = computed(() =>
    Object.entries(props.occupancy?.kinds || {}).map(([kind, stat]) => ({
        kind,
        label: KIND_LABELS[kind] || kind.toUpperCase(),
        ...stat,
    }))
)

const telegramUrl = computed(() => String(props.contacts?.socials?.telegram || ''))
const phoneHref = computed(() => {
    const phone = String(props.contacts?.phone || '')
    return phone ? `tel:${phone.replace(/[^\d+]/g, '')}` : ''
})
const occupancyJump = computed(() => (props.map?.computers?.length ? '#club-map' : bookingUrl()))

const occupancyDots = computed(() => {
    const total = Number(props.occupancy?.total || 0)
    const free = Number(props.occupancy?.free || 0)
    if (total < 1 || total > 28) return null
    return Array.from({ length: total }, (_, i) => i < free)
})

const cheapestSlug = computed(() => {
    const priced = props.zones.filter((zone) => zone.price_per_hour)
    if (priced.length < 2) return ''
    const min = Math.min(...priced.map((zone) => Number(zone.price_per_hour)))
    const matches = priced.filter((zone) => Number(zone.price_per_hour) === min)
    return matches.length === 1 ? matches[0].slug : ''
})

const reviewsScore = computed(() => {
    if (!props.reviews.length) return null
    const avg = props.reviews.reduce((sum, review) => sum + (review.rating || 0), 0) / props.reviews.length
    return avg.toFixed(1)
})

const buildHighlights = [
    { title: 'Подбор', text: 'железо под игры и бюджет' },
    { title: 'Сборка', text: 'в клубе, со стресс-тестами' },
    { title: 'Гарантия', text: 'на готовый компьютер' },
]

const tariffMode = ref<'hourly' | 'packages'>('hourly')

const allPackages = computed(() =>
    props.zones.flatMap((zone) =>
        zone.packages.map((pkg) => ({
            ...pkg,
            zoneName: zone.name,
            zoneColor: zone.color,
            zoneSlug: zone.slug,
            freeSeatId: zone.free_seat_id,
        }))
    )
)

const FREE_GAMES_SHOWN = 12

const paidGames = computed(() => props.games.filter((g) => g.is_paid))
const freeGames = computed(() => props.games.filter((g) => !g.is_paid))
const freeGamesShown = computed(() => freeGames.value.slice(0, FREE_GAMES_SHOWN))
const freeGamesRest = computed(() => Math.max(0, freeGames.value.length - FREE_GAMES_SHOWN))

const posterUrl = (poster: string | null) => {
    if (!poster) return null
    return poster.startsWith('/') || poster.startsWith('http') ? poster : `/${poster}`
}

const formatMoney = (value: number) => new Intl.NumberFormat('ru-RU').format(Math.round(value))

const gamePriceLabel = (game: LandingGame) => {
    if (!game.price_rub) return 'Включено в тариф'
    const unit = game.unit_minutes === 60 ? 'час' : `${game.unit_minutes} мин`
    return `${formatMoney(game.price_rub)} ₽ / ${unit}`
}

const selectedRoomInfo = ref<RoomInfoShowPayload | null>(null)
const showInfoModal = ref(false)

const openRoomInfo = (room: RoomInfoShowPayload) => {
    selectedRoomInfo.value = room
    showInfoModal.value = true
}

const openSeat = (seatId: string) => router.visit(bookingUrl(seatId))
const openAddonSeats = (payload: { seatIds: string[] }) => {
    const occupied = props.map?.occupied_ids || []
    const free = (payload.seatIds || []).find(id => !occupied.includes(id))
    if (free) openSeat(free)
}

const steps = [
    {
        num: '01',
        title: 'Выберите место и время',
        text: 'Карта клуба показывает, какие места свободны. Отметьте своё, укажите дату, время и тариф.',
    },
    {
        num: '02',
        title: 'Оплатите бронь',
        text: 'Оплата онлайн по СБП или картой. Место закрепляется за вами сразу после оплаты.',
    },
    {
        num: '03',
        title: 'Приходите и играйте',
        text: 'На месте введите PIN-код из брони — компьютер запустится с вашей учётной записью.',
    },
]
</script>

<template>
    <Head :title="`${clubName}: бронирование мест`" />

    <MainLayout>
        <div class="landing w-full max-w-7xl">
            <div class="landing-glow" aria-hidden="true"></div>

            <!-- HERO -->
            <section class="relative grid gap-6 lg:grid-cols-[1.15fr_0.85fr] lg:items-stretch mb-10 sm:mb-14">
                <div class="hero-copy relative flex flex-col justify-center rounded-[1.25rem] border border-white/10 px-6 py-8 sm:px-9 sm:py-10 overflow-hidden">
                    <div class="pointer-events-none absolute inset-0 hero-copy__wash" aria-hidden="true"></div>
                    <div class="relative z-[1]">
                        <div class="flex flex-wrap items-center gap-2 mb-5">
                            <span class="hud-chip">
                                <span class="w-1.5 h-1.5 rounded-full bg-[#22c55e] animate-pulse"></span>
                                Online
                            </span>
                            <span v-if="contacts?.hours" class="hud-chip text-white/55">{{ contacts.hours }}</span>
                        </div>

                        <p class="label !mb-3 !text-white/35">{{ club?.name || 'Игровой клуб' }}</p>
                        <h1 class="hero-title">
                            Займи место.<br>
                            <span>Войди по PIN.</span>
                        </h1>
                        <p class="mt-5 text-sm sm:text-[15px] text-white/60 leading-relaxed max-w-[34rem]">
                            Карта клуба в реальном времени, оплата сразу, вход за компьютер по коду из брони — без очереди на ресепшене.
                        </p>

                        <div class="mt-8 flex flex-col sm:flex-row gap-3">
                            <Link :href="bookingUrl()" class="cta-primary">
                                Забронировать место
                            </Link>
                            <a href="#tariffs" class="cta-ghost">
                                Цены и тарифы
                            </a>
                        </div>

                        <div class="mt-8 flex flex-wrap gap-2.5">
                            <div v-if="contacts?.address" class="meta-chip">
                                <span class="meta-k">Адрес</span>
                                <span class="meta-v">
                                    <span v-if="contacts?.city">{{ contacts.city }}</span>
                                    <span v-else>{{ contacts.address }}</span>
                                </span>
                            </div>
                            <a v-if="contacts?.phone" :href="phoneHref" class="meta-chip hover:border-[#22c55e]/40">
                                <span class="meta-k">Телефон</span>
                                <span class="meta-v">{{ contacts.phone }}</span>
                            </a>
                            <div v-if="hasSeats" class="meta-chip">
                                <span class="meta-k">Свободно</span>
                                <span class="meta-v text-[#22c55e]">{{ occupancy?.free }} / {{ occupancy?.total }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <article class="build-card hud-frame hud-frame--amber relative overflow-hidden rounded-[1.25rem] p-6 sm:p-7 flex flex-col">
                    <div class="pointer-events-none absolute inset-0 build-card__grid" aria-hidden="true"></div>
                    <div class="pointer-events-none absolute -right-12 -top-20 h-56 w-56 rounded-full bg-amber-500/25 blur-3xl" aria-hidden="true"></div>

                    <div class="relative z-[1] flex-1 grid gap-6 sm:grid-cols-[1fr_132px] sm:items-end">
                        <div>
                            <div class="inline-flex items-center gap-2 mb-4">
                                <span class="w-1.5 h-1.5 rounded-full bg-amber-400 animate-pulse"></span>
                                <span class="label !mb-0 !text-amber-300/80">Магазин при клубе</span>
                            </div>
                            <h2 class="text-[1.65rem] sm:text-[1.85rem] font-black italic uppercase tracking-tighter text-white leading-[1.05]">
                                У нас можно собрать игровой компьютер
                            </h2>
                            <p class="mt-3 text-[13px] sm:text-sm text-white/55 leading-relaxed max-w-[34ch]">
                                Подберём комплектующие, соберём в клубе, прогоним тесты и отдадим с гарантией.
                            </p>
                        </div>

                        <div class="hidden sm:block self-center" aria-hidden="true">
                            <svg viewBox="0 0 140 210" fill="none" class="w-full h-auto drop-shadow-[0_0_24px_rgba(245,158,11,0.25)]">
                                <rect x="28" y="8" width="84" height="194" rx="8" stroke="#fbbf24" stroke-opacity="0.55" stroke-width="1.4"/>
                                <rect x="34" y="14" width="72" height="14" rx="2" fill="#fbbf24" fill-opacity="0.08"/>
                                <rect x="38" y="36" width="64" height="118" rx="3" stroke="#fde68a" stroke-opacity="0.35"/>
                                <rect x="46" y="46" width="38" height="36" rx="2" stroke="#f59e0b" stroke-opacity="0.6"/>
                                <rect x="54" y="54" width="18" height="18" rx="1.5" fill="#f59e0b" fill-opacity="0.2" stroke="#fbbf24"/>
                                <rect x="88" y="48" width="4" height="30" fill="#22c55e" fill-opacity="0.85"/>
                                <rect x="94" y="48" width="4" height="30" fill="#22c55e" fill-opacity="0.45"/>
                                <rect class="gpu-glow" x="46" y="92" width="48" height="18" rx="2" fill="#f59e0b"/>
                                <rect x="46" y="96" width="8" height="10" rx="1" fill="#78350f"/>
                                <g class="fan-spin">
                                    <circle cx="70" cy="136" r="14" stroke="#fbbf24" stroke-opacity="0.8"/>
                                    <path d="M70 136 L70 124 M70 136 L70 148 M70 136 L58 136 M70 136 L82 136" stroke="#fde68a" stroke-width="1.5"/>
                                    <circle cx="70" cy="136" r="2.6" fill="#fbbf24"/>
                                </g>
                                <rect x="38" y="164" width="64" height="16" rx="2" fill="#fbbf24" fill-opacity="0.1"/>
                                <rect x="34" y="188" width="72" height="8" rx="1" fill="#fbbf24" fill-opacity="0.15"/>
                            </svg>
                        </div>
                    </div>

                    <ul class="relative z-[1] mt-6 grid grid-cols-3 gap-2">
                        <li v-for="item in buildHighlights" :key="item.title" class="rounded-lg border border-amber-400/15 bg-black/40 px-2.5 py-2.5">
                            <div class="text-[10px] font-black uppercase tracking-widest text-amber-300">{{ item.title }}</div>
                            <div class="mt-1 text-[10px] text-white/45 leading-snug">{{ item.text }}</div>
                        </li>
                    </ul>

                    <div class="relative z-[1] mt-6 flex flex-col sm:flex-row gap-2.5">
                        <a v-if="phoneHref" :href="phoneHref" class="cta-amber">Позвонить</a>
                        <a v-if="telegramUrl" :href="telegramUrl" target="_blank" rel="noopener" class="cta-amber-ghost">Telegram</a>
                        <a
                            v-if="!phoneHref && !telegramUrl"
                            :href="contacts?.map_url || '#tariffs'"
                            :target="contacts?.map_url ? '_blank' : undefined"
                            rel="noopener"
                            class="cta-amber-ghost"
                        >
                            {{ contacts?.map_url ? 'Как проехать' : 'Приходите в клуб' }}
                        </a>
                    </div>
                </article>
            </section>

            <!-- ЗАНЯТОСТЬ -->
            <section v-if="hasSeats" class="mb-16 sm:mb-20">
                <a :href="occupancyJump" class="occupancy-hud hud-frame group">
                    <div class="flex items-center gap-2 shrink-0">
                        <span class="live-dot"></span>
                        <span class="label !mb-0 !text-[#22c55e]/80">Live · свободно сейчас</span>
                    </div>
                    <div class="flex items-baseline gap-2">
                        <span class="text-4xl font-black italic tracking-tighter text-white leading-none">{{ occupancy?.free }}</span>
                        <span class="text-white/35 text-sm">/ {{ occupancy?.total }}</span>
                    </div>
                    <div v-if="occupancyDots" class="flex flex-wrap gap-1.5" aria-hidden="true">
                        <span
                            v-for="(isFree, i) in occupancyDots"
                            :key="i"
                            class="h-2.5 w-2.5 rounded-[2px]"
                            :class="isFree ? 'bg-[#22c55e] shadow-[0_0_8px_rgba(34,197,94,0.7)]' : 'bg-white/15'"
                        ></span>
                    </div>
                    <div v-else class="hidden md:block h-1.5 flex-1 max-w-xs rounded-full bg-white/10 overflow-hidden">
                        <div class="h-full rounded-full bg-[#22c55e] shadow-[0_0_12px_rgba(34,197,94,0.6)]" :style="{ width: `${freePercent}%` }"></div>
                    </div>
                    <div v-if="kindSummary.length" class="flex flex-wrap gap-2">
                        <span v-for="k in kindSummary" :key="k.kind" class="kind-pill">
                            {{ k.label }} <b>{{ k.free }}/{{ k.total }}</b>
                        </span>
                    </div>
                    <span class="occ-go">
                        {{ map?.computers?.length ? 'Карта клуба' : 'Бронь' }} →
                    </span>
                </a>
            </section>

            <!-- ТАРИФЫ -->
            <section v-if="zones.length" id="tariffs" class="mb-16 sm:mb-20 scroll-mt-28">
                <header class="sec-head">
                    <span class="sec-idx">/01</span>
                    <h3 class="sec-title">Цены</h3>
                    <i class="sec-line" aria-hidden="true"></i>
                    <div v-if="allPackages.length" class="flex p-1 bg-black/50 border border-white/10 rounded-lg">
                        <button type="button" @click="tariffMode = 'hourly'"
                                :class="['px-4 py-2 rounded-md text-[10px] font-black uppercase tracking-widest transition-colors',
                                         tariffMode === 'hourly' ? 'bg-[#22c55e] text-black' : 'text-white/40 hover:text-white/70']">
                            Почасовой
                        </button>
                        <button type="button" @click="tariffMode = 'packages'"
                                :class="['px-4 py-2 rounded-md text-[10px] font-black uppercase tracking-widest transition-colors',
                                         tariffMode === 'packages' ? 'bg-[#22c55e] text-black' : 'text-white/40 hover:text-white/70']">
                            Пакеты
                        </button>
                    </div>
                </header>

                <div v-if="tariffMode === 'hourly'" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <article
                        v-for="zone in zones" :key="zone.slug"
                        class="price-card"
                        :class="{ 'price-card--hot': zone.slug === cheapestSlug }"
                    >
                        <span class="price-card__bar" :style="{ backgroundColor: zone.color }"></span>
                        <div class="flex items-center justify-between gap-3 mb-5">
                            <div class="flex items-center gap-2.5">
                                <span class="w-2 h-2 rounded-[2px] shrink-0" :style="{ backgroundColor: zone.color }"></span>
                                <h4 class="text-base font-black italic uppercase tracking-tight text-white">{{ zone.name }}</h4>
                            </div>
                            <span v-if="zone.slug === cheapestSlug" class="hot-badge">старт</span>
                        </div>

                        <div v-if="zone.price_per_hour" class="flex items-end gap-1.5">
                            <span class="text-5xl font-black italic tracking-tighter text-white leading-none">
                                {{ formatMoney(zone.price_per_hour) }}
                            </span>
                            <span class="text-[#22c55e] font-black pb-1">₽</span>
                            <span class="text-white/30 text-xs pb-1.5">/ час</span>
                        </div>
                        <div v-else class="text-white/40 text-sm">Цена уточняется</div>

                        <div class="mt-6 space-y-2.5 text-[12px]">
                            <div class="flex items-center justify-between">
                                <span class="text-white/40">Свободно</span>
                                <span class="font-mono" :class="zone.seats_free ? 'text-white' : 'text-orange-400'">
                                    {{ zone.seats_free }} / {{ zone.seats_total }}
                                </span>
                            </div>
                            <div v-for="(stat, kind) in zone.kinds" :key="kind" class="flex items-center justify-between">
                                <span class="text-white/40">{{ KIND_LABELS[kind] || kind }}</span>
                                <span class="font-mono text-white/55">{{ stat.total }} шт.</span>
                            </div>
                        </div>

                        <Link v-if="zone.seats_free && zone.free_seat_id" :href="bookingUrl(zone.free_seat_id)" class="price-cta">
                            Выбрать место
                        </Link>
                        <div v-else class="price-cta price-cta--dead">Все места заняты</div>
                    </article>
                </div>

                <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <article v-for="pkg in allPackages" :key="`${pkg.zoneSlug}-${pkg.id}`" class="price-card">
                        <span class="price-card__bar" :style="{ backgroundColor: pkg.zoneColor }"></span>
                        <div class="flex items-start justify-between gap-3 mb-5">
                            <div>
                                <h4 class="text-base font-black italic uppercase tracking-tight text-white leading-none">
                                    {{ pkg.hours }} ч
                                </h4>
                                <span class="text-[11px] text-white/40 mt-1 inline-block">{{ pkg.zoneName }}</span>
                            </div>
                            <span v-if="pkg.discount_pct > 0" class="hot-badge">−{{ pkg.discount_pct }}%</span>
                        </div>
                        <div class="flex items-end gap-1.5">
                            <span class="text-5xl font-black italic tracking-tighter text-white leading-none">
                                {{ formatMoney(pkg.cost) }}
                            </span>
                            <span class="text-[#22c55e] font-black pb-1">₽</span>
                        </div>
                        <p class="mt-2 text-[12px] text-white/40">{{ formatMoney(pkg.hourly_equivalent) }} ₽ за час</p>
                        <Link :href="bookingUrl(pkg.freeSeatId)" class="price-cta">Забронировать</Link>
                    </article>
                </div>
            </section>

            <!-- КАРТА -->
            <section v-if="map?.computers?.length" id="club-map" class="mb-16 sm:mb-20 scroll-mt-28">
                <header class="sec-head">
                    <span class="sec-idx">/02</span>
                    <h3 class="sec-title">Карта клуба</h3>
                    <i class="sec-line" aria-hidden="true"></i>
                    <div class="flex flex-wrap gap-3 text-[10px] font-black uppercase tracking-widest text-white/40">
                        <span class="flex items-center gap-1.5"><i class="legend-swatch border-[#22c55e] bg-[#001100]"></i> свободно</span>
                        <span class="flex items-center gap-1.5"><i class="legend-swatch border-[#444] bg-[#1a1a1a]"></i> занято</span>
                        <span class="flex items-center gap-1.5"><i class="legend-swatch border-[#a855f7]"></i> ТВ</span>
                        <span class="flex items-center gap-1.5"><i class="legend-swatch border-[#3b82f6]"></i> PS5</span>
                    </div>
                </header>

                <div class="map-stage hud-frame">
                    <div class="h-[380px] sm:h-[540px]">
                        <ClubMap
                            :computers="map.computers"
                            :map-config="map.config"
                            :viewbox="map.viewbox"
                            :occupied-ids="map.occupied_ids"
                            @toggle-seat="openSeat"
                            @toggle-addon-seats="openAddonSeats"
                            @show-info="openRoomInfo"
                        />
                    </div>
                </div>
                <p class="mt-3 text-[12px] text-white/40">Нажмите на свободное место, чтобы перейти к бронированию.</p>
            </section>

            <!-- ИГРЫ -->
            <section v-if="games.length" class="mb-16 sm:mb-20">
                <header class="sec-head mb-3">
                    <span class="sec-idx">/03</span>
                    <h3 class="sec-title">Игры</h3>
                    <i class="sec-line" aria-hidden="true"></i>
                </header>
                <p v-if="paidGames.length" class="text-[13px] text-white/50 mb-6 max-w-[640px] leading-relaxed">
                    Игры с лицензией по подписке лучше забронировать заранее вместе с местом — так доступ
                    к аккаунту гарантированно останется за вами.
                </p>

                <div v-if="paidGames.length" class="grid gap-3 grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 mb-8">
                    <Link v-for="game in paidGames" :key="game.id" :href="bookingUrl()" class="game-card group">
                        <div class="relative aspect-[3/4] overflow-hidden bg-black">
                            <img v-if="posterUrl(game.poster)" :src="posterUrl(game.poster) || ''" :alt="game.title"
                                 loading="lazy" decoding="async"
                                 class="w-full h-full object-cover opacity-80 group-hover:opacity-100 group-hover:scale-110 transition-[transform,opacity] duration-700" />
                            <div v-else class="w-full h-full flex items-center justify-center text-white/20 text-[10px] uppercase tracking-widest">
                                нет постера
                            </div>
                            <div class="absolute inset-x-0 bottom-0 p-3 bg-gradient-to-t from-black via-black/80 to-transparent">
                                <div class="text-[12px] font-black italic uppercase text-white leading-tight line-clamp-2">{{ game.title }}</div>
                                <div class="text-[11px] text-[#22c55e] mt-1">{{ gamePriceLabel(game) }}</div>
                            </div>
                        </div>
                    </Link>
                </div>

                <div v-if="freeGames.length">
                    <div class="label mb-3">Доступны без доплаты</div>
                    <div class="flex flex-wrap gap-2">
                        <span v-for="game in freeGamesShown" :key="game.id" class="free-tag">{{ game.title }}</span>
                        <span v-if="freeGamesRest" class="free-tag !text-white/30 !border-transparent">и ещё {{ freeGamesRest }}</span>
                    </div>
                </div>
            </section>

            <!-- ОТЗЫВЫ -->
            <section v-if="reviews.length" class="mb-16 sm:mb-20">
                <header class="sec-head">
                    <span class="sec-idx">/04</span>
                    <h3 class="sec-title">Отзывы гостей</h3>
                    <span v-if="reviewsScore" class="text-yellow-400 text-sm font-black italic">★ {{ reviewsScore }}</span>
                    <i class="sec-line" aria-hidden="true"></i>
                    <a v-if="reviews_map_url" :href="reviews_map_url" target="_blank" rel="noopener" class="sec-link">
                        Яндекс.Карты →
                    </a>
                </header>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <article v-for="review in reviews" :key="review.id" class="review-card">
                        <div class="text-[#22c55e]/50 text-4xl font-black italic leading-none mb-2">“</div>
                        <p class="text-[13px] text-white/70 leading-relaxed line-clamp-6 flex-1">{{ review.text }}</p>
                        <div class="mt-5 flex items-center justify-between gap-3">
                            <div class="text-[11px] font-black uppercase italic text-white tracking-tight truncate">{{ review.author }}</div>
                            <div class="text-yellow-400 text-[11px] font-black shrink-0 tracking-widest">
                                {{ '★'.repeat(Math.min(5, Math.round(review.rating || 0))) }}
                            </div>
                        </div>
                    </article>
                </div>
            </section>

            <!-- КАК ЭТО РАБОТАЕТ -->
            <section class="mb-6">
                <header class="sec-head">
                    <span class="sec-idx">/05</span>
                    <h3 class="sec-title">Как забронировать</h3>
                    <i class="sec-line" aria-hidden="true"></i>
                </header>
                <div class="steps-grid grid gap-4 sm:grid-cols-3">
                    <article v-for="step in steps" :key="step.num" class="step-card">
                        <div class="text-[#22c55e] font-black italic text-2xl mb-4 text-glow">{{ step.num }}</div>
                        <h4 class="text-white font-black italic uppercase text-sm mb-2 tracking-tight">{{ step.title }}</h4>
                        <p class="text-[13px] text-white/50 leading-relaxed">{{ step.text }}</p>
                    </article>
                </div>

                <div class="finale">
                    <div>
                        <div class="text-white font-black italic uppercase tracking-tight text-lg sm:text-xl">Готов зайти в сектор?</div>
                        <p v-if="minAge" class="text-[12px] text-white/40 mt-1">Посетителям младше {{ minAge }} лет — только с согласия родителей.</p>
                    </div>
                    <Link :href="bookingUrl()" class="cta-primary">
                        Перейти к бронированию
                    </Link>
                </div>
            </section>

            <SiteFooter :contacts="contacts" />
        </div>

        <ZoneInfoModal :isOpen="showInfoModal" :room="selectedRoomInfo" @close="showInfoModal = false" />
    </MainLayout>
</template>

<style scoped>
@reference "../../../css/app.css";

.landing { position: relative; }
.landing-glow {
    pointer-events: none;
    position: absolute;
    top: -8rem;
    left: 50%;
    z-index: 0;
    width: min(70rem, 100%);
    height: 28rem;
    transform: translateX(-50%);
    background: radial-gradient(ellipse at center, rgba(34, 197, 94, 0.16), transparent 68%);
}

.label {
    @apply text-[10px] uppercase tracking-[0.25em] text-white/30 mb-1.5 font-black italic;
}

.hero-copy {
    background: linear-gradient(165deg, rgba(8, 12, 8, 0.92), rgba(5, 5, 5, 0.88));
    box-shadow: 0 0 0 1px rgba(34, 197, 94, 0.06), 0 24px 80px rgba(0, 0, 0, 0.45);
}
.hero-copy__wash {
    background:
        linear-gradient(90deg, rgba(34, 197, 94, 0.12), transparent 42%),
        repeating-linear-gradient(180deg, rgba(34, 197, 94, 0.035) 0 1px, transparent 1px 7px);
    mask-image: linear-gradient(180deg, black, transparent 90%);
}

.hero-title {
    font-size: clamp(2.35rem, 6.2vw, 4.4rem);
    font-weight: 900;
    font-style: italic;
    text-transform: uppercase;
    letter-spacing: -0.06em;
    line-height: 0.92;
    color: #fff;
    text-shadow: 0 0 28px rgba(34, 197, 94, 0.28);
}
.hero-title span {
    color: #22c55e;
    text-shadow: 0 0 24px rgba(34, 197, 94, 0.55);
}

.hud-chip {
    @apply inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md border border-white/10 bg-black/40 text-[10px] font-black uppercase tracking-[0.18em] text-white/80;
}

.meta-chip {
    @apply inline-flex flex-col gap-0.5 px-3 py-2 rounded-lg border border-white/10 bg-black/30 min-w-[7rem];
    text-decoration: none;
}
.meta-k { @apply text-[9px] uppercase tracking-[0.2em] text-white/30 font-black italic; }
.meta-v { @apply text-[12px] text-white/85 font-bold; }

.cta-primary {
    @apply px-7 py-4 rounded-xl bg-[#22c55e] text-black font-black uppercase text-[11px] tracking-[0.2em] text-center;
    box-shadow: 0 0 28px rgba(34, 197, 94, 0.35);
    transition: background-color 0.2s, box-shadow 0.2s, transform 0.2s;
}
.cta-primary:hover {
    background: #2ae06d;
    box-shadow: 0 0 40px rgba(34, 197, 94, 0.5);
    transform: translateY(-1px);
}
.cta-ghost {
    @apply px-7 py-4 rounded-xl border border-white/15 text-white/70 font-black uppercase text-[11px] tracking-[0.2em] text-center transition-colors;
}
.cta-ghost:hover { @apply border-white/40 text-white; }

.cta-amber {
    @apply px-5 py-3 rounded-lg bg-amber-400 text-black font-black uppercase text-[10px] tracking-[0.18em] text-center transition-colors;
}
.cta-amber:hover { @apply bg-amber-300; }
.cta-amber-ghost {
    @apply px-5 py-3 rounded-lg border border-amber-400/40 text-amber-100 font-black uppercase text-[10px] tracking-[0.18em] text-center transition-colors;
}
.cta-amber-ghost:hover { @apply border-amber-300 text-white; }

.hud-frame {
    position: relative;
}
.hud-frame::before,
.hud-frame::after {
    content: '';
    position: absolute;
    width: 14px;
    height: 14px;
    pointer-events: none;
    z-index: 2;
    border: 1.5px solid rgba(34, 197, 94, 0.55);
}
.hud-frame::before { top: 8px; left: 8px; border-right: 0; border-bottom: 0; }
.hud-frame::after { bottom: 8px; right: 8px; border-left: 0; border-top: 0; }
.hud-frame--amber::before,
.hud-frame--amber::after { border-color: rgba(251, 191, 36, 0.55); }

.build-card {
    background:
        radial-gradient(circle at 88% 8%, rgba(245, 158, 11, 0.2), transparent 42%),
        linear-gradient(165deg, #16120a 0%, #0a0906 58%);
    box-shadow: 0 24px 80px rgba(0, 0, 0, 0.4);
}
.build-card__grid {
    background-image:
        linear-gradient(rgba(245, 158, 11, 0.06) 1px, transparent 1px),
        linear-gradient(90deg, rgba(245, 158, 11, 0.06) 1px, transparent 1px);
    background-size: 18px 18px;
    mask-image: linear-gradient(180deg, rgba(0, 0, 0, 0.6), transparent 88%);
}

.gpu-glow {
    filter: drop-shadow(0 0 8px rgba(245, 158, 11, 0.85));
    animation: gpu-pulse 2.6s ease-in-out infinite;
}
.fan-spin {
    transform-origin: 70px 136px;
    animation: fan-spin 9s linear infinite;
}

.occupancy-hud {
    @apply flex flex-col sm:flex-row sm:items-center gap-4 sm:gap-6 px-5 py-5 rounded-[1.25rem] border border-[#22c55e]/20;
    background: linear-gradient(90deg, rgba(34, 197, 94, 0.08), rgba(10, 10, 10, 0.92) 28%);
    text-decoration: none;
    transition: border-color 0.2s, box-shadow 0.2s;
}
.occupancy-hud:hover {
    border-color: rgba(34, 197, 94, 0.45);
    box-shadow: 0 0 32px rgba(34, 197, 94, 0.12);
}
.live-dot {
    @apply w-2 h-2 rounded-full bg-[#22c55e];
    box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7);
    animation: live-ping 1.8s ease-out infinite;
}
.kind-pill {
    @apply px-2.5 py-1 rounded-md border border-white/10 bg-black/40 text-[11px] font-mono text-white/60;
}
.kind-pill b { @apply text-white font-bold ml-1; }
.occ-go {
    @apply text-[10px] font-black uppercase tracking-[0.18em] text-[#22c55e] sm:ml-auto;
}

.sec-head {
    @apply flex flex-wrap items-center gap-3 mb-6;
}
.sec-idx {
    @apply text-[11px] font-black italic text-[#22c55e] tracking-widest;
}
.sec-title {
    @apply text-xl sm:text-2xl font-black italic uppercase tracking-tight text-white;
}
.sec-line {
    @apply hidden sm:block flex-1 h-px;
    background: linear-gradient(90deg, rgba(34, 197, 94, 0.35), transparent);
}
.sec-link {
    @apply text-[11px] font-black uppercase tracking-[0.2em] text-[#22c55e] italic transition-colors;
}
.sec-link:hover { color: #2ae06d; }

.price-card {
    @apply relative flex flex-col overflow-hidden rounded-[1.25rem] border border-white/10 p-6;
    background: rgba(8, 8, 8, 0.92);
    transition: border-color 0.2s, transform 0.2s, box-shadow 0.2s;
}
.price-card:hover {
    border-color: rgba(255, 255, 255, 0.28);
    transform: translateY(-3px);
    box-shadow: 0 16px 40px rgba(0, 0, 0, 0.45);
}
.price-card--hot {
    border-color: rgba(34, 197, 94, 0.35);
    box-shadow: 0 0 32px rgba(34, 197, 94, 0.08);
}
.price-card__bar {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
}
.hot-badge {
    @apply px-2 py-0.5 rounded-full bg-[#22c55e]/15 border border-[#22c55e]/40 text-[#22c55e] text-[9px] font-black uppercase tracking-widest;
}
.price-cta {
    @apply mt-auto pt-6 text-center font-black uppercase text-[10px] tracking-[0.2em];
}
.price-cta:not(.price-cta--dead) {
    @apply py-3.5 rounded-lg bg-white/5 border border-white/10 text-white/70 transition-colors;
}
.price-cta:not(.price-cta--dead):hover {
    @apply bg-[#22c55e] text-black border-[#22c55e];
}
.price-cta--dead {
    @apply py-3.5 rounded-lg border border-white/5 text-white/25;
}

.map-stage {
    @apply rounded-[1.25rem] border border-[#22c55e]/20 overflow-hidden p-2 sm:p-3;
    background: rgba(0, 0, 0, 0.72);
    box-shadow: 0 0 60px rgba(34, 197, 94, 0.08);
}

.legend-swatch {
    @apply inline-block w-2.5 h-2.5 border;
}

.game-card {
    @apply rounded-xl overflow-hidden border border-white/10 bg-black;
    transition: border-color 0.2s, transform 0.2s, box-shadow 0.2s;
}
.game-card:hover {
    border-color: rgba(34, 197, 94, 0.45);
    transform: translateY(-3px);
    box-shadow: 0 12px 32px rgba(0, 0, 0, 0.5);
}

.free-tag {
    @apply px-3 py-1.5 rounded-md bg-black/50 border border-white/10 text-[12px] text-white/65;
}

.review-card {
    @apply flex flex-col rounded-[1.25rem] border border-white/10 p-6;
    background: rgba(8, 8, 8, 0.92);
}

.steps-grid { position: relative; }
@media (min-width: 640px) {
    .steps-grid::before {
        content: '';
        position: absolute;
        top: 1.85rem;
        left: 10%;
        right: 10%;
        height: 1px;
        background: linear-gradient(90deg, transparent, rgba(34, 197, 94, 0.35), transparent);
        pointer-events: none;
    }
}
.step-card {
    @apply relative rounded-[1.25rem] border border-white/10 p-6;
    background: rgba(8, 8, 8, 0.92);
}

.finale {
    @apply mt-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 px-6 py-6 rounded-[1.25rem] border border-[#22c55e]/25;
    background: linear-gradient(90deg, rgba(34, 197, 94, 0.12), rgba(8, 8, 8, 0.94));
}

@keyframes gpu-pulse {
    0%, 100% { filter: drop-shadow(0 0 5px rgba(245, 158, 11, 0.45)); }
    50% { filter: drop-shadow(0 0 14px rgba(251, 191, 36, 0.95)); }
}
@keyframes fan-spin { to { transform: rotate(360deg); } }
@keyframes live-ping {
    0% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.65); }
    70% { box-shadow: 0 0 0 8px rgba(34, 197, 94, 0); }
    100% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); }
}

@media (prefers-reduced-motion: reduce) {
    .gpu-glow, .fan-spin, .live-dot { animation: none; }
    .game-card img { transition: none; }
    .price-card:hover, .game-card:hover, .cta-primary:hover { transform: none; }
}
</style>
