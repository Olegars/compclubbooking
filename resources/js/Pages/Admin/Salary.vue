<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Head, router, useForm, usePage } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminConfirm from '@/Components/AdminConfirm.vue'
import { useClubName } from '@/Composables/useClubName'
import { useToast } from '@/Composables/useToast'

type ShiftRow = {
    id: number
    started_at: string | null
    ended_at: string | null
    status: string
    is_open: boolean
    duration_minutes: number | null
    accrued: number
}

type LedgerRow = {
    id: number
    amount: number
    reason: string | null
    created_at: string | null
    author?: string | null
}

type SlotRow = {
    id: number
    name: string
    starts_at: string
    ends_at: string
    lead_taken: boolean
    lead_name?: string | null
    intern_taken: number
    intern_capacity: number
    is_mine: boolean
    my_kind?: string | null
    booking_id?: number | null
    can_book: boolean
    can_cancel: boolean
    started: boolean
}

type DayRow = {
    date: string
    has_free: boolean
    slots: SlotRow[]
}

type MyBooking = {
    id: number
    slot_id: number
    name: string
    starts_at: string | null
    ends_at: string | null
    kind: string
    can_cancel: boolean
}

type Calendar = {
    month: string
    cancel_before_hours: number
    days: Record<string, DayRow>
    my_bookings: MyBooking[]
}

const props = withDefaults(defineProps<{
    pay_type: 'shift' | 'monthly' | null
    base_rate: number | null
    accrued_total: number
    fines_total: number
    payouts_total: number
    balance: number
    available: number
    shifts: ShiftRow[]
    fines: LedgerRow[]
    payouts: LedgerRow[]
    monthly_accruals: LedgerRow[]
    calendar?: Calendar
}>(), {
    calendar: () => ({
        month: '',
        cancel_before_hours: 48,
        days: {},
        my_bookings: [],
    }),
})

const clubName = useClubName()
const page = usePage()
const { success, error } = useToast()

const flashSuccess = computed(() => (page.props as any).flash?.success as string | undefined)
const formErrors = computed(() => (page.props as any).errors as Record<string, string> | undefined)

watch(flashSuccess, (msg) => {
    if (msg) success(msg)
}, { immediate: true })
watch(() => formErrors.value?.amount, (msg) => {
    if (msg) error(msg)
}, { immediate: true })
watch(() => formErrors.value?.message, (msg) => {
    if (msg) error(msg)
}, { immediate: true })
watch(() => (page.props as any).flash?.error as string | undefined, (msg) => {
    if (msg) error(msg)
}, { immediate: true })

const shiftState = computed(() => page.props.admin_shift as any)
const dutyBusy = ref(false)
const slotBusy = ref(false)
const selectedDate = ref('')
const confirmOpen = ref(false)
const cancelConfirmOpen = ref(false)
const pendingCancelId = ref<number | null>(null)
const processing = ref(false)

const weekDays = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс']

const localToday = () => {
    const now = new Date()
    const y = now.getFullYear()
    const m = String(now.getMonth() + 1).padStart(2, '0')
    const d = String(now.getDate()).padStart(2, '0')
    return `${y}-${m}-${d}`
}

const pickDefaultDate = (cal: Calendar) => {
    const today = localToday()
    if (cal.days[today]) return today
    const free = Object.values(cal.days).find((day) => day.has_free)
    if (free) return free.date
    return Object.keys(cal.days)[0] || ''
}

watch(() => props.calendar, (cal) => {
    if (selectedDate.value && cal.days[selectedDate.value]) return
    selectedDate.value = pickDefaultDate(cal)
}, { immediate: true })

const monthLabel = computed(() => {
    if (!props.calendar.month) return ''
    const [y, m] = props.calendar.month.split('-').map(Number)
    return new Date(y, m - 1, 1).toLocaleDateString('ru-RU', { month: 'long', year: 'numeric' })
})

