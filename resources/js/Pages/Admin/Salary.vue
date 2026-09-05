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

const props = defineProps<{
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
}>()

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

const confirmOpen = ref(false)
const processing = ref(false)

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
</script>

<template>
    <Head :title="`${clubName} | Моя зарплата`" />
    <AdminLayout>
        <div class="max-w-7xl mx-auto space-y-8 animate-in fade-in duration-500 font-mono pb-20 px-4">

            <div class="flex justify-between items-end mb-4 border-b border-white/10 pb-6">
                <div>
                    <h1 class="text-3xl font-black uppercase italic text-white tracking-tighter">
                        My <span class="text-[#22c55e]">Payroll</span>
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
                    <div class="text-[10px] text-white/30 uppercase font-black tracking-widest">Смена</div>
                    <p v-if="shiftState?.can_take_shift" class="text-white text-sm font-bold mt-2">
                        Чтобы получить доступ к админке, примите смену.
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
    </AdminLayout>
</template>
