<script setup>
import { computed, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const props = defineProps({
    clubs: Array,
    selectedClubId: Number,
    selectedTariffId: Number,
    tariffs: Array,
    zones: Array,
    dayGroups: Array,
    rules: Array,
    overrides: Array,
    addons: Array,
})

const WEEKDAY_LABELS = [
    { id: 1, short: 'Пн' },
    { id: 2, short: 'Вт' },
    { id: 3, short: 'Ср' },
    { id: 4, short: 'Чт' },
    { id: 5, short: 'Пт' },
    { id: 6, short: 'Сб' },
    { id: 7, short: 'Вс' },
]

const showAddTariff = ref(false)
const showRuleModal = ref(false)
const editingRuleId = ref(null)
const showDayGroupModal = ref(false)
const editingDayGroupId = ref(null)
const showOverrideModal = ref(false)
const showAddonModal = ref(false)
const editingAddonId = ref(null)

const tariffForm = useForm({ name: '', threshold_hours: 1 })

const ruleForm = useForm({
    club_id: props.selectedClubId,
    zone_id: props.zones?.[0]?.id ?? null,
    day_group_id: props.dayGroups?.[0]?.id ?? null,
    time_start: 0,
    time_end: 1440,
    price: '',
})

const dayGroupForm = useForm({
    name: '',
    color: '#22c55e',
    weekdays: [1, 2, 3, 4, 5],
    sort: 100,
})

const overrideForm = useForm({
    date: '',
    day_group_id: props.dayGroups?.[0]?.id ?? null,
    note: '',
})

const addonForm = useForm({
    name: '',
    color: '#22c55e',
    billing_mode: 'always',
    club_id: props.selectedClubId,
    price_per_hour: '',
})

watch(() => props.selectedClubId, (id) => {
    ruleForm.club_id = id
    addonForm.club_id = id
})

const selectedTariff = computed(() =>
    (props.tariffs || []).find((t) => t.id === props.selectedTariffId) || null
)

const reload = (params = {}) => {
    router.get('/admin/tariffs', {
        club: props.selectedClubId,
        tariff: props.selectedTariffId || undefined,
        ...params,
    }, { preserveScroll: true })
}

const minutesToInput = (minutes) => {
    const m = Math.max(0, Math.min(1440, Number(minutes) || 0))
    if (m === 1440) return '24:00'
    const h = Math.floor(m / 60)
    const min = m % 60
    return `${String(h).padStart(2, '0')}:${String(min).padStart(2, '0')}`
}

const inputToMinutes = (value) => {
    if (value === '24:00') return 1440
    const [h, m] = String(value || '00:00').split(':').map(Number)
    return Math.min(1439, (h || 0) * 60 + (m || 0))
}

const formatRange = (start, end) => `${minutesToInput(start)}–${minutesToInput(end)}`

const weekdayText = (weekdays) => {
    const set = new Set((weekdays || []).map(Number))
    return WEEKDAY_LABELS.filter((d) => set.has(d.id)).map((d) => d.short).join(' ')
}

const openNewRule = () => {
    editingRuleId.value = null
    ruleForm.club_id = props.selectedClubId
    ruleForm.zone_id = props.zones?.[0]?.id ?? null
    ruleForm.day_group_id = props.dayGroups?.[0]?.id ?? null
    ruleForm.time_start = 0
    ruleForm.time_end = 1440
    ruleForm.price = ''
    ruleForm.clearErrors()
    showRuleModal.value = true
}

const openEditRule = (rule) => {
    editingRuleId.value = rule.id
    ruleForm.club_id = props.selectedClubId
    ruleForm.zone_id = rule.zone_id
    ruleForm.day_group_id = rule.day_group_id
    ruleForm.time_start = rule.time_start
    ruleForm.time_end = rule.time_end
    ruleForm.price = rule.price
    ruleForm.clearErrors()
    showRuleModal.value = true
}

const submitRule = () => {
    if (!props.selectedTariffId) return
    if (editingRuleId.value) {
        ruleForm.put(`/admin/tariff-prices/${editingRuleId.value}`, {
            preserveScroll: true,
            onSuccess: () => { showRuleModal.value = false },
        })
    } else {
        ruleForm.post(`/admin/tariffs/${props.selectedTariffId}/rules`, {
            preserveScroll: true,
            onSuccess: () => { showRuleModal.value = false },
        })
    }
}

const deleteRule = (id) => {
    if (confirm('Удалить правило?')) {
        router.delete(`/admin/tariff-prices/${id}`, { preserveScroll: true })
    }
}

const submitTariff = () => {
    tariffForm.post('/admin/tariffs', {
        preserveScroll: true,
        onSuccess: () => {
            showAddTariff.value = false
            tariffForm.reset()
        },
    })
}

const toggleStatus = (tariff) => {
    router.put(`/admin/tariffs/${tariff.id}`, { is_active: !tariff.is_active }, { preserveScroll: true })
}

const deleteTariff = (id) => {
    if (confirm('Удалить тариф и все его правила?')) {
        router.delete(`/admin/tariffs/${id}`, { preserveScroll: true })
    }
}

const openNewDayGroup = () => {
    editingDayGroupId.value = null
    dayGroupForm.name = ''
    dayGroupForm.color = '#22c55e'
    dayGroupForm.weekdays = [1, 2, 3, 4, 5]
    dayGroupForm.sort = 100
    dayGroupForm.clearErrors()
    showDayGroupModal.value = true
}

const openEditDayGroup = (group) => {
    editingDayGroupId.value = group.id
    dayGroupForm.name = group.name
    dayGroupForm.color = group.color || '#22c55e'
    dayGroupForm.weekdays = [...(group.weekdays || [])]
    dayGroupForm.sort = group.sort ?? 100
    dayGroupForm.clearErrors()
    showDayGroupModal.value = true
}

const toggleWeekday = (id) => {
    const set = new Set(dayGroupForm.weekdays.map(Number))
    if (set.has(id)) set.delete(id)
    else set.add(id)
    dayGroupForm.weekdays = [...set].sort((a, b) => a - b)
}

const submitDayGroup = () => {
    if (editingDayGroupId.value) {
        dayGroupForm.put(`/admin/day-groups/${editingDayGroupId.value}`, {
            preserveScroll: true,
            onSuccess: () => { showDayGroupModal.value = false },
        })
    } else {
        dayGroupForm.post('/admin/day-groups', {
            preserveScroll: true,
            onSuccess: () => { showDayGroupModal.value = false },
        })
    }
}

const deleteDayGroup = (id) => {
    if (confirm('Удалить группу дней?')) {
        router.delete(`/admin/day-groups/${id}`, { preserveScroll: true })
    }
}

const submitOverride = () => {
    overrideForm.post('/admin/calendar-overrides', {
        preserveScroll: true,
        onSuccess: () => {
            showOverrideModal.value = false
            overrideForm.reset()
            overrideForm.day_group_id = props.dayGroups?.[0]?.id ?? null
        },
    })
}

const deleteOverride = (id) => {
    if (confirm('Убрать переопределение даты?')) {
        router.delete(`/admin/calendar-overrides/${id}`, { preserveScroll: true })
    }
}

const openNewAddon = () => {
    editingAddonId.value = null
    addonForm.name = ''
    addonForm.color = '#22c55e'
    addonForm.billing_mode = 'always'
    addonForm.club_id = props.selectedClubId
    addonForm.price_per_hour = ''
    addonForm.clearErrors()
    showAddonModal.value = true
}

const openEditAddon = (addon) => {
    editingAddonId.value = addon.id
    addonForm.name = addon.name
    addonForm.color = addon.color || '#22c55e'
    addonForm.billing_mode = addon.billing_mode
    addonForm.club_id = props.selectedClubId
    addonForm.price_per_hour = addon.price_per_hour ?? ''
    addonForm.clearErrors()
    showAddonModal.value = true
}

const submitAddon = () => {
    if (editingAddonId.value) {
        addonForm.put(`/admin/addons/${editingAddonId.value}`, {
            preserveScroll: true,
            onSuccess: () => { showAddonModal.value = false },
        })
    } else {
        addonForm.post('/admin/addons', {
            preserveScroll: true,
            onSuccess: () => { showAddonModal.value = false },
        })
    }
}

const deleteAddon = (id) => {
    if (confirm('Удалить доп? Он снимется со всех комнат на картах.')) {
        router.delete(`/admin/addons/${id}`, { preserveScroll: true })
    }
}

const timeStartInput = computed({
    get: () => minutesToInput(ruleForm.time_start),
    set: (v) => { ruleForm.time_start = inputToMinutes(v) },
})
const timeEndInput = computed({
    get: () => minutesToInput(ruleForm.time_end),
    set: (v) => { ruleForm.time_end = v === '24:00' ? 1440 : inputToMinutes(v) },
})
</script>

<template>
    <AdminLayout>
        <div class="p-8 min-h-full font-mono text-white animate-in fade-in duration-500">
            <div class="flex flex-wrap gap-6 justify-between items-center mb-8">
                <h1 class="text-4xl font-black italic tracking-tighter uppercase text-[#22c55e]">Управление тарифами</h1>

                <div class="flex flex-wrap gap-3 items-center">
                    <div>
                        <label class="text-[10px] uppercase text-white/40 tracking-widest font-black italic mr-2">Клуб</label>
                        <select :value="selectedClubId" @change="reload({ club: Number($event.target.value) })"
                                class="bg-black border-2 border-white/10 rounded-xl px-4 py-3 text-white font-bold text-xs focus:border-[#22c55e] outline-none appearance-none">
                            <option v-for="club in clubs" :key="club.id" :value="club.id">{{ club.name }}</option>
                        </select>
                    </div>
                    <button @click="showAddTariff = true"
                            class="px-5 py-3 bg-[#22c55e] text-black font-black rounded-xl hover:bg-[#1ea34d] tracking-widest text-xs uppercase active:scale-95">
                        + Тариф
                    </button>
                    <button @click="openNewRule" :disabled="!selectedTariffId"
                            class="px-5 py-3 border border-[#22c55e]/40 text-[#22c55e] font-black rounded-xl hover:bg-[#22c55e]/10 tracking-widest text-xs uppercase disabled:opacity-30">
                        + Правило цены
                    </button>
                </div>
            </div>

            <p class="mb-8 text-[11px] text-white/35 leading-relaxed max-w-4xl">
                Карточка тарифа общая. Цена задаётся правилами: зона + группа дней + интервал времени.
                Бронь, пересекающая день/ночь или будни/выходные, считается по сегментам.
                Доплата «+» комнаты и PS настраиваются отдельно, не здесь.
            </p>

            <div class="grid grid-cols-1 xl:grid-cols-[280px_1fr] gap-6">
                <!-- Тарифы -->
                <aside class="space-y-3">
                    <div class="text-[10px] text-white/30 uppercase font-black tracking-[0.25em] mb-2">Тарифы</div>
                    <button v-for="tariff in tariffs" :key="tariff.id"
                            @click="reload({ tariff: tariff.id })"
                            :class="[
                                'w-full text-left px-4 py-4 rounded-2xl border transition-all',
                                selectedTariffId === tariff.id
                                    ? 'border-[#22c55e]/40 bg-[#22c55e]/10'
                                    : 'border-white/5 bg-[#0a0a0a] hover:border-white/20',
                                !tariff.is_active && 'opacity-40'
                            ]">
                        <div class="flex justify-between items-start gap-2">
                            <div>
                                <div class="font-black uppercase italic text-sm">{{ tariff.name }}</div>
                                <div class="text-[9px] text-white/35 uppercase tracking-widest mt-1">
                                    {{ tariff.threshold_hours }} ч · {{ tariff.threshold_hours === 1 ? 'почасовой' : 'пакет' }}
                                </div>
                            </div>
                            <div class="flex gap-2" @click.stop>
                                <button @click="toggleStatus(tariff)" class="text-[#22c55e]/70 hover:text-[#22c55e]" title="Вкл/выкл">⏻</button>
                                <button @click="deleteTariff(tariff.id)" class="text-red-500/50 hover:text-red-500" title="Удалить">✕</button>
                            </div>
                        </div>
                    </button>
                    <div v-if="!tariffs?.length" class="text-[11px] text-white/30 p-4 border border-dashed border-white/10 rounded-2xl">
                        Создайте первый тариф
                    </div>
                </aside>

                <!-- Правила -->
                <section class="space-y-6">
                    <div class="rounded-3xl border border-white/5 bg-[#0a0a0a] overflow-hidden">
                        <div class="px-6 py-4 border-b border-white/5 flex justify-between items-center">
                            <div>
                                <div class="text-[10px] text-white/30 uppercase font-black tracking-widest">Правила цен</div>
                                <div class="text-lg font-black uppercase italic mt-1">
                                    {{ selectedTariff?.name || '—' }}
                                </div>
                            </div>
                        </div>

                        <div v-if="rules?.length" class="overflow-x-auto">
                            <table class="w-full border-collapse">
                                <thead>
                                    <tr class="border-b border-white/10 text-[10px] text-white/30 uppercase tracking-widest">
                                        <th class="text-left p-4">Зона</th>
                                        <th class="text-left p-4">Дни</th>
                                        <th class="text-left p-4">Время</th>
                                        <th class="text-left p-4">Цена</th>
                                        <th class="p-4 w-28"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="rule in rules" :key="rule.id" class="border-b border-white/5 hover:bg-white/[0.02]">
                                        <td class="p-4">
                                            <div class="flex items-center gap-2">
                                                <span class="w-2 h-2 rounded-full" :style="{ backgroundColor: rule.zone?.color || '#22c55e' }"></span>
                                                <span class="font-black uppercase text-xs tracking-widest">{{ rule.zone?.name }}</span>
                                            </div>
                                        </td>
                                        <td class="p-4">
                                            <div class="text-xs font-bold">{{ rule.day_group?.name }}</div>
                                            <div class="text-[9px] text-white/30 mt-1">{{ weekdayText(rule.day_group?.weekdays) }}</div>
                                        </td>
                                        <td class="p-4 font-mono text-sm text-white/80">{{ formatRange(rule.time_start, rule.time_end) }}</td>
                                        <td class="p-4 text-[#22c55e] font-black text-lg">{{ Number(rule.price).toFixed(0) }}₽</td>
                                        <td class="p-4">
                                            <div class="flex gap-3 justify-end">
                                                <button @click="openEditRule(rule)" class="text-white/40 hover:text-white text-[10px] uppercase font-black">изм</button>
                                                <button @click="deleteRule(rule.id)" class="text-red-500/50 hover:text-red-500 text-[10px] uppercase font-black">удл</button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-else class="p-12 text-center text-white/30 text-xs uppercase tracking-widest">
                            Нет правил — добавьте цену для зоны, дней и времени
                        </div>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <!-- Группы дней -->
                        <div class="rounded-3xl border border-white/5 bg-[#0a0a0a] p-6">
                            <div class="flex justify-between items-center mb-4">
                                <div class="text-[10px] text-white/30 uppercase font-black tracking-widest">Группы дней</div>
                                <button @click="openNewDayGroup" class="text-[10px] uppercase font-black text-[#22c55e]">+ группа</button>
                            </div>
                            <div class="space-y-2">
                                <div v-for="group in dayGroups" :key="group.id"
                                     class="flex items-center justify-between gap-3 px-4 py-3 rounded-xl bg-black border border-white/5">
                                    <div class="flex items-center gap-3 min-w-0">
                                        <span class="w-2.5 h-2.5 rounded-full shrink-0" :style="{ backgroundColor: group.color }"></span>
                                        <div class="min-w-0">
                                            <div class="text-xs font-black uppercase truncate">{{ group.name }}</div>
                                            <div class="text-[9px] text-white/30">{{ weekdayText(group.weekdays) }}</div>
                                        </div>
                                    </div>
                                    <div class="flex gap-2 shrink-0">
                                        <button @click="openEditDayGroup(group)" class="text-white/30 hover:text-white text-[9px] uppercase font-black">изм</button>
                                        <button @click="deleteDayGroup(group.id)" class="text-red-500/40 hover:text-red-500 text-[9px] uppercase font-black">удл</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Календарь -->
                        <div class="rounded-3xl border border-white/5 bg-[#0a0a0a] p-6">
                            <div class="flex justify-between items-center mb-4">
                                <div class="text-[10px] text-white/30 uppercase font-black tracking-widest">Праздники / даты</div>
                                <button @click="showOverrideModal = true" class="text-[10px] uppercase font-black text-[#22c55e]">+ дата</button>
                            </div>
                            <p class="text-[10px] text-white/25 mb-4 leading-relaxed">
                                Дата считается по выбранной группе дней, а не по дню недели календаря.
                            </p>
                            <div class="space-y-2 max-h-64 overflow-y-auto">
                                <div v-for="row in overrides" :key="row.id"
                                     class="flex items-center justify-between gap-3 px-4 py-3 rounded-xl bg-black border border-white/5">
                                    <div>
                                        <div class="text-xs font-mono font-bold">{{ row.date }}</div>
                                        <div class="text-[9px] text-white/40 mt-1">{{ row.day_group?.name }}{{ row.note ? ` · ${row.note}` : '' }}</div>
                                    </div>
                                    <button @click="deleteOverride(row.id)" class="text-red-500/40 hover:text-red-500 text-[9px] uppercase font-black">удл</button>
                                </div>
                                <div v-if="!overrides?.length" class="text-[11px] text-white/25 py-6 text-center">Переопределений нет</div>
                            </div>
                        </div>
                    </div>

                    <!-- Допы -->
                    <div class="rounded-3xl border border-white/5 bg-[#0a0a0a] p-6">
                        <div class="flex justify-between items-center mb-2">
                            <div class="text-[10px] text-white/30 uppercase font-black tracking-widest">Допы (+, PS…)</div>
                            <button @click="openNewAddon" class="text-[10px] uppercase font-black text-[#22c55e]">+ доп</button>
                        </div>
                        <p class="text-[10px] text-white/25 mb-4 leading-relaxed max-w-3xl">
                            Создайте доп и цену для клуба — он появится в редакторе карты (режим «Допы»),
                            где его можно повесить на комнату. «Всегда» входит в цену автоматически, «Опция» — гость включает при брони (PS).
                        </p>
                        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                            <div v-for="addon in addons" :key="addon.id"
                                 class="flex items-center justify-between gap-3 px-4 py-3 rounded-xl bg-black border border-white/5"
                                 :class="!addon.is_active && 'opacity-40'">
                                <div class="flex items-center gap-3 min-w-0">
                                    <span class="w-2.5 h-2.5 rounded-full shrink-0" :style="{ backgroundColor: addon.color }"></span>
                                    <div class="min-w-0">
                                        <div class="text-xs font-black uppercase truncate">{{ addon.name }}</div>
                                        <div class="text-[9px] text-white/35 mt-0.5">
                                            {{ addon.billing_mode === 'optional' ? 'опция' : 'всегда' }}
                                            ·
                                            <span v-if="addon.price_per_hour != null" class="text-[#22c55e]">{{ Number(addon.price_per_hour).toFixed(0) }} ₽/ч</span>
                                            <span v-else class="text-white/25">нет цены в клубе</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex gap-2 shrink-0">
                                    <button @click="openEditAddon(addon)" class="text-white/30 hover:text-white text-[9px] uppercase font-black">изм</button>
                                    <button @click="deleteAddon(addon.id)" class="text-red-500/40 hover:text-red-500 text-[9px] uppercase font-black">удл</button>
                                </div>
                            </div>
                        </div>
                        <div v-if="!addons?.length" class="text-[11px] text-white/25 py-8 text-center">Допов пока нет — создайте «+» или «PS»</div>
                    </div>
                </section>
            </div>
        </div>

        <!-- Модалки -->
        <Teleport to="body">
            <div v-if="showAddTariff" class="fixed inset-0 z-[9999900] flex items-center justify-center p-6 font-mono">
                <div class="absolute inset-0 bg-black/95 backdrop-blur-xl" @click="showAddTariff = false"></div>
                <form @submit.prevent="submitTariff" class="relative w-full max-w-lg bg-[#0a0a0a] border border-[#22c55e]/30 rounded-[2.5rem] p-10 space-y-5">
                    <h2 class="text-[#22c55e] text-2xl font-black uppercase italic">Новый тариф</h2>
                    <input v-model="tariffForm.name" required placeholder="Название" class="w-full bg-black border-2 border-white/5 rounded-2xl p-4 text-white font-bold focus:border-[#22c55e] outline-none" />
                    <input v-model="tariffForm.threshold_hours" type="number" min="1" required placeholder="Часов (1 = почасовой)" class="no-spinners w-full bg-black border-2 border-white/5 rounded-2xl p-4 text-white font-bold focus:border-[#22c55e] outline-none" />
                    <div class="flex gap-3 pt-4">
                        <button type="button" @click="showAddTariff = false" class="flex-1 py-4 border border-white/10 rounded-2xl text-[10px] font-black uppercase text-white/40">Отмена</button>
                        <button type="submit" :disabled="tariffForm.processing" class="flex-[2] py-4 bg-[#22c55e] text-black rounded-2xl text-[10px] font-black uppercase">Создать</button>
                    </div>
                </form>
            </div>

            <div v-if="showRuleModal" class="fixed inset-0 z-[9999900] flex items-center justify-center p-6 font-mono">
                <div class="absolute inset-0 bg-black/95 backdrop-blur-xl" @click="showRuleModal = false"></div>
                <form @submit.prevent="submitRule" class="relative w-full max-w-xl bg-[#0a0a0a] border border-[#22c55e]/30 rounded-[2.5rem] p-10 space-y-4">
                    <h2 class="text-[#22c55e] text-2xl font-black uppercase italic mb-2">
                        {{ editingRuleId ? 'Изменить правило' : 'Правило цены' }}
                    </h2>

                    <label class="block text-[10px] uppercase text-white/40 font-black tracking-widest">Зона</label>
                    <select v-model="ruleForm.zone_id" class="w-full bg-black border-2 border-white/5 rounded-2xl p-4 text-white font-bold outline-none focus:border-[#22c55e] appearance-none">
                        <option v-for="z in zones" :key="z.id" :value="z.id">{{ z.name }}</option>
                    </select>

                    <label class="block text-[10px] uppercase text-white/40 font-black tracking-widest">Группа дней</label>
                    <select v-model="ruleForm.day_group_id" class="w-full bg-black border-2 border-white/5 rounded-2xl p-4 text-white font-bold outline-none focus:border-[#22c55e] appearance-none">
                        <option v-for="g in dayGroups" :key="g.id" :value="g.id">{{ g.name }}</option>
                    </select>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-[10px] uppercase text-white/40 font-black tracking-widest mb-2">С</label>
                            <input v-model="timeStartInput" type="time" class="w-full bg-black border-2 border-white/5 rounded-2xl p-4 text-white font-bold outline-none focus:border-[#22c55e]" />
                        </div>
                        <div>
                            <label class="block text-[10px] uppercase text-white/40 font-black tracking-widest mb-2">До (24:00 = конец суток)</label>
                            <input v-model="timeEndInput" list="end-times" class="w-full bg-black border-2 border-white/5 rounded-2xl p-4 text-white font-bold outline-none focus:border-[#22c55e]" placeholder="24:00" />
                            <datalist id="end-times">
                                <option value="06:00" /><option value="12:00" /><option value="18:00" /><option value="22:00" /><option value="24:00" />
                            </datalist>
                        </div>
                    </div>
                    <p class="text-[9px] text-white/25">Через полночь: например С 22:00 До 06:00 — одно правило.</p>

                    <label class="block text-[10px] uppercase text-white/40 font-black tracking-widest">Цена, ₽</label>
                    <input v-model="ruleForm.price" type="number" min="0" step="1" required class="no-spinners w-full bg-black border-2 border-white/5 rounded-2xl p-4 text-[#22c55e] font-black outline-none focus:border-[#22c55e]" />

                    <p v-if="ruleForm.errors.time_start || ruleForm.errors.time_end || ruleForm.errors.price"
                       class="text-red-400 text-[10px] uppercase">
                        {{ ruleForm.errors.time_start || ruleForm.errors.time_end || ruleForm.errors.price }}
                    </p>

                    <div class="flex gap-3 pt-4">
                        <button type="button" @click="showRuleModal = false" class="flex-1 py-4 border border-white/10 rounded-2xl text-[10px] font-black uppercase text-white/40">Отмена</button>
                        <button type="submit" :disabled="ruleForm.processing" class="flex-[2] py-4 bg-[#22c55e] text-black rounded-2xl text-[10px] font-black uppercase">Сохранить</button>
                    </div>
                </form>
            </div>

            <div v-if="showDayGroupModal" class="fixed inset-0 z-[9999900] flex items-center justify-center p-6 font-mono">
                <div class="absolute inset-0 bg-black/95 backdrop-blur-xl" @click="showDayGroupModal = false"></div>
                <form @submit.prevent="submitDayGroup" class="relative w-full max-w-lg bg-[#0a0a0a] border border-[#22c55e]/30 rounded-[2.5rem] p-10 space-y-5">
                    <h2 class="text-[#22c55e] text-2xl font-black uppercase italic">Группа дней</h2>
                    <input v-model="dayGroupForm.name" required placeholder="Название" class="w-full bg-black border-2 border-white/5 rounded-2xl p-4 text-white font-bold focus:border-[#22c55e] outline-none" />
                    <input v-model="dayGroupForm.color" type="color" class="w-full h-12 bg-black border-2 border-white/5 rounded-2xl p-1" />
                    <div class="flex flex-wrap gap-2">
                        <button v-for="d in WEEKDAY_LABELS" :key="d.id" type="button" @click="toggleWeekday(d.id)"
                                :class="['px-3 py-2 rounded-xl text-[10px] font-black uppercase border', dayGroupForm.weekdays.includes(d.id) ? 'bg-[#22c55e] text-black border-[#22c55e]' : 'border-white/10 text-white/40']">
                            {{ d.short }}
                        </button>
                    </div>
                    <div class="flex gap-3 pt-4">
                        <button type="button" @click="showDayGroupModal = false" class="flex-1 py-4 border border-white/10 rounded-2xl text-[10px] font-black uppercase text-white/40">Отмена</button>
                        <button type="submit" :disabled="dayGroupForm.processing" class="flex-[2] py-4 bg-[#22c55e] text-black rounded-2xl text-[10px] font-black uppercase">Сохранить</button>
                    </div>
                </form>
            </div>

            <div v-if="showOverrideModal" class="fixed inset-0 z-[9999900] flex items-center justify-center p-6 font-mono">
                <div class="absolute inset-0 bg-black/95 backdrop-blur-xl" @click="showOverrideModal = false"></div>
                <form @submit.prevent="submitOverride" class="relative w-full max-w-lg bg-[#0a0a0a] border border-[#22c55e]/30 rounded-[2.5rem] p-10 space-y-5">
                    <h2 class="text-[#22c55e] text-2xl font-black uppercase italic">Дата как…</h2>
                    <input v-model="overrideForm.date" type="date" required class="w-full bg-black border-2 border-white/5 rounded-2xl p-4 text-white font-bold focus:border-[#22c55e] outline-none" />
                    <select v-model="overrideForm.day_group_id" class="w-full bg-black border-2 border-white/5 rounded-2xl p-4 text-white font-bold outline-none focus:border-[#22c55e] appearance-none">
                        <option v-for="g in dayGroups" :key="g.id" :value="g.id">{{ g.name }}</option>
                    </select>
                    <input v-model="overrideForm.note" placeholder="Комментарий (праздник)" class="w-full bg-black border-2 border-white/5 rounded-2xl p-4 text-white font-bold focus:border-[#22c55e] outline-none" />
                    <div class="flex gap-3 pt-4">
                        <button type="button" @click="showOverrideModal = false" class="flex-1 py-4 border border-white/10 rounded-2xl text-[10px] font-black uppercase text-white/40">Отмена</button>
                        <button type="submit" :disabled="overrideForm.processing" class="flex-[2] py-4 bg-[#22c55e] text-black rounded-2xl text-[10px] font-black uppercase">Сохранить</button>
                    </div>
                </form>
            </div>

            <div v-if="showAddonModal" class="fixed inset-0 z-[9999900] flex items-center justify-center p-6 font-mono">
                <div class="absolute inset-0 bg-black/95 backdrop-blur-xl" @click="showAddonModal = false"></div>
                <form @submit.prevent="submitAddon" class="relative w-full max-w-lg bg-[#0a0a0a] border border-[#22c55e]/30 rounded-[2.5rem] p-10 space-y-5">
                    <h2 class="text-[#22c55e] text-2xl font-black uppercase italic">
                        {{ editingAddonId ? 'Изменить доп' : 'Новый доп' }}
                    </h2>
                    <input v-model="addonForm.name" required placeholder="Название (+ / PS / …)" class="w-full bg-black border-2 border-white/5 rounded-2xl p-4 text-white font-bold focus:border-[#22c55e] outline-none" />
                    <input v-model="addonForm.color" type="color" class="w-full h-12 bg-black border-2 border-white/5 rounded-2xl p-1" />
                    <select v-model="addonForm.billing_mode" class="w-full bg-black border-2 border-white/5 rounded-2xl p-4 text-white font-bold outline-none focus:border-[#22c55e] appearance-none">
                        <option value="always">Всегда в цене (как «+»)</option>
                        <option value="optional">Опция при брони (как PS)</option>
                    </select>
                    <input v-model="addonForm.price_per_hour" type="number" min="0" step="1" required placeholder="₽/час в этом клубе" class="no-spinners w-full bg-black border-2 border-white/5 rounded-2xl p-4 text-[#22c55e] font-black focus:border-[#22c55e] outline-none" />
                    <div class="flex gap-3 pt-4">
                        <button type="button" @click="showAddonModal = false" class="flex-1 py-4 border border-white/10 rounded-2xl text-[10px] font-black uppercase text-white/40">Отмена</button>
                        <button type="submit" :disabled="addonForm.processing" class="flex-[2] py-4 bg-[#22c55e] text-black rounded-2xl text-[10px] font-black uppercase">Сохранить</button>
                    </div>
                </form>
            </div>
        </Teleport>
    </AdminLayout>
</template>

<style scoped>
.no-spinners::-webkit-outer-spin-button,
.no-spinners::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
.no-spinners { -moz-appearance: textfield; }
</style>
