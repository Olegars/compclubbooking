<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useClubName } from '@/Composables/useClubName'
import { useToast } from '@/Composables/useToast'

type Alert = { id: string; tone: string; title: string; hint: string; status: string }
type EventRow = {
    id: number
    name: string | null
    event_label: string
    event_date: string | null
    order_number: string
    okz_code: string
    work_function_title: string
    status: string
    status_label: string
    deadline_label: string
    can_export: boolean
}
type ReportRow = {
    id: number
    type_label: string
    period: string
    records_count: number
    status: string
    status_label: string
}

const props = defineProps<{
    employer_ready: boolean
    employer_hint: string
    alerts: Alert[]
    events: EventRow[]
    reports: ReportRow[]
    pers_year: number
    pers_month: number
}>()

const clubName = useClubName()
const page = usePage()
const isOwner = computed(() => (page.props as any).admin_user?.role === 'owner')
const { success, error } = useToast()
const selected = ref<number[]>([])
const busy = ref(false)

const efsForm = useForm({ event_ids: [] as number[] })
const persForm = useForm({ year: props.pers_year, month: props.pers_month })

watch(() => (page.props as any).flash?.success as string | undefined, (msg) => {
    if (msg) success(msg)
}, { immediate: true })
watch(() => (page.props as any).errors?.cadre as string | undefined, (msg) => {
    if (msg) error(msg)
}, { immediate: true })

const toneClass: Record<string, string> = {
    overdue: 'border-red-500/40 text-red-300',
    due_soon: 'border-amber-500/40 text-amber-200',
    upcoming: 'border-cyan-500/30 text-cyan-100',
}

const months = [
    { value: 1, label: 'январь' }, { value: 2, label: 'февраль' }, { value: 3, label: 'март' },
    { value: 4, label: 'апрель' }, { value: 5, label: 'май' }, { value: 6, label: 'июнь' },
    { value: 7, label: 'июль' }, { value: 8, label: 'август' }, { value: 9, label: 'сентябрь' },
    { value: 10, label: 'октябрь' }, { value: 11, label: 'ноябрь' }, { value: 12, label: 'декабрь' },
]

const exportable = computed(() => props.events.filter((row) => row.can_export))

const toggle = (id: number) => {
    selected.value = selected.value.includes(id)
        ? selected.value.filter((item) => item !== id)
        : [...selected.value, id]
}

const generateEfs = () => {
    if (!selected.value.length || efsForm.processing) return
    efsForm.event_ids = [...selected.value]
    efsForm.post('/admin/taxes/cadre/efs1/generate', {
        preserveScroll: true,
        onSuccess: () => { selected.value = [] },
    })
}

const generatePers = () => {
    persForm.post('/admin/taxes/cadre/pers-records/generate', { preserveScroll: true })
}

const markSubmitted = (id: number) => {
    if (busy.value) return
    busy.value = true
    router.post(`/admin/taxes/cadre/mark-submitted/${id}`, {}, {
        preserveScroll: true,
        onFinish: () => { busy.value = false },
    })
}

const inputClass = 'mt-2 w-full bg-black/40 border border-white/10 rounded-2xl px-4 py-3 text-sm text-white outline-none'
</script>

