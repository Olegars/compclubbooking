<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Head, router, useForm, usePage } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useClubName } from '@/Composables/useClubName'
import { useToast } from '@/Composables/useToast'

type EventSettings = {
    enabled: boolean
    duration_sec: number
    color: string
    effect: string
    brightness: number
    strobe: boolean
    strobe_on_ms: number
    strobe_off_ms: number
    cycle_colors: string[]
    cycle_hold_sec: number
    fade_sec: number
}

type InteractiveEvent = {
    id: string
    title: string
    hint: string
    group: string
    allow_auto: boolean
    settings: EventSettings
}

const clubName = useClubName()
const { success, error } = useToast()
const page = usePage()

const props = defineProps<{
    clubs: Array<{ id: number, name: string }>
    clubId: number
    nodes: any[]
    lights: any[]
    spaces: any[]
    computers: Array<{ id: number, name: string, space_id: number | null, space_name?: string | null }>
    defaults: { port: number, brightness: number }
    tab?: string
    interactiveEvents?: InteractiveEvent[]
    colorOptions?: string[]
}>()

const selectedClubId = ref(props.clubId || props.clubs[0]?.id || 0)
const activeTab = ref(props.tab === 'interactive' ? 'interactive' : 'nodes')
const openEventId = ref('')
const eventDrafts = ref<InteractiveEvent[]>([])

const cloneEvents = (rows: InteractiveEvent[] | undefined): InteractiveEvent[] =>
    JSON.parse(JSON.stringify(rows || []))

eventDrafts.value = cloneEvents(props.interactiveEvents)

watch(selectedClubId, (id) => {
    router.get('/admin/lights', {
        club_id: id,
        tab: activeTab.value === 'interactive' ? 'interactive' : undefined,
    }, { preserveState: true, replace: true })
})

watch(() => props.clubId, (id) => {
    nodeForm.club_id = id
    lightForm.club_id = id
})

watch(() => props.interactiveEvents, (rows) => {
    eventDrafts.value = cloneEvents(rows)
}, { deep: true })

watch(() => props.tab, (tab) => {
    activeTab.value = tab === 'interactive' ? 'interactive' : 'nodes'
})

watch(() => (page.props as any).flash?.success as string | undefined, (msg) => {
    if (msg) success(msg)
}, { immediate: true })

const goTab = (tab: 'nodes' | 'interactive') => {
    activeTab.value = tab
    router.get('/admin/lights', {
        club_id: selectedClubId.value,
        tab: tab === 'interactive' ? 'interactive' : undefined,
    }, { preserveState: true, replace: true, preserveScroll: true })
}

const nodeForm = useForm({
    club_id: props.clubId,
    name: '',
    host: '',
    port: props.defaults.port,
    universe: 0,
    is_active: true,
})

const lightForm = useForm({
    club_id: props.clubId,
    computer_id: null as number | null,
    space_id: null as number | null,
    dmx_node_id: null as number | null,
    start_channel: 1,
    fixture_count: 1,
    layout: 'rgb',
})

const eventsForm = useForm({
    club_id: props.clubId,
    events: {} as Record<string, EventSettings>,
})

const freeSpaces = computed(() => props.spaces.filter((s: any) => !s.has_light))

const eventGroups = computed(() => {
    const groups: { title: string, items: InteractiveEvent[] }[] = []
    for (const row of eventDrafts.value) {
        const last = groups[groups.length - 1]
        if (!last || last.title !== row.group) {
            groups.push({ title: row.group, items: [row] })
        } else {
            last.items.push(row)
        }
    }
    return groups
})

const solidColors = computed(() => (props.colorOptions || ['white', 'red', 'blue', 'green', 'yellow', 'purple', 'rainbow'])
    .filter((c) => c !== 'rainbow'))

const submitNode = () => {
    nodeForm.club_id = selectedClubId.value
    nodeForm.post('/admin/lights/nodes', {
        onSuccess: () => nodeForm.reset('name', 'host'),
        onError: () => error('Не удалось добавить узел'),
    })
}