const monthGrid = computed(() => {
    const cal = props.calendar
    if (!cal.month) return [] as Array<DayRow | null>
    const [y, m] = cal.month.split('-').map(Number)
    const pad = (new Date(y, m - 1, 1).getDay() + 6) % 7
    const cells: Array<DayRow | null> = Array.from({ length: pad }, () => null)
    Object.keys(cal.days).sort().forEach((date) => cells.push(cal.days[date]))
    while (cells.length % 7 !== 0) cells.push(null)
    return cells
})

const selectedDay = computed(() => props.calendar.days[selectedDate.value] || null)

const goMonth = (delta: number) => {
    if (!props.calendar.month) return
    const [y, m] = props.calendar.month.split('-').map(Number)
    const next = new Date(y, m - 1 + delta, 1)
    const month = `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, '0')}`
    router.get('/admin/salary', { month }, { preserveScroll: true, preserveState: false })
}

const takeShift = () => {
    router.get('/admin/shifts/transfer')
}

const internJoin = () => {
    if (dutyBusy.value) return
    dutyBusy.value = true
    router.post('/admin/shifts/intern/join', {}, {
        preserveScroll: true,
        onFinish: () => { dutyBusy.value = false },
    })
}

const internLeave = () => {
    if (dutyBusy.value) return
    dutyBusy.value = true
    router.post('/admin/shifts/intern/leave', {}, {
        preserveScroll: true,
        onFinish: () => { dutyBusy.value = false },
    })
}

const bookSlot = (slot: SlotRow) => {
    if (slotBusy.value || !slot.can_book) return
    slotBusy.value = true
    router.post(`/admin/salary/slots/${slot.id}/book`, {}, {
        preserveScroll: true,
        onFinish: () => { slotBusy.value = false },
    })
}

const askCancel = (id: number) => {
    pendingCancelId.value = id
    cancelConfirmOpen.value = true
}

const confirmCancel = () => {
    if (slotBusy.value || !pendingCancelId.value) return
    slotBusy.value = true
    router.post(`/admin/salary/slots/${pendingCancelId.value}/cancel`, {}, {
        preserveScroll: true,
        onFinish: () => {
            slotBusy.value = false
            cancelConfirmOpen.value = false
            pendingCancelId.value = null
        },
    })
}

const formatMoney = (value: number | string | null | undefined) => {
    return Number(value ?? 0).toLocaleString('ru-RU', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }) + ' ₽'
}

const formatDate = (dateString: string | null | undefined) => {
    if (!dateString) return '—'
    return new Date(dateString).toLocaleString('ru-RU', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    })
}

const formatHm = (dateString: string | null | undefined) => {
    if (!dateString) return '—'
    return new Date(dateString).toLocaleTimeString('ru-RU', {
        hour: '2-digit',
        minute: '2-digit',
    })
}

const formatRange = (start: string | null | undefined, end: string | null | undefined) => {
    if (!start || !end) return '—'
    const s = new Date(start)
    const e = new Date(end)
    const sameDay = s.toDateString() === e.toDateString()
    if (sameDay) return `${formatHm(start)}–${formatHm(end)}`
    return `${formatDate(start)} — ${formatDate(end)}`
}

const formatDayLabel = (date: string) => {
    const [y, m, d] = date.split('-').map(Number)
    return new Date(y, m - 1, d).toLocaleDateString('ru-RU', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    })
}

const formatDuration = (minutes: number | null | undefined) => {
    if (minutes == null) return '—'
    const h = Math.floor(minutes / 60)
    const m = minutes % 60
    if (h <= 0) return `${m} мин`
    if (m === 0) return `${h} ч`
    return `${h} ч ${m} мин`
}

const payTypeLabel = computed(() => {
    if (props.pay_type === 'shift') return 'За смену'
    if (props.pay_type === 'monthly') return 'Оклад'
    return 'Ставка не задана'
})

const withdrawForm = useForm({
    amount: Number(props.available || 0).toFixed(2),
})

watch(() => props.available, (next) => {
    withdrawForm.amount = Number(next || 0).toFixed(2)
})

const canWithdraw = computed(() => Number(props.available) > 0 && Number(withdrawForm.amount) > 0)

