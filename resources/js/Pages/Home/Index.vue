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
    <!-- Бренд к заголовку добавляет app.js, meta description отдаёт blade из config('club.seo'). -->
    <Head :title="`${clubName}: бронирование мест`" />

    <MainLayout>
        <div class="w-full max-w-7xl">

            <!-- HERO -->
            <section class="grid gap-6 lg:grid-cols-[1.2fr_1fr] lg:items-stretch mb-16">
                <div class="flex flex-col justify-center">
                    <h2 class="text-3xl sm:text-4xl lg:text-5xl font-black italic uppercase tracking-tighter text-white leading-[1.05]">
                        {{ club?.name || 'Игровой клуб' }}
                    </h2>
                    <p class="mt-4 text-sm sm:text-base text-white/60 leading-relaxed max-w-[560px]">
                        Выберите место на карте клуба, время и тариф — бронь подтверждается сразу после оплаты,
                        а вход за компьютер по PIN-коду.
                    </p>

                    <div class="mt-7 flex flex-col sm:flex-row gap-3">
                        <Link :href="bookingUrl()"
                              class="px-7 py-4 rounded-2xl bg-[#22c55e] text-black font-black uppercase text-[11px] tracking-[0.2em] text-center hover:bg-[#2ae06d] transition-colors">
                            Забронировать место
                        </Link>
                        <a href="#tariffs"
                           class="px-7 py-4 rounded-2xl border border-white/15 text-white/70 font-black uppercase text-[11px] tracking-[0.2em] text-center hover:border-white/40 hover:text-white transition-colors">
                            Цены и тарифы
                        </a>
                    </div>

                    <dl class="mt-8 grid gap-x-8 gap-y-4 sm:grid-cols-3 text-[13px]">
                        <div v-if="contacts?.address">
                            <dt class="label">Адрес</dt>
                            <dd class="text-white/80 leading-snug">
                                <span v-if="contacts?.city">{{ contacts.city }}, </span>{{ contacts.address }}
                            </dd>
                        </div>
                        <div v-if="contacts?.hours">
                            <dt class="label">Работаем</dt>
                            <dd class="text-white/80 leading-snug">{{ contacts.hours }}</dd>
                        </div>
                        <div v-if="contacts?.phone">
                            <dt class="label">Телефон</dt>
                            <dd>
                                <a :href="`tel:${String(contacts.phone).replace(/[^\d+]/g, '')}`"
                                   class="text-white/80 hover:text-[#22c55e] transition-colors">{{ contacts.phone }}</a>
                            </dd>
                        </div>
                    </dl>
                </div>

                <article class="build-card relative overflow-hidden rounded-3xl border border-amber-400/25 p-6 sm:p-7 flex flex-col justify-between min-h-[280px]">
                    <div class="pointer-events-none absolute inset-0 build-card__grid" aria-hidden="true"></div>
                    <div class="pointer-events-none absolute -right-10 -top-16 h-48 w-48 rounded-full bg-amber-500/20 blur-3xl" aria-hidden="true"></div>
                    <div class="pointer-events-none absolute right-2 bottom-1 hidden w-[40%] max-w-[170px] sm:block" aria-hidden="true">
                        <svg viewBox="0 0 180 220" fill="none" class="w-full h-auto text-amber-200/70">
                            <rect x="42" y="16" width="96" height="188" rx="10" stroke="currentColor" stroke-width="1.4"/>
                            <rect x="54" y="34" width="72" height="142" rx="4" stroke="currentColor" stroke-opacity="0.35"/>
                            <rect x="64" y="46" width="52" height="48" rx="2" stroke="#f59e0b" stroke-opacity="0.55"/>
                            <rect x="78" y="58" width="24" height="24" rx="2" fill="#f59e0b" fill-opacity="0.22" stroke="#fbbf24"/>
                            <rect x="108" y="50" width="4" height="38" fill="#22c55e" fill-opacity="0.7"/>
                            <rect x="115" y="50" width="4" height="38" fill="#22c55e" fill-opacity="0.4"/>
                            <rect class="gpu-glow" x="64" y="106" width="52" height="20" rx="2" fill="#f59e0b"/>
                            <g class="fan-spin">
                                <circle cx="90" cy="154" r="13" stroke="#fbbf24" stroke-opacity="0.75"/>
                                <path d="M90 154 L90 143 M90 154 L90 165 M90 154 L79 154 M90 154 L101 154" stroke="#fbbf24" stroke-opacity="0.8" stroke-width="1.4"/>
                                <circle cx="90" cy="154" r="2.4" fill="#fbbf24"/>
                            </g>
                            <rect x="54" y="182" width="72" height="14" rx="2" fill="currentColor" fill-opacity="0.12"/>
                        </svg>
                    </div>

                    <div class="relative z-[1]">
                        <div class="inline-flex items-center gap-2 mb-4">
                            <span class="w-1.5 h-1.5 rounded-full bg-amber-400 animate-pulse"></span>
                            <span class="label !mb-0 !text-amber-400/70">Магазин при клубе</span>
                        </div>
                        <h3 class="text-2xl sm:text-3xl font-black italic uppercase tracking-tighter text-white leading-[1.05] max-w-[18ch]">
                            У нас можно собрать игровой компьютер
                        </h3>
                        <p class="mt-3 text-[13px] sm:text-sm text-white/55 leading-relaxed max-w-[36ch]">
                            Подберём комплектующие, соберём в клубе, прогоним тесты и отдадим с гарантией.
                        </p>
                    </div>

                    <ul class="relative z-[1] mt-5 grid grid-cols-3 gap-2 max-w-[360px]">
                        <li v-for="item in buildHighlights" :key="item.title"
                            class="rounded-xl border border-white/10 bg-black/30 px-2.5 py-2">
                            <div class="text-[10px] font-black uppercase tracking-widest text-amber-300">{{ item.title }}</div>
                            <div class="mt-0.5 text-[10px] text-white/45 leading-snug">{{ item.text }}</div>
                        </li>
                    </ul>

                    <div class="relative z-[1] mt-6 flex flex-col sm:flex-row gap-2.5">
                        <a v-if="phoneHref" :href="phoneHref"
                           class="px-5 py-3 rounded-xl bg-amber-400 text-black font-black uppercase text-[10px] tracking-[0.18em] text-center hover:bg-amber-300 transition-colors">
                            Позвонить
                        </a>
                        <a v-if="telegramUrl" :href="telegramUrl" target="_blank" rel="noopener"
                           class="px-5 py-3 rounded-xl border border-amber-400/40 text-amber-200 font-black uppercase text-[10px] tracking-[0.18em] text-center hover:border-amber-300 hover:text-white transition-colors">
                            Telegram
                        </a>
                        <a v-if="!phoneHref && !telegramUrl && contacts?.address"
                           :href="contacts?.map_url || '#tariffs'"
                           class="px-5 py-3 rounded-xl border border-amber-400/40 text-amber-200 font-black uppercase text-[10px] tracking-[0.18em] text-center hover:border-amber-300 hover:text-white transition-colors">
                            Приходите в клуб
                        </a>
                    </div>
                </article>
            </section>

            <!-- ЗАНЯТОСТЬ -->
            <section v-if="hasSeats" class="mb-16">
                <a :href="occupancyJump"
                   class="flex flex-col sm:flex-row sm:items-center gap-4 sm:gap-6 bg-white/5 md:bg-[#0a0a0a] border border-white/10 rounded-2xl px-5 py-4 hover:border-[#22c55e]/35 transition-colors">
                    <div class="flex items-center gap-2 shrink-0">
                        <span class="w-1.5 h-1.5 rounded-full bg-[#22c55e] animate-pulse"></span>
                        <span class="label !mb-0">Свободно прямо сейчас</span>
                    </div>
                    <div class="flex items-baseline gap-2">
                        <span class="text-3xl font-black italic tracking-tighter text-white leading-none">
                            {{ occupancy?.free }}
                        </span>
                        <span class="text-white/40 text-sm">из {{ occupancy?.total }} мест</span>
                    </div>
                    <div class="hidden md:block h-1.5 flex-1 rounded-full bg-white/10 overflow-hidden">
                        <div class="h-full rounded-full bg-[#22c55e] transition-all duration-700"
                             :style="{ width: `${freePercent}%` }"></div>
                    </div>
                    <div v-if="kindSummary.length" class="flex flex-wrap gap-x-5 gap-y-1 text-[12px] font-mono">
                        <span v-for="k in kindSummary" :key="k.kind" class="text-white/75">
                            <span class="text-white/35">{{ k.label }}</span> {{ k.free }}/{{ k.total }}
                        </span>
                    </div>
                    <span class="text-[10px] font-black uppercase tracking-[0.18em] text-white/30 sm:ml-auto">
                        {{ map?.computers?.length ? 'К карте →' : 'Забронировать →' }}
                    </span>
                </a>
            </section>

            <!-- ТАРИФЫ -->
            <section v-if="zones.length" id="tariffs" class="mb-16 scroll-mt-28">
                <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
                    <h3 class="text-xl sm:text-2xl font-black italic uppercase tracking-tight text-white">Цены</h3>

                    <div v-if="allPackages.length" class="flex p-1 bg-white/5 border border-white/10 rounded-xl">
                        <button type="button" @click="tariffMode = 'hourly'"
                                :class="['px-5 py-2 rounded-lg text-[10px] font-black uppercase tracking-widest transition-colors',
                                         tariffMode === 'hourly' ? 'bg-[#22c55e] text-black' : 'text-white/40 hover:text-white/70']">
                            Почасовой
                        </button>
                        <button type="button" @click="tariffMode = 'packages'"
                                :class="['px-5 py-2 rounded-lg text-[10px] font-black uppercase tracking-widest transition-colors',
                                         tariffMode === 'packages' ? 'bg-[#22c55e] text-black' : 'text-white/40 hover:text-white/70']">
                            Пакеты
                        </button>
                    </div>
                </div>

                <div v-if="tariffMode === 'hourly'" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div v-for="zone in zones" :key="zone.slug"
                         class="bg-white/5 md:bg-[#0a0a0a] border border-white/10 rounded-3xl p-6 flex flex-col hover:border-white/25 transition-colors">
                        <div class="flex items-center gap-2.5 mb-4">
                            <span class="w-2.5 h-2.5 rounded-sm shrink-0" :style="{ backgroundColor: zone.color }"></span>
                            <h4 class="text-base font-black italic uppercase tracking-tight text-white">{{ zone.name }}</h4>
                        </div>

                        <div v-if="zone.price_per_hour" class="flex items-baseline gap-1.5">
                            <span class="text-4xl font-black italic tracking-tighter text-white leading-none">
                                {{ formatMoney(zone.price_per_hour) }}
                            </span>
                            <span class="text-[#22c55e] font-bold">₽</span>
                            <span class="text-white/30 text-xs">/ час</span>
                        </div>
                        <div v-else class="text-white/40 text-sm">Цена уточняется</div>

                        <div class="mt-5 space-y-2 text-[12px]">
                            <div class="flex items-center justify-between border-b border-white/5 pb-2">
                                <span class="text-white/50">Свободно</span>
                                <span class="font-mono" :class="zone.seats_free ? 'text-white/80' : 'text-orange-400'">
                                    {{ zone.seats_free }} / {{ zone.seats_total }}
                                </span>
                            </div>
                            <div v-for="(stat, kind) in zone.kinds" :key="kind"
                                 class="flex items-center justify-between border-b border-white/5 pb-2 last:border-0">
                                <span class="text-white/50">{{ KIND_LABELS[kind] || kind }}</span>
                                <span class="font-mono text-white/60">{{ stat.total }} шт.</span>
                            </div>
                        </div>

                        <Link v-if="zone.seats_free && zone.free_seat_id" :href="bookingUrl(zone.free_seat_id)"
                              class="mt-6 py-3.5 rounded-xl bg-white/5 border border-white/10 text-center font-black uppercase text-[10px] tracking-[0.2em] text-white/70 hover:bg-[#22c55e] hover:text-black hover:border-[#22c55e] transition-colors">
                            Выбрать место
                        </Link>
                        <div v-else
                             class="mt-6 py-3.5 rounded-xl border border-white/5 text-center font-black uppercase text-[10px] tracking-[0.2em] text-white/25">
                            Все места заняты
                        </div>
                    </div>
                </div>

                <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div v-for="pkg in allPackages" :key="`${pkg.zoneSlug}-${pkg.id}`"
                         class="bg-white/5 md:bg-[#0a0a0a] border border-white/10 rounded-3xl p-6 flex flex-col hover:border-white/25 transition-colors">
                        <div class="flex items-start justify-between gap-3 mb-4">
                            <div class="flex items-center gap-2.5">
                                <span class="w-2.5 h-2.5 rounded-sm shrink-0" :style="{ backgroundColor: pkg.zoneColor }"></span>
                                <div>
                                    <h4 class="text-base font-black italic uppercase tracking-tight text-white leading-none">
                                        {{ pkg.hours }} ч
                                    </h4>
                                    <span class="text-[11px] text-white/40">{{ pkg.zoneName }}</span>
                                </div>
                            </div>
                            <span v-if="pkg.discount_pct > 0"
                                  class="px-2.5 py-1 rounded-full bg-[#22c55e]/10 border border-[#22c55e]/30 text-[#22c55e] text-[9px] font-black uppercase tracking-widest">
                                −{{ pkg.discount_pct }}%
                            </span>
                        </div>

                        <div class="flex items-baseline gap-1.5">
                            <span class="text-4xl font-black italic tracking-tighter text-white leading-none">
                                {{ formatMoney(pkg.cost) }}
                            </span>
                            <span class="text-[#22c55e] font-bold">₽</span>
                        </div>
                        <p class="mt-2 text-[12px] text-white/40">
                            {{ formatMoney(pkg.hourly_equivalent) }} ₽ за час
                        </p>

                        <Link :href="bookingUrl(pkg.freeSeatId)"
                              class="mt-6 py-3.5 rounded-xl bg-white/5 border border-white/10 text-center font-black uppercase text-[10px] tracking-[0.2em] text-white/70 hover:bg-[#22c55e] hover:text-black hover:border-[#22c55e] transition-colors">
                            Забронировать
                        </Link>
                    </div>
                </div>
            </section>

            <!-- КАРТА -->
            <section v-if="map?.computers?.length" id="club-map" class="mb-16 scroll-mt-28">
                <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
                    <h3 class="text-xl sm:text-2xl font-black italic uppercase tracking-tight text-white">Карта клуба</h3>
                    <div class="flex flex-wrap gap-4 text-[11px] text-white/40">
                        <span class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 border border-[#22c55e] bg-[#001100]"></span> свободно
                        </span>
                        <span class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 border border-[#444] bg-[#1a1a1a]"></span> занято
                        </span>
                        <span class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 border border-[#a855f7]"></span> ТВ
                        </span>
                        <span class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 border border-[#3b82f6]"></span> PS5
                        </span>
                    </div>
                </div>

                <div class="h-[380px] sm:h-[520px]">
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
                <p class="mt-3 text-[12px] text-white/40">Нажмите на свободное место, чтобы перейти к бронированию.</p>
            </section>

            <!-- ИГРЫ -->
            <section v-if="games.length" class="mb-16">
                <h3 class="text-xl sm:text-2xl font-black italic uppercase tracking-tight text-white mb-2">Игры</h3>
                <p v-if="paidGames.length" class="text-[13px] text-white/50 mb-6 max-w-[640px] leading-relaxed">
                    Игры с лицензией по подписке лучше забронировать заранее вместе с местом — так доступ
                    к аккаунту гарантированно останется за вами.
                </p>

                <div v-if="paidGames.length" class="grid gap-3 grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 mb-8">
                    <Link v-for="game in paidGames" :key="game.id" :href="bookingUrl()"
                          class="group bg-white/5 md:bg-[#0a0a0a] border border-white/10 rounded-2xl overflow-hidden hover:border-[#22c55e]/40 transition-colors">
                        <div class="aspect-[3/4] bg-white/5 overflow-hidden">
                            <img v-if="posterUrl(game.poster)" :src="posterUrl(game.poster) || ''" :alt="game.title"
                                 loading="lazy" decoding="async"
                                 class="w-full h-full object-cover opacity-80 group-hover:opacity-100 transition-opacity" />
                            <div v-else class="w-full h-full flex items-center justify-center text-white/20 text-[10px] uppercase tracking-widest">
                                нет постера
                            </div>
                        </div>
                        <div class="p-3">
                            <div class="text-[12px] font-bold text-white truncate">{{ game.title }}</div>
                            <div class="text-[11px] text-[#22c55e] mt-0.5">{{ gamePriceLabel(game) }}</div>
                        </div>
                    </Link>
                </div>

                <div v-if="freeGames.length">
                    <div class="label mb-3">Доступны без доплаты</div>
                    <div class="flex flex-wrap gap-2">
                        <span v-for="game in freeGamesShown" :key="game.id"
                              class="px-3 py-1.5 rounded-lg bg-white/5 border border-white/10 text-[12px] text-white/60">
                            {{ game.title }}
                        </span>
                        <span v-if="freeGamesRest"
                              class="px-3 py-1.5 rounded-lg text-[12px] text-white/30">
                            и ещё {{ freeGamesRest }}
                        </span>
                    </div>
                </div>
            </section>

            <!-- ОТЗЫВЫ С ЯНДЕКС.КАРТ -->
            <section v-if="reviews.length" class="mb-16">
                <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4 mb-6">
                    <h3 class="text-xl sm:text-2xl font-black italic uppercase tracking-tight text-white">
                        Отзывы гостей
                    </h3>
                    <a v-if="reviews_map_url" :href="reviews_map_url" target="_blank" rel="noopener"
                       class="text-[11px] font-black uppercase tracking-[0.2em] text-[#22c55e] hover:text-[#2ae06d] transition-colors italic">
                        Все на Яндекс.Картах →
                    </a>
                </div>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <article v-for="review in reviews" :key="review.id"
                             class="bg-white/5 md:bg-[#0a0a0a] border border-white/10 rounded-3xl p-6 flex flex-col">
                        <div class="flex items-center justify-between gap-3 mb-4">
                            <div class="text-sm font-black uppercase italic text-white tracking-tight truncate">{{ review.author }}</div>
                            <div class="text-yellow-500 text-xs font-black shrink-0">{{ '★'.repeat(Math.min(5, Math.round(review.rating || 0))) }}</div>
                        </div>
                        <p class="text-[13px] text-white/55 leading-relaxed line-clamp-6 flex-1">{{ review.text }}</p>
                    </article>
                </div>
            </section>

            <!-- КАК ЭТО РАБОТАЕТ -->
            <section class="mb-4">
                <h3 class="text-xl sm:text-2xl font-black italic uppercase tracking-tight text-white mb-6">
                    Как забронировать
                </h3>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div v-for="step in steps" :key="step.num"
                         class="bg-white/5 md:bg-[#0a0a0a] border border-white/10 rounded-3xl p-6">
                        <div class="text-[#22c55e] font-black italic text-lg mb-3">{{ step.num }}</div>
                        <h4 class="text-white font-bold text-sm mb-2">{{ step.title }}</h4>
                        <p class="text-[13px] text-white/50 leading-relaxed">{{ step.text }}</p>
                    </div>
                </div>

                <div class="mt-6 flex flex-wrap items-center gap-4">
                    <Link :href="bookingUrl()"
                          class="px-7 py-4 rounded-2xl bg-[#22c55e] text-black font-black uppercase text-[11px] tracking-[0.2em] hover:bg-[#2ae06d] transition-colors">
                        Перейти к бронированию
                    </Link>
                    <span v-if="minAge" class="text-[12px] text-white/40">
                        Посетителям младше {{ minAge }} лет — только с согласия родителей.
                    </span>
                </div>
            </section>

            <SiteFooter :contacts="contacts" />
        </div>

        <ZoneInfoModal :isOpen="showInfoModal" :room="selectedRoomInfo" @close="showInfoModal = false" />
    </MainLayout>
</template>

<style scoped>
@reference "../../../css/app.css";

.label {
    @apply text-[10px] uppercase tracking-[0.25em] text-white/30 mb-1.5 font-black italic;
}

.build-card {
    background:
        radial-gradient(circle at 88% 12%, rgba(245, 158, 11, 0.16), transparent 42%),
        linear-gradient(160deg, #14100a 0%, #0a0a0a 55%);
}

.build-card__grid {
    background-image:
        linear-gradient(rgba(245, 158, 11, 0.05) 1px, transparent 1px),
        linear-gradient(90deg, rgba(245, 158, 11, 0.05) 1px, transparent 1px);
    background-size: 22px 22px;
    mask-image: linear-gradient(180deg, rgba(0, 0, 0, 0.55), transparent 85%);
}

.gpu-glow {
    filter: drop-shadow(0 0 8px rgba(245, 158, 11, 0.85));
    animation: gpu-pulse 2.6s ease-in-out infinite;
}

.fan-spin {
    transform-origin: 90px 154px;
    animation: fan-spin 9s linear infinite;
}

@keyframes gpu-pulse {
    0%, 100% { filter: drop-shadow(0 0 5px rgba(245, 158, 11, 0.45)); }
    50% { filter: drop-shadow(0 0 14px rgba(251, 191, 36, 0.95)); }
}

@keyframes fan-spin {
    to { transform: rotate(360deg); }
}
</style>