<template>
    <Head :title="`${clubName} | Отчётность СФР`" />
    <AdminLayout>
        <div class="max-w-7xl mx-auto space-y-8 font-mono pb-20 px-4">
            <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4 border-b border-white/10 pb-6">
                <div>
                    <h1 class="text-3xl font-black uppercase italic text-white tracking-tighter">
                        Отчётность <span class="text-cyan-400">СФР / ФНС</span>
                    </h1>
                    <p class="text-white/30 text-[10px] uppercase tracking-[0.35em] font-black mt-2">
                        ЕФС-1 подраздел 1.1 и персонифицированные сведения
                    </p>
                </div>
                <Link v-if="isOwner" href="/admin/taxes" class="text-[10px] uppercase font-black tracking-widest text-white/40 hover:text-white">
                    Налоговый кабинет
                </Link>
            </div>

            <div class="rounded-[1.125rem] border px-5 py-4 text-sm font-bold"
                 :class="employer_ready ? 'border-cyan-500/30 text-cyan-100' : 'border-amber-500/40 text-amber-100'">
                {{ employer_hint }}
            </div>

            <div v-if="alerts.length" class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                <div v-for="alert in alerts" :key="alert.id"
                     class="rounded-2xl border bg-black/40 px-5 py-4"
                     :class="toneClass[alert.tone] || toneClass.upcoming">
                    <div class="text-sm font-black">{{ alert.title }}</div>
                    <div class="text-[10px] uppercase tracking-widest mt-2 opacity-70">{{ alert.hint }}</div>
                </div>
            </div>

            <section class="bg-[#0a0a0a] border border-white/5 rounded-[1.125rem] p-6 space-y-4">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                    <h2 class="text-sm uppercase font-black tracking-[0.2em] text-white/50">Кадровые мероприятия</h2>
                    <button type="button"
                            class="px-5 py-3 bg-cyan-400 text-black rounded-xl text-[10px] font-black uppercase tracking-widest disabled:opacity-40"
                            :disabled="!selected.length || efsForm.processing"
                            @click="generateEfs">
                        {{ efsForm.processing ? 'Сборка…' : 'Сформировать ЕФС-1' }}
                    </button>
                </div>
                <p v-if="efsForm.errors.event_ids" class="text-red-400 text-xs font-bold">{{ efsForm.errors.event_ids }}</p>
                <div v-if="!events.length" class="text-white/30 text-sm font-bold">Пока нет приёмов и увольнений по трудовому договору.</div>
                <div v-for="row in events" :key="row.id" class="flex flex-col md:flex-row md:items-center gap-3 border-t border-white/5 pt-4">
                    <label v-if="row.can_export" class="flex items-center gap-3 text-white">
                        <input type="checkbox" class="rounded border-white/20 bg-black" :checked="selected.includes(row.id)" @change="toggle(row.id)">
                    </label>
                    <div class="flex-1">
                        <div class="text-white font-black">{{ row.event_label }} · {{ row.name }}</div>
                        <div class="text-[11px] text-white/40 mt-1">
                            Приказ {{ row.order_number }} · ОКЗ {{ row.okz_code }} {{ row.work_function_title }} · {{ row.deadline_label }}
                        </div>
                    </div>
                    <div class="text-[10px] uppercase font-black tracking-widest text-white/50">{{ row.status_label }}</div>
                    <Link :href="`/admin/taxes/cadre/events/${row.id}/preview`"
                          class="text-[10px] uppercase font-black tracking-widest text-cyan-300">
                        Предпросмотр
                    </Link>
                </div>
                <p v-if="exportable.length === 0 && events.length" class="text-[11px] text-white/30">Все мероприятия уже отмечены как сданные.</p>
            </section>

            <section class="bg-[#0a0a0a] border border-white/5 rounded-[1.125rem] p-6 space-y-4">
                <h2 class="text-sm uppercase font-black tracking-[0.2em] text-white/50">Персонифицированные сведения</h2>
                <p class="text-[11px] text-white/40">
                    Строка 070 — начисления за закрытые смены и выплаченная премия. Квартальный фонд, который ещё не выплачен, в сумму не входит. Срок — 25-е число следующего месяца.
                </p>
                <form class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end" @submit.prevent="generatePers">
                    <label class="block">
                        <span class="text-[10px] uppercase font-black tracking-widest text-white/30">Год</span>
                        <input v-model.number="persForm.year" type="number" min="2024" max="2100" :class="inputClass">
                    </label>
                    <label class="block">
                        <span class="text-[10px] uppercase font-black tracking-widest text-white/30">Месяц</span>
                        <select v-model.number="persForm.month" :class="inputClass">
                            <option v-for="item in months" :key="item.value" :value="item.value">{{ item.label }}</option>
                        </select>
                    </label>
                    <button type="submit"
                            class="py-3 bg-white text-black rounded-2xl text-[10px] font-black uppercase tracking-widest disabled:opacity-40"
                            :disabled="persForm.processing">
                        {{ persForm.processing ? 'Сборка…' : 'Сформировать XML' }}
                    </button>
                </form>
            </section>

            <section class="bg-[#0a0a0a] border border-white/5 rounded-[1.125rem] p-6 space-y-4">
                <h2 class="text-sm uppercase font-black tracking-[0.2em] text-white/50">Журнал файлов</h2>
                <div v-if="!reports.length" class="text-white/30 text-sm font-bold">Файлов ещё нет.</div>
                <div v-for="report in reports" :key="report.id" class="flex flex-col md:flex-row md:items-center gap-3 border-t border-white/5 pt-4">
                    <div class="flex-1">
                        <div class="text-white font-black">{{ report.type_label }}</div>
                        <div class="text-[11px] text-white/40 mt-1">{{ report.period }} · записей {{ report.records_count }} · {{ report.status_label }}</div>
                    </div>
                    <a :href="`/admin/taxes/cadre/download/${report.id}`"
                       class="text-[10px] uppercase font-black tracking-widest text-cyan-300">
                        Скачать
                    </a>
                    <button v-if="report.status !== 'submitted'" type="button"
                            class="text-[10px] uppercase font-black tracking-widest text-white/60"
                            @click="markSubmitted(report.id)">
                        Отметить сданным
                    </button>
                </div>
            </section>
        </div>
    </AdminLayout>
</template>
