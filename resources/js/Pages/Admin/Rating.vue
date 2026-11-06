<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Head, router, usePage } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useClubName } from '@/Composables/useClubName'
import { useToast } from '@/Composables/useToast'

type EventRow = {
    id: number
    name: string
    points: number
    direction: 'add' | 'subtract'
    signed: number
}

type AdminRow = {
    id: number
    name: string
    role: string
    role_label: string
    total: number
}

type RecentRow = {
    id: number
    admin_name: string | null
    event_name: string
    points: number
    author: string | null
    created_at: string | null
}

const props = defineProps<{
    events: EventRow[]
    admins: AdminRow[]
    recent: RecentRow[]
}>()

const clubName = useClubName()
const page = usePage()
const { error } = useToast()
const busy = ref(false)
const flashSuccess = computed(() => (page.props as any).flash?.success as string | undefined)
const formErrors = computed(() => (page.props as any).errors as Record<string, string> | undefined)

const draft = ref({
    name: '',
    points: '10',
    direction: 'add' as 'add' | 'subtract',
})

const rows = ref<EventRow[]>([])
const applyAdminId = ref('')
const applyEventId = ref('')

const syncRows = (events: EventRow[]) => {
    rows.value = events.map((event) => ({ ...event }))
    if (!applyEventId.value && events[0]) {
        applyEventId.value = String(events[0].id)
    }
}

watch(() => props.events, (events) => syncRows(events), { immediate: true, deep: true })
watch(() => props.admins, (admins) => {
    if (!applyAdminId.value && admins[0]) {
        applyAdminId.value = String(admins[0].id)
    }
}, { immediate: true })

const currentAdminId = computed(() => Number((page.props.admin_user as any)?.id || 0))
const targets = computed(() => props.admins.filter((admin) => admin.id !== currentAdminId.value))

const signLabel = (points: number) => `${points > 0 ? '+' : ''}${points}`

const post = (url: string, data: Record<string, string | number>, method: 'post' | 'put' | 'delete' = 'post') => {
    busy.value = true
    const options = {
        preserveScroll: true,
        onFinish: () => { busy.value = false },
        onError: (errors: Record<string, string>) => {
            error(errors.name || errors.points || errors.direction || errors.apply || 'Не удалось сохранить')
        },
    }
    if (method === 'put') {
        router.put(url, data, options)
        return
    }
    if (method === 'delete') {
        router.delete(url, options)
        return
    }
    router.post(url, data, options)
}

const addEvent = () => {
    const name = draft.value.name.trim()
    const points = Number(draft.value.points)
    if (!name || !(points >= 1)) {
        error('Укажите название и баллы')
        return
    }
    post('/admin/rating', {
        name,
        points,
        direction: draft.value.direction,
    })
    draft.value.name = ''
}

const saveEvent = (row: EventRow) => {
    const name = row.name.trim()
    const points = Number(row.points)
    if (!name || !(points >= 1)) {
        error('Укажите название и баллы')
        return
    }
    post(`/admin/rating/${row.id}`, {
        name,
        points,
        direction: row.direction,
    }, 'put')
}

const removeEvent = (row: EventRow) => {
    if (!confirm(`Удалить событие «${row.name}»? Уже начисленные баллы останутся.`)) return
    post(`/admin/rating/${row.id}`, {}, 'delete')
}

const applyEvent = () => {
    if (!applyAdminId.value || !applyEventId.value) {
        error('Выберите сотрудника и событие')
        return
    }
    post('/admin/rating/apply', {
        admin_id: Number(applyAdminId.value),
        event_id: Number(applyEventId.value),
    })
}
</script>