const submitLight = () => {
    lightForm.club_id = selectedClubId.value
    lightForm.post('/admin/lights', {
        onSuccess: () => {
            lightForm.reset('computer_id', 'space_id', 'start_channel')
            lightForm.fixture_count = 1
            lightForm.start_channel = 1
        },
        onError: () => error('Не удалось привязать свет'),
    })
}

const saveEvents = () => {
    const map: Record<string, EventSettings> = {}
    for (const row of eventDrafts.value) {
        map[row.id] = { ...row.settings, cycle_colors: [...row.settings.cycle_colors] }
    }
    eventsForm.club_id = selectedClubId.value
    eventsForm.events = map
    eventsForm.post('/admin/lights/events', {
        preserveScroll: true,
        onError: () => error('Не удалось сохранить события'),
    })
}

const colorDot = (color: string) => {
    const map: Record<string, string> = {
        white: '#f8fafc',
        red: '#ef4444',
        blue: '#3b82f6',
        green: '#22c55e',
        yellow: '#eab308',
        purple: '#a855f7',
        rainbow: 'conic-gradient(red, yellow, lime, cyan, blue, magenta, red)',
        auto: 'transparent',
    }
    return map[color] || '#64748b'
}

const colorLabel = (color: string) => {
    const map: Record<string, string> = {
        white: 'белый',
        red: 'красный',
        blue: 'синий',
        green: 'зелёный',
        yellow: 'жёлтый',
        purple: 'фиолетовый',
        rainbow: 'радуга',
        auto: 'авто',
    }
    return map[color] || color
}

const layoutLabel = (l: string) => {
    if (l === 'dimmer_rgb') return 'Dimmer + RGB'
    if (l === 'rgbw') return 'RGBW'
    return 'RGB'
}

const setEventColor = (row: InteractiveEvent, color: string) => {
    row.settings.color = color
    if (color === 'rainbow') {
        row.settings.effect = 'rainbow'
    } else if (row.settings.effect === 'rainbow') {
        row.settings.effect = 'none'
    }
}

const setEventEffect = (row: InteractiveEvent, effect: string) => {
    row.settings.effect = effect
    if (effect === 'rainbow') {
        row.settings.color = 'rainbow'
    } else if (row.settings.color === 'rainbow') {
        row.settings.color = 'white'
    }
}

const toggleCycleColor = (row: InteractiveEvent, color: string) => {
    const list = row.settings.cycle_colors
    const i = list.indexOf(color)
    if (i >= 0) {
        list.splice(i, 1)
        return
    }
    if (list.length >= 8) return
    list.push(color)
}

const summary = (row: InteractiveEvent) => {
    const s = row.settings
    if (!s.enabled) return 'выкл'
    const bits = [colorLabel(s.color)]
    if (s.effect === 'rainbow') bits[0] = 'радуга'
    if (s.effect === 'cycle') bits.push('смена цветов')
    if (s.strobe) bits.push('строб')
    bits.push(s.duration_sec > 0 ? `${s.duration_sec} с` : 'держать')
    bits.push(`fade ${s.fade_sec} с`)
    return bits.join(' · ')
}
</script>

