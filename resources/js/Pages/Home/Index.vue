<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'

import MainLayout from '@/Layouts/MainLayout.vue'
import ClubMap from '@/Components/ClubMap.vue'
import SiteFooter from '@/Components/SiteFooter.vue'

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
}>(), {
    zones: () => [],
    games: () => [],
    minAge: 0,
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
    <Head title="Киберспортивный клуб: бронирование мест" />

    <MainLayout>
        <div class="w-full max-w-[1400px]">

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

                <div v-if="hasSeats"
                     class="bg-[#0a0a0a] border border-white/10 rounded-3xl p-7 flex flex-col justify-center">
                    <div class="flex items-center gap-2 mb-5">
                        <span class="w-1.5 h-1.5 rounded-full bg-[#22c55e] animate-pulse"></span>
                        <span class="label !mb-0">Свободно прямо сейчас</span>
                    </div>

                    <div class="flex items-baseline gap-3">
                        <span class="text-6xl sm:text-7xl font-black italic tracking-tighter text-white leading-none">
                            {{ occupancy?.free }}
                        </span>
                        <span class="text-white/40 text-sm">из {{ occupancy?.total }} мест</span>
                    </div>

                    <div class="mt-5 h-1.5 w-full rounded-full bg-white/10 overflow-hidden">
                        <div class="h-full rounded-full bg-[#22c55e] transition-all duration-700"
                             :style="{ width: `${freePercent}%` }"></div>
                    </div>

                    <div v-if="kindSummary.length" class="mt-6 space-y-2">
                        <div v-for="k in kindSummary" :key="k.kind"
                             class="flex items-center justify-between text-[12px] border-b border-white/5 pb-2 last:border-0">
                            <span class="text-white/50">{{ k.label }}</span>
                            <span class="font-mono text-white/80">{{ k.free }} / {{ k.total }}</span>
                        </div>
                    </div>
                </div>
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
                         class="bg-[#0a0a0a] border border-white/10 rounded-3xl p-6 flex flex-col hover:border-white/25 transition-colors">
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
                         class="bg-[#0a0a0a] border border-white/10 rounded-3xl p-6 flex flex-col hover:border-white/25 transition-colors">
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
            <section v-if="map?.computers?.length" class="mb-16">
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
                          class="group bg-[#0a0a0a] border border-white/10 rounded-2xl overflow-hidden hover:border-[#22c55e]/40 transition-colors">
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

            <!-- КАК ЭТО РАБОТАЕТ -->
            <section class="mb-4">
                <h3 class="text-xl sm:text-2xl font-black italic uppercase tracking-tight text-white mb-6">
                    Как забронировать
                </h3>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div v-for="step in steps" :key="step.num"
                         class="bg-[#0a0a0a] border border-white/10 rounded-3xl p-6">
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
    </MainLayout>
</template>

<style scoped>
@reference "../../../css/app.css";

.label {
    @apply text-[10px] uppercase tracking-[0.25em] text-white/30 mb-1.5 font-black italic;
}
</style>