<template>
    <Head :title="`${clubName} | Рейтинг админов`" />
    <AdminLayout>
        <div class="max-w-6xl mx-auto space-y-8 pb-20 px-4">
            <div>
                <h1 class="text-3xl font-black uppercase italic text-white tracking-tighter">
                    Рейтинг <span class="text-cyan-400">админов</span>
                </h1>
                <p class="text-white/30 text-[10px] uppercase tracking-[0.35em] font-black mt-2">
                    Событие начисляет или снимает баллы. Сумма видна в личном кабинете.
                </p>
            </div>

            <div v-if="flashSuccess" class="bg-[#22c55e]/10 border border-[#22c55e]/30 text-[#22c55e] rounded-2xl px-5 py-4 text-sm font-bold">
                {{ flashSuccess }}
            </div>
            <div v-if="formErrors?.name || formErrors?.apply" class="bg-red-500/10 border border-red-500/30 text-red-300 rounded-2xl px-5 py-4 text-sm font-bold">
                {{ formErrors.name || formErrors.apply }}
            </div>

            <section class="bg-[#050505] border border-white/10 rounded-[1.125rem] p-8 space-y-6">
                <h2 class="text-sm font-black uppercase italic tracking-widest text-white">Новое событие</h2>
                <form class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end" @submit.prevent="addEvent">
                    <label class="md:col-span-2 text-[10px] uppercase font-black tracking-widest text-white/40">
                        Название
                        <input v-model="draft.name" type="text" maxlength="80" placeholder="Идеальная смена"
                               class="mt-2 w-full bg-black/40 border border-white/10 rounded-2xl px-4 py-3 text-sm text-white outline-none">
                    </label>
                    <label class="text-[10px] uppercase font-black tracking-widest text-white/40">
                        Баллы
                        <input v-model="draft.points" type="number" min="1" max="100000" step="1"
                               class="mt-2 w-full bg-black/40 border border-white/10 rounded-2xl px-4 py-3 text-sm text-white outline-none">
                    </label>
                    <label class="text-[10px] uppercase font-black tracking-widest text-white/40">
                        Действие
                        <select v-model="draft.direction"
                                class="mt-2 w-full bg-black/40 border border-white/10 rounded-2xl px-4 py-3 text-sm text-white outline-none">
                            <option value="add">Начислить</option>
                            <option value="subtract">Снять</option>
                        </select>
                    </label>
                    <button type="submit" :disabled="busy"
                            class="md:col-span-4 px-4 py-3 bg-cyan-400 text-black rounded-2xl text-[10px] font-black uppercase tracking-widest disabled:opacity-40">
                        Добавить событие
                    </button>
                </form>
            </section>

            <section class="bg-[#050505] border border-white/10 rounded-[1.125rem] p-8 space-y-4">
                <h2 class="text-sm font-black uppercase italic tracking-widest text-white">События</h2>
                <div v-if="rows.length === 0" class="text-white/30 text-sm">Событий нет. Добавьте первое выше.</div>
                <div v-for="row in rows" :key="row.id" class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end border-b border-white/5 pb-4">
                    <label class="md:col-span-5 text-[10px] uppercase font-black tracking-widest text-white/40">
                        Название
                        <input v-model="row.name" type="text" maxlength="80"
                               class="mt-2 w-full bg-black/40 border border-white/10 rounded-2xl px-4 py-3 text-sm text-white outline-none">
                    </label>
                    <label class="md:col-span-2 text-[10px] uppercase font-black tracking-widest text-white/40">
                        Баллы
                        <input v-model.number="row.points" type="number" min="1" max="100000" step="1"
                               class="mt-2 w-full bg-black/40 border border-white/10 rounded-2xl px-4 py-3 text-sm text-white outline-none">
                    </label>
                    <label class="md:col-span-3 text-[10px] uppercase font-black tracking-widest text-white/40">
                        Действие
                        <select v-model="row.direction"
                                class="mt-2 w-full bg-black/40 border border-white/10 rounded-2xl px-4 py-3 text-sm text-white outline-none">
                            <option value="add">Начислить</option>
                            <option value="subtract">Снять</option>
                        </select>
                    </label>
                    <div class="md:col-span-2 flex gap-2">
                        <button type="button" :disabled="busy" @click="saveEvent(row)"
                                class="flex-1 px-3 py-3 bg-white/10 text-white rounded-2xl text-[10px] font-black uppercase tracking-widest disabled:opacity-40">
                            Сохранить
                        </button>
                        <button type="button" :disabled="busy" @click="removeEvent(row)"
                                class="px-3 py-3 border border-red-500/40 text-red-300 rounded-2xl text-[10px] font-black uppercase tracking-widest disabled:opacity-40">
                            Удалить
                        </button>
                    </div>
                </div>
            </section>

            <section class="bg-[#050505] border border-cyan-500/20 rounded-[1.125rem] p-8 space-y-6">
                <h2 class="text-sm font-black uppercase italic tracking-widest text-white">Провести событие</h2>
                <form class="grid grid-cols-1 md:grid-cols-3 gap-3 items-end" @submit.prevent="applyEvent">
                    <label class="text-[10px] uppercase font-black tracking-widest text-white/40">
                        Сотрудник
                        <select v-model="applyAdminId"
                                class="mt-2 w-full bg-black/40 border border-white/10 rounded-2xl px-4 py-3 text-sm text-white outline-none">
                            <option v-for="admin in targets" :key="admin.id" :value="String(admin.id)">
                                {{ admin.name }} · {{ admin.role_label }}
                            </option>
                        </select>
                    </label>
                    <label class="text-[10px] uppercase font-black tracking-widest text-white/40">
                        Событие
                        <select v-model="applyEventId"
                                class="mt-2 w-full bg-black/40 border border-white/10 rounded-2xl px-4 py-3 text-sm text-white outline-none">
                            <option v-for="event in events" :key="event.id" :value="String(event.id)">
                                {{ event.name }} · {{ event.signed > 0 ? '+' : '' }}{{ event.signed }}
                            </option>
                        </select>
                    </label>
                    <button type="submit" :disabled="busy || targets.length === 0 || events.length === 0"
                            class="px-4 py-3 border border-cyan-400/40 text-cyan-300 rounded-2xl text-[10px] font-black uppercase tracking-widest disabled:opacity-40">
                        Провести
                    </button>
                </form>

                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                        <tr class="border-b border-white/10 text-[10px] uppercase tracking-widest text-white/30">
                            <th class="py-3 pr-4">Сотрудник</th>
                            <th class="py-3 pr-4">Роль</th>
                            <th class="py-3 text-right">Рейтинг</th>
                        </tr>
                        </thead>
                        <tbody>
                        <tr v-for="admin in admins" :key="admin.id" class="border-b border-white/5 text-sm">
                            <td class="py-3 pr-4 text-white font-bold">{{ admin.name }}</td>
                            <td class="py-3 pr-4 text-white/50">{{ admin.role_label }}</td>
                            <td class="py-3 text-right font-black" :class="admin.total < 0 ? 'text-amber-300' : 'text-cyan-300'">
                                {{ signLabel(admin.total) }}
                            </td>
                        </tr>
                        <tr v-if="admins.length === 0">
                            <td colspan="3" class="py-6 text-white/30 text-sm">Нет сотрудников зала.</td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section v-if="recent.length" class="bg-[#050505] border border-white/10 rounded-[1.125rem] p-8 space-y-3">
                <h2 class="text-sm font-black uppercase italic tracking-widest text-white">Последние проведения</h2>
                <div v-for="row in recent" :key="row.id" class="flex justify-between gap-4 text-xs border-b border-white/5 py-2">
                    <span class="text-white/60">
                        {{ row.created_at }} · {{ row.admin_name }} · {{ row.event_name }}
                        <span v-if="row.author" class="text-white/30">· {{ row.author }}</span>
                    </span>
                    <span class="font-black shrink-0" :class="row.points < 0 ? 'text-amber-300' : 'text-cyan-300'">
                        {{ signLabel(row.points) }}
                    </span>
                </div>
            </section>
        </div>
    </AdminLayout>
</template>