<template>
    <Head :title="`${clubName} | Свет DMX`" />
    <AdminLayout>
        <div class="p-8 max-w-6xl mx-auto font-mono text-white space-y-8">
            <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
                <div>
                    <h1 class="text-4xl font-black italic tracking-tighter uppercase text-cyan-500">Свет DMX</h1>
                    <p class="text-[10px] text-white/30 uppercase tracking-widest font-black mt-2">
                        Art-Net UDP · комната = все ПК · shell шлёт пакеты по LAN
                    </p>
                </div>
                <select v-model.number="selectedClubId"
                        class="bg-black border border-white/10 rounded-xl px-4 py-3 text-sm outline-none focus:border-cyan-500">
                    <option v-for="c in clubs" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
            </div>

            <div class="flex flex-wrap gap-2">
                <button type="button"
                        class="px-5 py-3 rounded-2xl text-[10px] font-black uppercase tracking-widest border"
                        :class="activeTab === 'nodes' ? 'bg-cyan-500 text-black border-cyan-500' : 'border-white/15 text-white/50 hover:text-white'"
                        @click="goTab('nodes')">
                    Узлы и комнаты
                </button>
                <button type="button"
                        class="px-5 py-3 rounded-2xl text-[10px] font-black uppercase tracking-widest border"
                        :class="activeTab === 'interactive' ? 'bg-cyan-500 text-black border-cyan-500' : 'border-white/15 text-white/50 hover:text-white'"
                        @click="goTab('interactive')">
                    Интерактивный свет
                </button>
            </div>

            <div v-if="activeTab === 'nodes'" class="space-y-8">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <div class="bg-[#0a0a0a] border border-white/5 rounded-[1rem] p-8 space-y-4">
                    <h3 class="text-lg font-black uppercase italic">Art-Net узел</h3>
                    <form @submit.prevent="submitNode" class="space-y-3">
                        <input v-model="nodeForm.name" type="text" placeholder="Имя (Hall A lights)"
                               class="w-full bg-black border border-white/10 rounded-xl p-4 text-sm outline-none focus:border-cyan-500" required />
                        <input v-model="nodeForm.host" type="text" placeholder="IP (192.168.20.50)"
                               class="w-full bg-black border border-white/10 rounded-xl p-4 text-sm outline-none focus:border-cyan-500" required />
                        <div class="grid grid-cols-2 gap-3">
                            <input v-model.number="nodeForm.port" type="number" min="1" max="65535"
                                   placeholder="UDP 6454"
                                   class="w-full bg-black border border-white/10 rounded-xl p-4 text-sm outline-none focus:border-cyan-500" />
                            <input v-model.number="nodeForm.universe" type="number" min="0" max="32767"
                                   placeholder="Universe 0"
                                   class="w-full bg-black border border-white/10 rounded-xl p-4 text-sm outline-none focus:border-cyan-500" />
                        </div>
                        <button type="submit" class="w-full py-4 bg-cyan-500 text-black font-black uppercase text-[10px] rounded-xl tracking-widest">
                            Добавить узел
                        </button>
                    </form>

                    <div class="pt-4 space-y-3">
                        <div v-for="n in nodes" :key="n.id"
                             class="flex items-center justify-between gap-3 border border-white/10 rounded-xl px-4 py-3">
                            <div>
                                <div class="text-sm font-bold">{{ n.name }}</div>
                                <div class="text-[10px] text-white/40 uppercase tracking-widest">
                                    {{ n.host }}:{{ n.port }} · uni {{ n.universe }}
                                    <span v-if="!n.is_active" class="text-amber-400"> · выкл</span>
                                </div>
                            </div>
                            <button type="button"
                                    class="text-[10px] uppercase tracking-widest text-red-400 hover:text-red-300"
                                    @click="router.delete(`/admin/lights/nodes/${n.id}`)">
                                Удалить
                            </button>
                        </div>
                        <p v-if="!nodes.length" class="text-xs text-white/30">Сначала добавьте Art-Net / DMX контроллер.</p>
                    </div>
                </div>

                <div class="bg-[#0a0a0a] border border-white/5 rounded-[1rem] p-8 space-y-4">
                    <h3 class="text-lg font-black uppercase italic">Привязать комнату</h3>
                    <form @submit.prevent="submitLight" class="space-y-3">
                        <select v-model.number="lightForm.space_id"
                                class="w-full bg-black border border-white/10 rounded-xl p-4 text-sm outline-none focus:border-cyan-500">
                            <option :value="null">Комната</option>
                            <option v-for="s in freeSpaces" :key="s.id" :value="s.id">
                                {{ s.name }} <span v-if="s.zone_name">({{ s.zone_name }})</span>
                            </option>
                        </select>
                        <select v-model.number="lightForm.computer_id"
                                class="w-full bg-black border border-white/10 rounded-xl p-4 text-sm outline-none focus:border-cyan-500">
                            <option :value="null">или ПК (комната из setup)</option>
                            <option v-for="pc in computers" :key="pc.id" :value="pc.id">
                                {{ pc.name }} <span v-if="pc.space_name">→ {{ pc.space_name }}</span>
                            </option>
                        </select>
                        <select v-model.number="lightForm.dmx_node_id"
                                class="w-full bg-black border border-white/10 rounded-xl p-4 text-sm outline-none focus:border-cyan-500" required>
                            <option :value="null">Art-Net узел</option>
                            <option v-for="n in nodes" :key="n.id" :value="n.id">{{ n.name }} · {{ n.host }}</option>
                        </select>
                        <div class="grid grid-cols-3 gap-3">
                            <input v-model.number="lightForm.start_channel" type="number" min="1" max="512"
                                   placeholder="Ch 1"
                                   class="w-full bg-black border border-white/10 rounded-xl p-4 text-sm outline-none focus:border-cyan-500" />
                            <input v-model.number="lightForm.fixture_count" type="number" min="1" max="170"
                                   placeholder="Приборы"
                                   class="w-full bg-black border border-white/10 rounded-xl p-4 text-sm outline-none focus:border-cyan-500" />
                            <select v-model="lightForm.layout"
                                    class="w-full bg-black border border-white/10 rounded-xl p-4 text-sm outline-none focus:border-cyan-500">
                                <option value="rgb">RGB</option>
                                <option value="dimmer_rgb">Dimmer+RGB</option>
                                <option value="rgbw">RGBW</option>
                            </select>
                        </div>
                        <p v-if="lightForm.errors.start_channel" class="text-xs text-red-400">{{ lightForm.errors.start_channel }}</p>
                        <p v-if="lightForm.errors.space_id" class="text-xs text-red-400">{{ lightForm.errors.space_id }}</p>
                        <button type="submit" class="w-full py-4 bg-cyan-500 text-black font-black uppercase text-[10px] rounded-xl tracking-widest">
                            Привязать свет
                        </button>
                    </form>
                </div>
            </div>

            <div class="bg-[#0a0a0a] border border-white/5 rounded-[1rem] p-8 space-y-4">
                <h3 class="text-lg font-black uppercase italic">Комнаты</h3>
                <div v-if="!lights.length" class="text-xs text-white/30">Нет привязок — шелл покажет плитку серой.</div>
                <div v-for="l in lights" :key="l.id"
                     class="flex flex-col md:flex-row md:items-center justify-between gap-3 border border-white/10 rounded-xl px-4 py-4">
                    <div class="flex items-center gap-3 min-w-0">
                        <span class="w-5 h-5 rounded-full shrink-0 border border-white/20"
                              :style="{ background: colorDot(l.desired_color) }" />
                        <div class="min-w-0">
                            <div class="text-sm font-bold truncate">{{ l.space?.name || ('Space #' + l.space_id) }}</div>
                            <div class="text-[10px] text-white/40 uppercase tracking-widest">
                                {{ l.dmx_node?.name }} · ch {{ l.start_channel }} ×{{ l.fixture_count }}
                                · {{ layoutLabel(l.layout) }}
                                · {{ l.desired_effect === 'rainbow' ? 'rainbow' : l.desired_color }}
                                · {{ l.desired_brightness }}%
                            </div>
                        </div>
                    </div>
                    <button type="button"
                            class="text-[10px] uppercase tracking-widest text-red-400 hover:text-red-300"
                            @click="router.delete(`/admin/lights/${l.id}`)">
                        Отвязать
                    </button>
                </div>
            </div>
            </div>

            <div v-else class="space-y-6">
                <div class="bg-[#0a0a0a] border border-white/5 rounded-[1rem] p-6">
                    <h2 class="text-xl font-black uppercase italic text-cyan-400">Интерактивный свет</h2>
                    <p class="text-white/40 text-xs mt-2 leading-relaxed">
                        Длительность 0 — держать до следующего события (бомба, выключен).
                        Цвет или эффект — чем светить. Строб — вспышки вкл/выкл.
                        Смена цветов — чередование. Плавность — fade в секундах.
                    </p>
                </div>

                <section v-for="group in eventGroups" :key="group.title" class="space-y-3">
                    <div class="text-[10px] uppercase tracking-[0.3em] text-white/35 font-black italic px-1">
                        {{ group.title }}
                    </div>
                    <div v-for="row in group.items" :key="row.id"
                         class="bg-[#0a0a0a] border border-white/5 rounded-[1rem] overflow-hidden">
                        <button type="button"
                                class="w-full flex items-center gap-4 px-5 py-4 text-left hover:bg-white/[0.02]"
                                @click="openEventId = openEventId === row.id ? '' : row.id">
                            <span class="w-5 h-5 rounded-full shrink-0 border border-white/20"
                                  :style="{ background: colorDot(row.settings.color) }" />
                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-bold truncate">{{ row.title }}</div>
                                <div class="text-[10px] text-white/35 uppercase tracking-widest truncate">
                                    {{ summary(row) }}
                                </div>
                            </div>
                            <label class="shrink-0" @click.stop>
                                <input v-model="row.settings.enabled" type="checkbox"
                                       class="accent-cyan-500 w-4 h-4" />
                            </label>
                            <span class="text-white/25 text-xs">{{ openEventId === row.id ? '▴' : '▾' }}</span>
                        </button>

                        <div v-if="openEventId === row.id" class="px-5 pb-6 space-y-5 border-t border-white/5 pt-5">
                            <p class="text-[11px] text-white/40 leading-relaxed">{{ row.hint }}</p>

                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <label class="block space-y-2">
                                    <span class="text-[10px] uppercase tracking-widest text-white/40 font-black">Длительность, с</span>
                                    <input v-model.number="row.settings.duration_sec" type="number" min="0" max="120" step="0.1"
                                           class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm outline-none focus:border-cyan-500" />
                                    <span class="text-[10px] text-white/30">0 = держать</span>
                                </label>
                                <label class="block space-y-2">
                                    <span class="text-[10px] uppercase tracking-widest text-white/40 font-black">Плавность, с</span>
                                    <input v-model.number="row.settings.fade_sec" type="number" min="0" max="30" step="0.1"
                                           class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm outline-none focus:border-cyan-500" />
                                </label>
                                <label class="block space-y-2">
                                    <span class="text-[10px] uppercase tracking-widest text-white/40 font-black">Яркость, %</span>
                                    <input v-model.number="row.settings.brightness" type="number" min="0" max="100" step="1"
                                           class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm outline-none focus:border-cyan-500" />
                                </label>
                            </div>

                            <div class="space-y-2">
                                <div class="text-[10px] uppercase tracking-widest text-white/40 font-black">Цвет эффекта</div>
                                <div class="flex flex-wrap gap-2">
                                    <button v-if="row.allow_auto" type="button"
                                            class="px-3 py-2 rounded-xl border text-[10px] font-black uppercase tracking-widest"
                                            :class="row.settings.color === 'auto' ? 'border-cyan-500 text-cyan-300' : 'border-white/10 text-white/40'"
                                            @click="setEventColor(row, 'auto')">
                                        авто
                                    </button>
                                    <button v-for="c in solidColors" :key="c" type="button"
                                            class="w-9 h-9 rounded-full border-2"
                                            :class="row.settings.color === c ? 'border-cyan-400 scale-110' : 'border-white/15'"
                                            :style="{ background: colorDot(c) }"
                                            :title="colorLabel(c)"
                                            @click="setEventColor(row, c)" />
                                    <button type="button"
                                            class="w-9 h-9 rounded-full border-2"
                                            :class="row.settings.color === 'rainbow' || row.settings.effect === 'rainbow' ? 'border-cyan-400 scale-110' : 'border-white/15'"
                                            :style="{ background: colorDot('rainbow') }"
                                            title="радуга"
                                            @click="setEventEffect(row, 'rainbow')" />
                                </div>
                            </div>

                            <div class="space-y-2">
                                <div class="text-[10px] uppercase tracking-widest text-white/40 font-black">Эффект</div>
                                <div class="flex flex-wrap gap-2">
                                    <button type="button"
                                            class="px-4 py-2 rounded-xl border text-[10px] font-black uppercase tracking-widest"
                                            :class="row.settings.effect === 'none' ? 'bg-cyan-500 text-black border-cyan-500' : 'border-white/10 text-white/50'"
                                            @click="setEventEffect(row, 'none')">
                                        цвет
                                    </button>
                                    <button type="button"
                                            class="px-4 py-2 rounded-xl border text-[10px] font-black uppercase tracking-widest"
                                            :class="row.settings.effect === 'rainbow' ? 'bg-cyan-500 text-black border-cyan-500' : 'border-white/10 text-white/50'"
                                            @click="setEventEffect(row, 'rainbow')">
                                        радуга
                                    </button>
                                    <button type="button"
                                            class="px-4 py-2 rounded-xl border text-[10px] font-black uppercase tracking-widest"
                                            :class="row.settings.effect === 'cycle' ? 'bg-cyan-500 text-black border-cyan-500' : 'border-white/10 text-white/50'"
                                            @click="setEventEffect(row, 'cycle')">
                                        смена цветов
                                    </button>
                                </div>
                            </div>

                            <div v-if="row.settings.effect === 'cycle'" class="space-y-3">
                                <div class="text-[10px] uppercase tracking-widest text-white/40 font-black">Какие цвета чередовать</div>
                                <div class="flex flex-wrap gap-2">
                                    <button v-for="c in solidColors" :key="'cyc-'+c" type="button"
                                            class="flex items-center gap-2 px-3 py-2 rounded-xl border text-[10px] font-black uppercase tracking-widest"
                                            :class="row.settings.cycle_colors.includes(c) ? 'border-cyan-500 text-white' : 'border-white/10 text-white/40'"
                                            @click="toggleCycleColor(row, c)">
                                        <span class="w-3 h-3 rounded-full border border-white/20"
                                              :style="{ background: colorDot(c) }" />
                                        {{ colorLabel(c) }}
                                    </button>
                                </div>
                                <label class="block space-y-2 max-w-xs">
                                    <span class="text-[10px] uppercase tracking-widest text-white/40 font-black">Светить каждым, с</span>
                                    <input v-model.number="row.settings.cycle_hold_sec" type="number" min="0.05" max="30" step="0.05"
                                           class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm outline-none focus:border-cyan-500" />
                                </label>
                            </div>

                            <div class="space-y-3">
                                <label class="flex items-center gap-3 text-sm">
                                    <input v-model="row.settings.strobe" type="checkbox" class="accent-cyan-500 w-4 h-4" />
                                    Стробоскоп
                                </label>
                                <div v-if="row.settings.strobe" class="grid grid-cols-2 gap-4 max-w-md">
                                    <label class="block space-y-2">
                                        <span class="text-[10px] uppercase tracking-widest text-white/40 font-black">Вкл, мс</span>
                                        <input v-model.number="row.settings.strobe_on_ms" type="number" min="0" max="5000" step="10"
                                               class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm outline-none focus:border-cyan-500" />
                                    </label>
                                    <label class="block space-y-2">
                                        <span class="text-[10px] uppercase tracking-widest text-white/40 font-black">Выкл, мс</span>
                                        <input v-model.number="row.settings.strobe_off_ms" type="number" min="0" max="5000" step="10"
                                               class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm outline-none focus:border-cyan-500" />
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <button type="button"
                        class="w-full sm:w-auto px-8 py-4 bg-cyan-500 text-black font-black uppercase text-[10px] rounded-xl tracking-widest disabled:opacity-40"
                        :disabled="eventsForm.processing"
                        @click="saveEvents">
                    Сохранить события
                </button>
            </div>
        </div>
    </AdminLayout>
</template>
