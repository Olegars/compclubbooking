<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useClubName } from '@/Composables/useClubName'

type LocationRow = {
    id: number
    name: string
    type: string | null
}

type ShiftCard = {
    id: number
    status: string | null
    admin_name: string | null
    started_at: string | null
    interns: string[]
} | null

type Alerts = {
    pending_orders: number
    sos: number
    input: number
    incidents: number
    avito_unread: number
}

const props = defineProps<{
    location: LocationRow | null
    locations: LocationRow[]
    shift: ShiftCard
    alerts: Alerts
    pending_hires: number
    staff_count: number
    store_open_orders: number
    today: {
        taxable: number
        new_guests: number
        bar_pending: number
    }
}>()

const clubName = useClubName()
const page = usePage()
const isBossApp = /CompClubBoss/i.test(navigator.userAgent || '')
const ownerName = computed(() => (page.props as any).admin_user?.name || 'Владелец')

const money = (value: number | null | undefined) =>
    Number(value ?? 0).toLocaleString('ru-RU', { maximumFractionDigits: 0 }) + ' ₽'

const typeLabel = (type: string | null | undefined) => {
    if (type === 'store') return 'магазин'
    if (type === 'both') return 'клуб + магазин'
    return 'клуб'
}

const shiftStatusLabel = (status: string | null | undefined) => {
    if (status === 'transferring') return 'передача смены'
    if (status === 'open') return 'открыта'
    return status || '—'
}

const formatWhen = (iso: string | null | undefined) => {
    if (!iso) return ''
    const d = new Date(iso)
    if (Number.isNaN(d.getTime())) return ''
    return d.toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
}

const links = [
    { href: '/admin/dashboard', label: 'Дашборд зала', hint: 'ПК, касса, гости' },
    { href: '/admin/staff', label: 'Штат', hint: 'Найм, ставки, штрафы' },
    { href: '/admin/analytics', label: 'Аналитика', hint: 'Загрузка и выручка' },
    { href: '/admin/taxes', label: 'Налоги', hint: 'УСН 6% · НДС · штат' },
    { href: '/admin/store/locations', label: 'Локации', hint: 'Клубы и магазины' },
    { href: '/admin/store/orders', label: 'Заказы магазина', hint: 'Сборки и выдача' },
    { href: '/admin/incidents', label: 'Инциденты', hint: 'SOS и качество' },
    { href: '/admin/system-tests', label: 'Тесты системы', hint: 'Кнопки проверок и PHPUnit' },
    { href: '/admin/docs', label: 'О системе', hint: 'Справка владельца' },
]
</script>