const openWithdraw = () => {
    if (!canWithdraw.value || withdrawForm.processing) return
    confirmOpen.value = true
}

const confirmWithdraw = () => {
    if (processing.value) return
    processing.value = true
    withdrawForm.post('/admin/salary/withdraw', {
        preserveScroll: true,
        onFinish: () => {
            processing.value = false
            confirmOpen.value = false
        },
    })
}

const kindLabel = (kind: string | null | undefined) => kind === 'intern' ? 'стажёр' : 'админ'
</script>

<template>
    <Head :title="`${clubName} | Личный кабинет`" />
    <AdminLayout>
        <div class="max-w-7xl mx-auto space-y-8 animate-in fade-in duration-500 font-mono pb-20 px-4">

            <div class="flex justify-between items-end mb-4 border-b border-white/10 pb-6">
                <div>
                    <h1 class="text-3xl font-black uppercase italic text-white tracking-tighter">
                        Личный <span class="text-[#22c55e]">кабинет</span>
                    </h1>
                    <p class="text-white/20 text-[10px] uppercase tracking-[0.4em] font-black mt-2 italic">
                        Смены, начисления, штрафы и вывод
                    </p>
                </div>
                <div class="text-right">
                    <div class="text-[10px] uppercase font-black tracking-widest text-white/30">Статус</div>
                    <div class="text-sm font-black uppercase text-white mt-1">{{ shiftState?.duty_label || payTypeLabel }}</div>
                    <div class="text-[11px] text-white/40 mt-1">ставка {{ formatMoney(base_rate) }}</div>
                </div>
            </div>

            <div v-if="shiftState?.duty === 'intern' && !shiftState?.id"
                 class="bg-[#0a0a0a] border border-white/5 rounded-[1.125rem] p-8 text-sm text-white/60 font-bold">
                Нет активной смены. Стажёр выходит в смену вместе с активным админом, когда тот её примет.
            </div>

            <div v-if="shiftState?.can_take_shift || shiftState?.can_join_as_intern || shiftState?.can_leave_as_intern"
                 class="bg-[#0a0a0a] border border-white/5 rounded-[1.125rem] p-8 shadow-2xl flex flex-col md:flex-row md:items-center gap-6">
                <div class="flex-1">
                    <div class="text-[10px] text-white/30 uppercase font-black tracking-widest">Смена сейчас</div>
                    <p v-if="shiftState?.can_take_shift" class="text-white text-sm font-bold mt-2">
                        Чтобы получить доступ к админке, примите текущую смену.
                    </p>
                    <p v-else-if="shiftState?.can_join_as_intern" class="text-white text-sm font-bold mt-2">
                        Активный админ: {{ shiftState.admin_name }}. Выйдите в смену вместе с ним.
                    </p>
                    <p v-else class="text-white text-sm font-bold mt-2">
                        Вы на смене вместе с {{ shiftState.admin_name }}.
                    </p>
                </div>
                <button v-if="shiftState?.can_take_shift" type="button" @click="takeShift"
                        class="px-8 py-4 bg-[#22c55e] hover:bg-[#1ea34d] text-black rounded-2xl text-xs font-black uppercase tracking-widest">
                    Принять смену
                </button>
                <button v-else-if="shiftState?.can_join_as_intern" type="button" :disabled="dutyBusy" @click="internJoin"
                        class="px-8 py-4 bg-amber-500 hover:bg-amber-400 text-black rounded-2xl text-xs font-black uppercase tracking-widest disabled:opacity-40">
                    Выйти в смену
                </button>
                <button v-else-if="shiftState?.can_leave_as_intern" type="button" :disabled="dutyBusy" @click="internLeave"
                        class="px-8 py-4 border border-white/15 text-white/70 hover:text-white rounded-2xl text-xs font-black uppercase tracking-widest disabled:opacity-40">
                    Уйти со смены
                </button>
            </div>

            <div class="bg-[#0a0a0a] border border-white/5 rounded-[1.125rem] p-8 shadow-2xl space-y-8">
                <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4">
                    <div>
                        <h2 class="text-sm font-black uppercase italic tracking-widest text-white">Выбрать смену</h2>
                        <p class="text-white/40 text-xs font-bold mt-2">
                            Свободный слот можно взять, если место есть. Отмена — не позднее чем за {{ calendar.cancel_before_hours }} часов до начала.
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        <button type="button" @click="goMonth(-1)"
                                class="px-4 py-3 border border-white/10 rounded-2xl text-white/60 hover:text-white text-xs font-black uppercase tracking-widest">
                            ←
                        </button>
                        <div class="min-w-[10rem] text-center text-sm font-black uppercase italic text-white">
                            {{ monthLabel }}
                        </div>
                        <button type="button" @click="goMonth(1)"
                                class="px-4 py-3 border border-white/10 rounded-2xl text-white/60 hover:text-white text-xs font-black uppercase tracking-widest">
                            →
                        </button>
                    </div>
                </div>

                <div v-if="calendar.my_bookings.length" class="grid gap-3">
                    <div class="text-[10px] text-white/30 uppercase font-black tracking-widest">Мои слоты</div>
                    <div v-for="row in calendar.my_bookings" :key="row.id"
                         class="flex flex-col md:flex-row md:items-center gap-3 bg-black/40 border border-white/10 rounded-2xl px-5 py-4">
                        <div class="flex-1">
                            <div class="text-white text-sm font-black uppercase italic">{{ row.name }} · {{ kindLabel(row.kind) }}</div>
                            <div class="text-white/50 text-xs mt-1">{{ formatRange(row.starts_at, row.ends_at) }}</div>
                        </div>
                        <button v-if="row.can_cancel" type="button" :disabled="slotBusy" @click="askCancel(row.id)"
                                class="px-5 py-3 border border-white/15 text-white/70 hover:text-white rounded-xl text-[10px] font-black uppercase tracking-widest disabled:opacity-40">
                            Отменить
                        </button>
                        <div v-else class="text-[10px] uppercase font-black tracking-widest text-white/30">
                            Отмена недоступна
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-7 gap-2">
                    <div v-for="label in weekDays" :key="label"
                         class="text-center text-[10px] uppercase font-black tracking-widest text-white/30 py-1">
                        {{ label }}
                    </div>
                    <button v-for="(day, idx) in monthGrid" :key="day?.date || `empty-${idx}`"
                            type="button"
                            :disabled="!day"
                            class="min-h-[4.5rem] rounded-2xl border p-2 text-left transition-all disabled:border-transparent disabled:bg-transparent"
                            :class="!day
                                ? ''
                                : day.date === selectedDate
                                    ? 'border-[#22c55e]/50 bg-[#22c55e]/10'
                                    : day.has_free
                                        ? 'border-white/10 bg-white/[0.03] hover:border-[#22c55e]/30'
                                        : 'border-white/5 bg-black/20 opacity-60'"
                            @click="day && (selectedDate = day.date)">
                        <template v-if="day">
                            <div class="text-sm font-black" :class="day.date === localToday() ? 'text-[#22c55e]' : 'text-white'">
                                {{ Number(day.date.slice(-2)) }}
                            </div>
                            <div class="mt-1 text-[9px] uppercase font-black tracking-widest"
                                 :class="day.has_free ? 'text-[#22c55e]' : 'text-white/25'">
                                {{ day.has_free ? 'есть слот' : (day.slots.length ? 'занято' : '—') }}
                            </div>
                        </template>
                    </button>
                </div>

                <div v-if="selectedDay">
                    <div class="text-[10px] text-white/30 uppercase font-black tracking-widest mb-4">
                        {{ formatDayLabel(selectedDay.date) }}
                    </div>
                    <div v-if="selectedDay.slots.length === 0" class="text-white/40 text-sm font-bold">
                        На этот день слотов нет.
                    </div>
                    <div v-else class="grid gap-3">
                        <div v-for="slot in selectedDay.slots" :key="slot.id"
                             class="flex flex-col lg:flex-row lg:items-center gap-4 bg-black/40 border border-white/10 rounded-2xl px-5 py-5">
                            <div class="flex-1">
                                <div class="text-white text-sm font-black uppercase italic tracking-wider">{{ slot.name }}</div>
                                <div class="text-white/50 text-xs mt-1">{{ formatRange(slot.starts_at, slot.ends_at) }}</div>
                                <div class="text-[10px] uppercase font-black tracking-widest mt-2"
                                     :class="slot.is_mine ? 'text-[#22c55e]' : slot.can_book ? 'text-white/50' : 'text-white/30'">
                                    <span v-if="slot.is_mine">Вы записаны ({{ kindLabel(slot.my_kind) }})</span>
                                    <span v-else-if="slot.started">Смена уже началась</span>
                                    <span v-else>
                                        Админ: {{ slot.lead_taken ? (slot.lead_name || 'занято') : 'свободно' }}
                                        · стажёр {{ slot.intern_taken }}/{{ slot.intern_capacity }}
                                    </span>
                                </div>
                            </div>
                            <button v-if="slot.can_book" type="button" :disabled="slotBusy" @click="bookSlot(slot)"
                                    class="px-8 py-4 bg-[#22c55e] hover:bg-[#1ea34d] text-black rounded-2xl text-xs font-black uppercase tracking-widest disabled:opacity-40">
                                Выбрать смену
                            </button>
                            <button v-else-if="slot.can_cancel && slot.booking_id" type="button" :disabled="slotBusy"
                                    @click="askCancel(slot.booking_id)"
                                    class="px-8 py-4 border border-white/15 text-white/70 hover:text-white rounded-2xl text-xs font-black uppercase tracking-widest disabled:opacity-40">
                                Отменить
                            </button>
                            <div v-else class="text-[10px] uppercase font-black tracking-widest text-white/25">
                                {{ slot.is_mine ? 'Отмена только за 48 часов' : 'Слот занят' }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-6">
                <div class="bg-[#050505] border border-white/5 p-8 rounded-[0.875rem] shadow-xl">
                    <div class="text-[10px] text-white/30 uppercase font-black tracking-widest mb-2">Начислено</div>
                    <div class="text-3xl font-black text-white tracking-tighter">{{ formatMoney(accrued_total) }}</div>
                </div>
                <div class="bg-[#050505] border border-red-500/20 p-8 rounded-[0.875rem] shadow-xl">
                    <div class="text-[10px] text-red-400 uppercase font-black tracking-widest mb-2">Штрафы</div>
                    <div class="text-3xl font-black text-red-400 tracking-tighter">{{ formatMoney(fines_total) }}</div>
                </div>
                <div class="bg-[#050505] border border-white/5 p-8 rounded-[0.875rem] shadow-xl">
                    <div class="text-[10px] text-white/30 uppercase font-black tracking-widest mb-2">Уже выведено</div>
                    <div class="text-3xl font-black text-white/70 tracking-tighter">{{ formatMoney(payouts_total) }}</div>
                </div>
                <div class="bg-[#22c55e] p-8 rounded-[0.875rem] shadow-[0_0_40px_rgba(34,197,94,0.25)] text-black">
                    <div class="text-[10px] uppercase font-black tracking-widest mb-2 opacity-70">К выводу</div>
                    <div class="text-3xl font-black tracking-tighter">{{ formatMoney(available) }}</div>
                    <div v-if="balance < 0" class="text-[10px] font-black uppercase mt-2 opacity-70">
                        Штрафы больше начислений
                    </div>
                </div>
            </div>

            <div class="bg-[#0a0a0a] border border-white/5 rounded-[1.125rem] p-8 shadow-2xl flex flex-col md:flex-row md:items-end gap-6">
                <div class="flex-1">
                    <label class="text-[10px] text-white/30 uppercase font-black tracking-widest">Сумма вывода</label>
                    <input v-model="withdrawForm.amount"
                           type="number"
                           min="0.01"
                           step="0.01"
                           :max="available"
                           :disabled="available <= 0 || withdrawForm.processing"
                           class="mt-3 w-full bg-black/40 border border-white/10 focus:border-[#22c55e]/50 rounded-2xl px-5 py-4 text-xl font-black text-white outline-none disabled:opacity-40">
                    <p v-if="withdrawForm.errors.amount" class="text-red-400 text-[10px] uppercase font-black mt-2">
                        {{ withdrawForm.errors.amount }}
                    </p>
                </div>
                <button type="button"
                        :disabled="!canWithdraw || withdrawForm.processing"
                        @click="openWithdraw"
                        class="px-10 py-5 bg-[#22c55e] hover:bg-[#1ea34d] text-black rounded-2xl text-xs font-black uppercase tracking-[0.2em] transition-all italic active:scale-95 disabled:opacity-30 disabled:cursor-not-allowed shadow-[0_0_25px_rgba(34,197,94,0.25)]">
                    Вывести
                </button>
            </div>

            <div v-if="monthly_accruals.length > 0" class="bg-[#050505] border border-white/5 rounded-[0.875rem] overflow-hidden shadow-xl">
                <div class="p-6 border-b border-white/10">
                    <h2 class="text-sm font-black uppercase italic tracking-widest text-white/70">Оклады</h2>
                </div>
                <table class="w-full text-left border-collapse">
                    <thead>
                    <tr class="border-b border-white/10 bg-white/[0.02]">
                        <th class="p-6 text-[10px] uppercase tracking-widest text-white/30 font-black">Период</th>
                        <th class="p-6 text-[10px] uppercase tracking-widest text-white/30 font-black text-right">Сумма</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr v-for="row in monthly_accruals" :key="row.id" class="border-b border-white/5">
                        <td class="p-6 text-xs text-white/70">{{ row.reason || formatDate(row.created_at) }}</td>
                        <td class="p-6 text-right text-[#22c55e] font-black">{{ formatMoney(row.amount) }}</td>
                    </tr>
                    </tbody>
                </table>
            </div>

            <div class="bg-[#050505] border border-white/5 rounded-[0.875rem] overflow-hidden shadow-xl">
                <div class="p-6 border-b border-white/10">
                    <h2 class="text-sm font-black uppercase italic tracking-widest text-white/70">Отработанные смены</h2>
                </div>
                <table class="w-full text-left border-collapse">
                    <thead>
                    <tr class="border-b border-white/10 bg-white/[0.02]">
                        <th class="p-6 text-[10px] uppercase tracking-widest text-white/30 font-black">Смена</th>
                        <th class="p-6 text-[10px] uppercase tracking-widest text-white/30 font-black">Пришёл</th>
                        <th class="p-6 text-[10px] uppercase tracking-widest text-white/30 font-black">Ушёл</th>
                        <th class="p-6 text-[10px] uppercase tracking-widest text-white/30 font-black">Длительность</th>
                        <th class="p-6 text-[10px] uppercase tracking-widest text-white/30 font-black text-right">Начислено</th>
                    </tr>
                    </thead>
                    <tbody v-if="shifts.length > 0">
                    <tr v-for="shift in shifts" :key="shift.id" class="border-b border-white/5 hover:bg-white/[0.02] transition-colors">
                        <td class="p-6 text-xs text-white font-black italic tracking-wider">#{{ shift.id }}</td>
                        <td class="p-6 text-xs text-white/70">{{ formatDate(shift.started_at) }}</td>
                        <td class="p-6 text-xs">
                            <span v-if="shift.ended_at" class="text-white/70">{{ formatDate(shift.ended_at) }}</span>
                            <span v-else class="text-[#22c55e] text-[10px] uppercase font-black tracking-widest animate-pulse">На смене</span>
                        </td>
                        <td class="p-6 text-xs text-white/50">{{ formatDuration(shift.duration_minutes) }}</td>
                        <td class="p-6 text-right font-black" :class="shift.accrued > 0 ? 'text-[#22c55e]' : 'text-white/30'">
                            {{ shift.is_open ? 'после закрытия' : formatMoney(shift.accrued) }}
                        </td>
                    </tr>
                    </tbody>
                    <tbody v-else>
                    <tr>
                        <td colspan="5" class="py-16 text-center">
                            <div class="text-white/10 text-xl font-black uppercase tracking-widest italic mb-2">Нет смен</div>
                            <div class="text-white/30 text-[10px] uppercase tracking-widest">Смены появятся после пересменки</div>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>

            <div class="bg-[#050505] border border-white/5 rounded-[0.875rem] overflow-hidden shadow-xl">
                <div class="p-6 border-b border-white/10">
                    <h2 class="text-sm font-black uppercase italic tracking-widest text-white/70">Штрафы</h2>
                </div>
                <table class="w-full text-left border-collapse">
                    <thead>
                    <tr class="border-b border-white/10 bg-white/[0.02]">
                        <th class="p-6 text-[10px] uppercase tracking-widest text-white/30 font-black">Когда</th>
                        <th class="p-6 text-[10px] uppercase tracking-widest text-white/30 font-black">За что</th>
                        <th class="p-6 text-[10px] uppercase tracking-widest text-white/30 font-black">Кто выписал</th>
                        <th class="p-6 text-[10px] uppercase tracking-widest text-white/30 font-black text-right">Сумма</th>
                    </tr>
                    </thead>
                    <tbody v-if="fines.length > 0">
                    <tr v-for="fine in fines" :key="fine.id" class="border-b border-white/5 hover:bg-white/[0.02] transition-colors">
                        <td class="p-6 text-xs text-white/50">{{ formatDate(fine.created_at) }}</td>
                        <td class="p-6 text-sm text-white font-bold uppercase tracking-tight">{{ fine.reason || '—' }}</td>
                        <td class="p-6 text-xs text-white/50">{{ fine.author || '—' }}</td>
                        <td class="p-6 text-right text-red-400 font-black">− {{ formatMoney(fine.amount) }}</td>
                    </tr>
                    </tbody>
                    <tbody v-else>
                    <tr>
                        <td colspan="4" class="py-16 text-center">
                            <div class="text-white/10 text-xl font-black uppercase tracking-widest italic mb-2">Штрафов нет</div>
                            <div class="text-white/30 text-[10px] uppercase tracking-widest">Удержания появятся здесь с причиной</div>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="payouts.length > 0" class="bg-[#050505] border border-white/5 rounded-[0.875rem] overflow-hidden shadow-xl">
                <div class="p-6 border-b border-white/10">
                    <h2 class="text-sm font-black uppercase italic tracking-widest text-white/70">История выводов</h2>
                </div>
                <table class="w-full text-left border-collapse">
                    <thead>
                    <tr class="border-b border-white/10 bg-white/[0.02]">
                        <th class="p-6 text-[10px] uppercase tracking-widest text-white/30 font-black">Когда</th>
                        <th class="p-6 text-[10px] uppercase tracking-widest text-white/30 font-black text-right">Сумма</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr v-for="row in payouts" :key="row.id" class="border-b border-white/5">
                        <td class="p-6 text-xs text-white/50">{{ formatDate(row.created_at) }}</td>
                        <td class="p-6 text-right text-white font-black">{{ formatMoney(row.amount) }}</td>
                    </tr>
                    </tbody>
                </table>
            </div>

        </div>

        <AdminConfirm
            :is-open="confirmOpen"
            tone="primary"
            title="Вывести зарплату"
            :message="`Списать ${formatMoney(Number(withdrawForm.amount || 0))} с баланса к выводу?`"
            confirm-text="Вывести"
            cancel-text="Отмена"
            :is-processing="processing || withdrawForm.processing"
            @close="confirmOpen = false"
            @confirm="confirmWithdraw"
        />

        <AdminConfirm
            :is-open="cancelConfirmOpen"
            title="Отменить смену"
            message="Слот снова станет свободным. Отменить можно только не позднее чем за 48 часов до начала."
            confirm-text="Отменить смену"
            cancel-text="Назад"
            :is-processing="slotBusy"
            @close="cancelConfirmOpen = false; pendingCancelId = null"
            @confirm="confirmCancel"
        />
    </AdminLayout>
</template>