<template>
    <Head :title="`${clubName} | Кабинет владельца`" />
    <AdminLayout>
        <div class="max-w-7xl mx-auto space-y-8 animate-in fade-in duration-500 font-mono pb-20 px-4">

            <a
                v-if="!isBossApp"
                href="/boss-app.apk"
                download="boss0451.apk"
                class="flex items-center justify-between gap-4 bg-[#0a0a0a] border border-yellow-500/30 rounded-[1.125rem] px-6 py-5 hover:bg-yellow-500/10 transition-colors"
            >
                <div>
                    <div class="text-[10px] text-white/30 uppercase font-black tracking-widest">Android</div>
                    <div class="text-white text-sm font-black uppercase tracking-wide mt-1">Скачать приложение владельца</div>
                    <p class="text-white/40 text-xs mt-1">0451 Boss — вся админка клуба и магазина, без сайта для гостей</p>
                </div>
                <span class="shrink-0 px-5 py-3 bg-yellow-500 text-black rounded-2xl text-xs font-black uppercase tracking-widest">
                    Скачать APK
                </span>
            </a>

            <div class="flex justify-between items-end mb-4 border-b border-white/10 pb-6">
                <div>
                    <h1 class="text-3xl font-black uppercase italic text-white tracking-tighter">
                        Кабинет <span class="text-yellow-400">владельца</span>
                    </h1>
                    <p class="text-white/20 text-[10px] uppercase tracking-[0.4em] font-black mt-2 italic">
                        Сводка по клубу и магазину, без смен и зарплаты сотрудников
                    </p>
                </div>
                <div class="text-right">
                    <div class="text-[10px] uppercase font-black tracking-widest text-white/30">Владелец</div>
                    <div class="text-sm font-black uppercase text-white mt-1">{{ ownerName }}</div>
                    <div v-if="location" class="text-[11px] text-white/40 mt-1">
                        {{ location.name }} · {{ typeLabel(location.type) }}
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
                <div class="bg-[#0a0a0a] border border-white/5 rounded-[1.125rem] p-6">
                    <div class="text-[10px] text-white/30 uppercase font-black tracking-widest">Смена зала</div>
                    <div v-if="shift" class="text-white text-lg font-black uppercase italic mt-3">
                        {{ shift.admin_name || 'Админ' }}
                    </div>
                    <div v-else class="text-white/40 text-lg font-black uppercase italic mt-3">Нет смены</div>
                    <p class="text-white/40 text-xs font-bold mt-2">
                        <template v-if="shift">
                            {{ shiftStatusLabel(shift.status) }}
                            <span v-if="shift.started_at"> · с {{ formatWhen(shift.started_at) }}</span>
                            <span v-if="shift.interns.length"> · стажёр: {{ shift.interns.join(', ') }}</span>
                        </template>
                        <template v-else>Ресепшен закрыт</template>
                    </p>
                </div>

                <div class="bg-[#0a0a0a] border border-white/5 rounded-[1.125rem] p-6">
                    <div class="text-[10px] text-white/30 uppercase font-black tracking-widest">Доход сегодня</div>
                    <div class="text-yellow-400 text-lg font-black mt-3">{{ money(today.taxable) }}</div>
                    <p class="text-white/40 text-xs font-bold mt-2">
                        Пополнения с живым чеком · новые гости {{ today.new_guests }}
                    </p>
                </div>

                <div class="bg-[#0a0a0a] border border-white/5 rounded-[1.125rem] p-6">
                    <div class="text-[10px] text-white/30 uppercase font-black tracking-widest">Очереди</div>
                    <div class="text-white text-lg font-black mt-3">
                        {{ today.bar_pending }} / {{ store_open_orders }}
                    </div>
                    <p class="text-white/40 text-xs font-bold mt-2">Бар pending · магазин в работе</p>
                </div>

                <div class="bg-[#0a0a0a] border border-white/5 rounded-[1.125rem] p-6">
                    <div class="text-[10px] text-white/30 uppercase font-black tracking-widest">Риски</div>
                    <div class="text-lg font-black mt-3" :class="alerts.incidents > 0 ? 'text-red-400' : 'text-white'">
                        {{ alerts.incidents }}
                    </div>
                    <p class="text-white/40 text-xs font-bold mt-2">
                        Инциденты · Avito {{ alerts.avito_unread }} непрочит.
                    </p>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <Link href="/admin/staff"
                      class="bg-[#0a0a0a] border border-white/5 hover:border-yellow-500/30 rounded-[1.125rem] p-6 transition-colors">
                    <div class="text-[10px] text-white/30 uppercase font-black tracking-widest">Люди</div>
                    <div class="text-white text-lg font-black uppercase italic mt-3">
                        {{ staff_count }} в штате
                    </div>
                    <p class="text-white/40 text-xs font-bold mt-2">
                        На проверке: {{ pending_hires }}
                        <span v-if="pending_hires > 0" class="text-yellow-400"> · нужна проверка</span>
                    </p>
                </Link>

                <div class="bg-[#0a0a0a] border border-white/5 rounded-[1.125rem] p-6">
                    <div class="text-[10px] text-white/30 uppercase font-black tracking-widest">Локации</div>
                    <div class="text-white text-lg font-black uppercase italic mt-3">
                        {{ locations.length || 1 }}
                    </div>
                    <p class="text-white/40 text-xs font-bold mt-2">
                        {{ (locations.length ? locations : (location ? [location] : [])).map(row => row.name).join(' · ') || 'Нет карточек' }}
                    </p>
                </div>
            </div>

            <div class="bg-[#0a0a0a] border border-white/5 rounded-[1.125rem] p-8 space-y-6">
                <div>
                    <h2 class="text-sm font-black uppercase italic tracking-widest text-white">Управление</h2>
                    <p class="text-white/40 text-xs font-bold mt-2">
                        Это кабинет владельца. Смены, вывод зарплаты и устройство — у админа зала и сотрудника магазина в их кабинетах.
                    </p>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
                    <Link v-for="item in links" :key="item.href" :href="item.href"
                          class="bg-black/40 border border-white/10 hover:border-yellow-500/40 rounded-2xl px-5 py-4 transition-colors">
                        <div class="text-white text-sm font-black uppercase italic">{{ item.label }}</div>
                        <div class="text-white/40 text-xs mt-1">{{ item.hint }}</div>
                    </Link>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
