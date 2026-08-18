<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const props = defineProps<{
    clubs: Array<{ id: number, name: string }>
    clubId: number
    boards: any[]
    fans: any[]
    sharedFans?: any[]
    linkedPersonalIds?: number[]
    spaces: any[]
    computers?: Array<{
        id: number
        name: string
        space_id: number | null
        space_name?: string | null
        type?: string
        x: number
        y: number
    }>
    mapPreview?: { viewbox?: string | null, walls?: any[], labels?: any[] }
    defaults: {
        port: number
        thermal_on_c: number
        thermal_off_c: number
        max_per_space?: number
        load_steps?: number[]
    }
}>()

const maxPerSpace = computed(() => props.defaults.max_per_space ?? 2)
const loadSteps = computed(() => props.defaults.load_steps ?? [50, 60, 70, 80, 90, 100])
const sharedFans = computed(() => props.sharedFans || [])
const linkedPersonalIds = computed(() => new Set(props.linkedPersonalIds || []))
const selectedClubId = ref(props.clubId || props.clubs[0]?.id || 0)
const selectedSpaceId = ref<number | null>(null)
const selectedComputerId = ref<number | null>(null)
const expandedSharedId = ref<number | null>(null)

watch(selectedClubId, (id) => {
    selectedSpaceId.value = null
    selectedComputerId.value = null
    fanForm.space_id = null
    fanForm.computer_id = null
    router.get('/admin/fans', { club_id: id }, { preserveState: true, replace: true })
})

const boardForm = useForm({
    club_id: props.clubId,
    name: '',
    host: '',
    port: props.defaults.port,
    is_active: true,
})

watch(() => props.clubId, (id) => {
    boardForm.club_id = id
    fanForm.club_id = id
    sharedForm.club_id = id
})

const fanForm = useForm({
    club_id: props.clubId,
    computer_id: null as number | null,
    space_id: null as number | null,
    relay_board_id: null as number | null,
    channel: 1,
    channel2: 2,
    thermal_on_c: props.defaults.thermal_on_c,
    thermal_off_c: props.defaults.thermal_off_c,
})

const sharedForm = useForm({
    club_id: props.clubId,
    kind: 'supply' as 'supply' | 'exhaust',
    name: '',
    relay_board_id: null as number | null,
    channel: 1,
    channel2: 2,
})

const linkForm = useForm({
    space_fan_id: null as number | null,
})

const mapDrafts = ref<Record<number, Array<{ load_pct: number, output_pct: number }>>>({})

watch(sharedFans, (list) => {
    const next: Record<number, Array<{ load_pct: number, output_pct: number }>> = {}
    for (const sf of list) {
        next[sf.id] = loadSteps.value.map((load) => {
            const row = (sf.maps || []).find((m: any) => Number(m.load_pct) === load)
            return { load_pct: load, output_pct: Number(row?.output_pct ?? 50) }
        })
    }
    mapDrafts.value = next
}, { immediate: true })

const freeForExhaust = (sharedId: number) =>
    props.fans.filter((f: any) => {
        const linked = linkedPersonalIds.value.has(Number(f.id))
        const mine = (sharedFans.value.find((s: any) => s.id === sharedId)?.linked_fans || [])
            .some((l: any) => Number(l.id) === Number(f.id))
        return !linked || mine
    })

const speedLabel = (p: number) => (Number(p) >= 3 ? '100%' : Number(p) === 2 ? '75%' : '50%')
const kindLabel = (k: string) => (k === 'exhaust' ? 'Вытяжка' : 'Приток')

const spaceFanCount = (s: any) => Number(s.fans_count ?? (s.has_fan ? 1 : 0))
const spaceCanAddFan = (s: any) => spaceFanCount(s) < maxPerSpace.value

const computers = computed(() => props.computers || [])

const pickComputer = (computerId: number) => {
    const pc = computers.value.find((c) => Number(c.id) === Number(computerId))
    if (!pc) return
    selectedComputerId.value = pc.id
    fanForm.computer_id = pc.id
    selectedSpaceId.value = pc.space_id
    fanForm.space_id = pc.space_id
}

const pickSpace = (spaceId: number, allowTaken = false) => {
    const space = props.spaces.find((s: any) => Number(s.id) === Number(spaceId))
    if (!space) return
    if (!spaceCanAddFan(space) && !allowTaken) return
    const inRoom = computers.value.filter((c) => Number(c.space_id) === Number(spaceId))
    if (inRoom.length) {
        pickComputer(inRoom[0].id)
        return
    }
    selectedSpaceId.value = spaceId
    fanForm.space_id = spaceId
    selectedComputerId.value = null
    fanForm.computer_id = null
}

const submitBoard = () => {
    boardForm.club_id = selectedClubId.value
    boardForm.post('/admin/fans/boards', {
        onSuccess: () => boardForm.reset('name', 'host'),
    })
}

const submitFan = () => {
    fanForm.club_id = selectedClubId.value
    if (!fanForm.computer_id) {
        fanForm.computer_id = selectedComputerId.value
    }
    if (!fanForm.space_id) {
        fanForm.space_id = selectedSpaceId.value
    }
    // Enforce cascade pair even if form was tampered with
    if (fanForm.channel % 2 === 1) {
        fanForm.channel2 = fanForm.channel + 1
    }
    fanForm.post('/admin/fans', {
        onSuccess: () => {
            fanForm.reset('channel', 'channel2')
            fanForm.channel = 1
            fanForm.channel2 = 2
            selectedSpaceId.value = null
            selectedComputerId.value = null
            fanForm.space_id = null
            fanForm.computer_id = null
        },
    })
}

const deleteBoard = (id: number) => {
    if (confirm('Удалить плату и связанные вентиляторы?')) {
        router.delete(`/admin/fans/boards/${id}`)
    }
}

const deleteFan = (id: number) => {
    if (confirm('Удалить вентилятор этой комнаты?')) {
        router.delete(`/admin/fans/${id}`)
    }
}

const submitShared = () => {
    sharedForm.club_id = selectedClubId.value
    if (sharedForm.channel % 2 === 1) {
        sharedForm.channel2 = sharedForm.channel + 1
    }
    sharedForm.post('/admin/fans/shared', {
        onSuccess: () => {
            sharedForm.reset('name')
            sharedForm.channel = 1
            sharedForm.channel2 = 2
            sharedForm.kind = 'supply'
        },
    })
}

const saveMaps = (sharedId: number) => {
    router.put(`/admin/fans/shared/${sharedId}/maps`, {
        maps: mapDrafts.value[sharedId] || [],
    }, { preserveScroll: true })
}

const linkPersonal = (sharedId: number) => {
    if (!linkForm.space_fan_id) return
    linkForm.post(`/admin/fans/shared/${sharedId}/link`, {
        onSuccess: () => { linkForm.space_fan_id = null },
        preserveScroll: true,
    })
}

const unlinkPersonal = (sharedId: number, spaceFanId: number) => {
    router.post(`/admin/fans/shared/${sharedId}/unlink`, {
        space_fan_id: spaceFanId,
    }, { preserveScroll: true })
}

const deleteShared = (id: number) => {
    if (confirm('Удалить общий вентилятор?')) {
        router.delete(`/admin/fans/shared/${id}`)
    }
}

const freeSpaces = computed(() =>
    props.spaces.filter((s: any) => spaceCanAddFan(s))
)

const selectedSpace = computed(() =>
    props.spaces.find((s: any) => Number(s.id) === Number(selectedSpaceId.value)) || null
)

const selectedComputer = computed(() =>
    computers.value.find((c) => Number(c.id) === Number(selectedComputerId.value)) || null
)

const computerMarkers = computed(() => {
    return computers.value.map((pc) => {
        const space = props.spaces.find((s: any) => Number(s.id) === Number(pc.space_id))
        const hasPoint = Number(pc.x) !== 0 || Number(pc.y) !== 0
        const x = hasPoint ? Number(pc.x) : (space ? Number(space.x) + Number(space.w) / 2 : 0)
        const y = hasPoint ? Number(pc.y) : (space ? Number(space.y) + Number(space.h) / 2 : 0)
        return { ...pc, mx: x, my: y }
    }).filter((pc) => pc.mx !== 0 || pc.my !== 0)
})

/** Плотный viewBox по комнатам — не берём высокий viewbox редактора, иначе пустота снизу. */
const mapViewBox = computed(() => {
    const list = props.spaces.filter((s: any) => Number(s.w) > 0 && Number(s.h) > 0)
    if (!list.length) return '0 0 100 60'

    let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity
    for (const s of list) {
        minX = Math.min(minX, Number(s.x))
        minY = Math.min(minY, Number(s.y))
        maxX = Math.max(maxX, Number(s.x) + Number(s.w))
        maxY = Math.max(maxY, Number(s.y) + Number(s.h))
    }
    const pad = 2
    const w = Math.max(1, maxX - minX + pad * 2)
    const h = Math.max(1, maxY - minY + pad * 2)
    return `${minX - pad} ${minY - pad} ${w} ${h}`
})

const mapAspectStyle = computed(() => {
    const parts = String(mapViewBox.value).trim().split(/[\s,]+/).map(Number)
    const w = parts[2] > 0 ? parts[2] : 100
    const h = parts[3] > 0 ? parts[3] : 60
    return { aspectRatio: `${w} / ${h}` }
})

const mapWalls = computed(() => (props.mapPreview?.walls || []).filter((w: any) => w?.d))

const labelFontSize = (s: any) => {
    const w = Math.max(1, Number(s.w) || 1)
    const h = Math.max(1, Number(s.h) || 1)
    return Math.max(1.2, Math.min(3.2, Math.min(w, h) * 0.28))
}

const spaceFill = (s: any) => {
    if (selectedSpaceId.value === s.id) return 'rgba(6,182,212,0.45)'
    const n = spaceFanCount(s)
    if (n >= maxPerSpace.value) return 'rgba(34,197,94,0.22)'
    if (n > 0) return 'rgba(34,197,94,0.12)'
    return (s.zone_color || '#22c55e') + '33'
}

const spaceStroke = (s: any) => {
    if (selectedSpaceId.value === s.id) return '#22d3ee'
    if (spaceFanCount(s) > 0) return '#22c55e'
    return s.zone_color || '#64748b'
}
</script>

<template>
    <AdminLayout>
        <div class="p-8 max-w-6xl mx-auto font-mono text-white space-y-8">
            <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
                <div>
                    <h1 class="text-4xl font-black italic tracking-tighter uppercase text-cyan-500">Вентиляторы</h1>
                    <p class="text-[10px] text-white/30 uppercase tracking-widest font-black mt-2">
                        W5100 · K1+K2 каскад 120/170/220 В · shell на LAN
                    </p>
                </div>
                <select v-model.number="selectedClubId"
                        class="bg-black border border-white/10 rounded-xl px-4 py-3 text-sm outline-none focus:border-cyan-500">
                    <option v-for="c in clubs" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <div class="bg-[#0a0a0a] border border-white/5 rounded-[1rem] p-8 space-y-4">
                    <h3 class="text-lg font-black uppercase italic">Новая плата W5100</h3>
                    <form @submit.prevent="submitBoard" class="space-y-3">
                        <input v-model="boardForm.name" type="text" placeholder="Имя (Hall A relays)"
                               class="w-full bg-black border border-white/10 rounded-xl p-4 text-sm outline-none focus:border-cyan-500" required />
                        <input v-model="boardForm.host" type="text" placeholder="IP (192.168.1.4)"
                               class="w-full bg-black border border-white/10 rounded-xl p-4 text-sm outline-none focus:border-cyan-500" required />
                        <input v-model.number="boardForm.port" type="number" min="1" max="65535"
                               placeholder="Путь-порт (30000 → http://IP/30000/…)"
                               class="w-full bg-black border border-white/10 rounded-xl p-4 text-sm outline-none focus:border-cyan-500" />
                        <button type="submit" class="w-full py-4 bg-cyan-500 text-black font-black uppercase text-[10px] rounded-xl tracking-widest">
                            Добавить плату
                        </button>
                    </form>

                    <div class="pt-4 space-y-3">
                        <div v-for="b in boards" :key="b.id"
                             class="flex items-center justify-between gap-3 p-4 rounded-2xl border border-white/5 bg-black/40">
                            <div>
                                <div class="text-sm font-black uppercase italic">{{ b.name }}</div>
                                <div class="text-[10px] text-white/40 font-mono mt-1">http://{{ b.host }}/{{ b.port }}/ · {{ b.driver }}</div>
                            </div>
                            <button @click="deleteBoard(b.id)" class="text-red-500/50 hover:text-red-500 text-[10px] font-black uppercase">
                                Удалить
                            </button>
                        </div>
                        <div v-if="!boards.length" class="text-[10px] text-white/20 uppercase tracking-widest italic py-6 text-center border border-dashed border-white/5 rounded-2xl">
                            Платы не заведены
                        </div>
                    </div>
                </div>

                <div class="bg-[#0a0a0a] border border-white/5 rounded-[1rem] p-8 space-y-4">
                    <h3 class="text-lg font-black uppercase italic">Привязать вентилятор</h3>
                    <p class="text-[10px] text-white/30 uppercase tracking-wider">
                        Комната берётся из setup шелла · до {{ maxPerSpace }} на комнату · каналы K1/K2
                    </p>
                    <form @submit.prevent="submitFan" class="space-y-3">
                        <select v-model.number="fanForm.relay_board_id"
                                class="w-full bg-black border border-white/10 rounded-xl p-4 text-sm outline-none focus:border-cyan-500" required>
                            <option :value="null" disabled>Плата</option>
                            <option v-for="b in boards" :key="b.id" :value="b.id">{{ b.name }} ({{ b.host }})</option>
                        </select>
                        <select v-model.number="fanForm.channel"
                                class="w-full bg-black border border-white/10 rounded-xl p-4 text-sm outline-none focus:border-cyan-500"
                                required
                                @change="fanForm.channel2 = fanForm.channel + 1">
                            <option v-for="k1 in [1,3,5,7,9,11,13,15]" :key="k1" :value="k1">
                                K{{ k1 }}+K{{ k1 + 1 }}
                            </option>
                        </select>
                        <div class="grid grid-cols-2 gap-3">
                            <input v-model.number="fanForm.thermal_on_c" type="number" placeholder="ON °C"
                                   class="bg-black border border-white/10 rounded-xl p-4 text-sm outline-none focus:border-cyan-500" />
                            <input v-model.number="fanForm.thermal_off_c" type="number" placeholder="OFF °C"
                                   class="bg-black border border-white/10 rounded-xl p-4 text-sm outline-none focus:border-cyan-500" />
                        </div>
                        <select v-model.number="fanForm.computer_id"
                                class="w-full bg-black border border-white/10 rounded-xl p-4 text-sm outline-none focus:border-cyan-500"
                                required
                                @change="fanForm.computer_id && pickComputer(fanForm.computer_id)">
                            <option :value="null" disabled>ПК (зона из setup шелла)</option>
                            <option v-for="pc in computers" :key="'pc'+pc.id" :value="pc.id">
                                {{ pc.name }} · {{ pc.space_name || (pc.space_id ? ('space #' + pc.space_id) : (pc.type || 'без комнаты')) }}
                            </option>
                        </select>
                        <div class="rounded-2xl border border-cyan-500/20 bg-cyan-500/5 p-4 text-[10px] uppercase tracking-wider">
                            Комната:
                            <span class="text-cyan-400 font-black ml-2">
                                <template v-if="selectedComputer">
                                    {{ selectedComputer.name }}
                                    <span class="text-white/40 font-bold normal-case tracking-normal ml-2">
                                        {{ selectedComputer.space_name || selectedSpace?.name || selectedComputer.type || 'подтянется из setup' }}
                                    </span>
                                </template>
                                <template v-else-if="selectedSpace">
                                    space #{{ selectedSpace.id }}
                                    <span class="text-white/40 font-bold normal-case tracking-normal ml-2">
                                        {{ selectedSpace.name }} · {{ selectedSpace.zone_name || 'zone' }}
                                    </span>
                                </template>
                                <template v-else>выберите ПК</template>
                            </span>
                        </div>
                        <button type="submit" :disabled="!fanForm.computer_id && !selectedComputerId && !fanForm.space_id && !selectedSpaceId"
                                class="w-full py-4 bg-[#22c55e] text-black font-black uppercase text-[10px] rounded-xl tracking-widest disabled:opacity-30">
                            Завести вентилятор
                        </button>
                    </form>

                    <div class="pt-2 space-y-2">
                        <div class="flex flex-wrap gap-3 text-[9px] font-black uppercase tracking-widest text-white/40">
                            <span class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-sm bg-cyan-400/80"></span> Выбран ПК</span>
                            <span class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-sm bg-[#22c55e]/50"></span> С вентилятором</span>
                            <span class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-sm bg-white/20"></span> Свободна</span>
                        </div>
                        <div
                            class="w-full overflow-hidden rounded-2xl border border-white/5 bg-black/60"
                            :style="mapAspectStyle"
                        >
                            <svg
                                v-if="spaces.length"
                                class="block w-full h-full"
                                :viewBox="mapViewBox"
                                preserveAspectRatio="xMidYMid meet"
                            >
                                <g class="walls" fill="none" stroke="rgba(255,255,255,0.18)" stroke-width="0.35">
                                    <path v-for="(w, i) in mapWalls" :key="'wall'+i" :d="w.d" />
                                </g>
                                <g class="spaces">
                                    <g v-for="s in spaces" :key="s.id"
                                       :class="spaceCanAddFan(s) ? 'cursor-pointer' : 'cursor-default'"
                                       @click="pickSpace(s.id)">
                                        <rect
                                            :x="s.x" :y="s.y" :width="s.w" :height="s.h"
                                            :fill="spaceFill(s)"
                                            :stroke="spaceStroke(s)"
                                            stroke-width="0.45"
                                            rx="0.4"
                                            class="transition-opacity"
                                            :opacity="!spaceCanAddFan(s) && selectedSpaceId !== s.id ? 0.85 : 1"
                                        />
                                        <text
                                            :x="Number(s.x) + Number(s.w) / 2"
                                            :y="Number(s.y) + Number(s.h) / 2"
                                            text-anchor="middle"
                                            dominant-baseline="central"
                                            fill="white"
                                            :font-size="labelFontSize(s)"
                                            font-weight="900"
                                            class="pointer-events-none select-none"
                                            style="font-family: ui-monospace, monospace;"
                                        >#{{ s.id }}</text>
                                        <text
                                            v-if="Number(s.h) > 6"
                                            :x="Number(s.x) + Number(s.w) / 2"
                                            :y="Number(s.y) + Number(s.h) / 2 + labelFontSize(s) * 1.15"
                                            text-anchor="middle"
                                            dominant-baseline="central"
                                            fill="rgba(255,255,255,0.45)"
                                            :font-size="Math.max(0.9, labelFontSize(s) * 0.55)"
                                            font-weight="700"
                                            class="pointer-events-none select-none uppercase"
                                            style="font-family: ui-monospace, monospace;"
                                        >{{ spaceFanCount(s) > 0 ? ('fan×' + spaceFanCount(s)) : (s.zone_name || s.name) }}</text>
                                    </g>
                                </g>
                                <g class="computers">
                                    <g v-for="pc in computerMarkers" :key="'c'+pc.id"
                                       class="cursor-pointer"
                                       @click.stop="pickComputer(pc.id)">
                                        <circle
                                            :cx="pc.mx" :cy="pc.my" r="1.15"
                                            :fill="selectedComputerId === pc.id ? '#22d3ee' : '#e2e8f0'"
                                            :stroke="selectedComputerId === pc.id ? '#22d3ee' : '#0f172a'"
                                            stroke-width="0.35"
                                        />
                                        <text
                                            :x="pc.mx"
                                            :y="pc.my - 1.6"
                                            text-anchor="middle"
                                            fill="white"
                                            font-size="1.35"
                                            font-weight="800"
                                            class="pointer-events-none select-none"
                                            style="font-family: ui-monospace, monospace;"
                                        >{{ pc.name }}</text>
                                    </g>
                                </g>
                            </svg>
                            <div v-else class="flex items-center justify-center py-12 text-[10px] uppercase tracking-widest text-white/20 italic">
                                Нет rooms / spaces — карта в редакторе
                            </div>
                        </div>
                        <div v-if="!freeSpaces.length && spaces.length"
                             class="text-[10px] text-white/20 uppercase tracking-widest italic py-2 text-center">
                            Все комнаты уже с макс. вентиляторами
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-[#0a0a0a] border border-white/5 rounded-[1rem] p-8">
                <h3 class="text-lg font-black uppercase italic mb-6">Персональные вентиляторы</h3>
                <div class="space-y-3">
                    <div v-for="f in fans" :key="f.id"
                         class="flex flex-col md:flex-row md:items-center justify-between gap-4 p-5 rounded-2xl border border-white/5 bg-black/40">
                        <div>
                            <div class="text-sm font-black uppercase italic">
                                Space #{{ f.space_id }} · {{ f.space?.name || 'room' }}
                            </div>
                            <div class="text-[10px] text-white/40 font-mono mt-1">
                                http://{{ f.relay_board?.host }}/{{ f.relay_board?.port }}/
                                · K1={{ f.channel }} K2={{ f.channel2 }}
                                · speed {{ f.applied_power }}/3 · mode {{ f.manual_mode }}
                                <span v-if="f.shared_fan_link?.shared_fan" class="text-amber-400/80">
                                    · вытяжка: {{ f.shared_fan_link.shared_fan.name }}
                                </span>
                            </div>
                        </div>
                        <button @click="deleteFan(f.id)" class="text-red-500/50 hover:text-red-500 text-[10px] font-black uppercase">
                            Удалить
                        </button>
                    </div>
                    <div v-if="!fans.length" class="py-10 text-center text-white/20 text-[10px] uppercase tracking-widest italic border border-dashed border-white/5 rounded-2xl">
                        Вентиляторы не заведены
                    </div>
                </div>
            </div>

            <div class="bg-[#0a0a0a] border border-white/5 rounded-[1rem] p-8 space-y-6">
                <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-black uppercase italic">Общие (приток / вытяжка)</h3>
                        <p class="text-[10px] text-white/30 uppercase tracking-wider mt-2">
                            Приток = пул avg всех personal · Вытяжка = avg привязанных · MikroTik poll
                        </p>
                    </div>
                </div>

                <form @submit.prevent="submitShared" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-3 items-end">
                    <select v-model="sharedForm.kind"
                            class="bg-black border border-white/10 rounded-xl p-4 text-sm outline-none focus:border-cyan-500">
                        <option value="supply">Приток</option>
                        <option value="exhaust">Вытяжка</option>
                    </select>
                    <input v-model="sharedForm.name" type="text" placeholder="Имя"
                           class="bg-black border border-white/10 rounded-xl p-4 text-sm outline-none focus:border-cyan-500 lg:col-span-2" required />
                    <select v-model.number="sharedForm.relay_board_id"
                            class="bg-black border border-white/10 rounded-xl p-4 text-sm outline-none focus:border-cyan-500" required>
                        <option :value="null" disabled>Плата</option>
                        <option v-for="b in boards" :key="'sb'+b.id" :value="b.id">{{ b.name }}</option>
                    </select>
                    <select v-model.number="sharedForm.channel"
                            class="bg-black border border-white/10 rounded-xl p-4 text-sm outline-none focus:border-cyan-500"
                            required
                            @change="sharedForm.channel2 = sharedForm.channel + 1">
                        <option v-for="k1 in [1,3,5,7,9,11,13,15]" :key="'sk'+k1" :value="k1">
                            K{{ k1 }}+K{{ k1 + 1 }}
                        </option>
                    </select>
                    <button type="submit"
                            class="py-4 bg-amber-400 text-black font-black uppercase text-[10px] rounded-xl tracking-widest">
                        Добавить
                    </button>
                </form>

                <div class="space-y-4">
                    <div v-for="sf in sharedFans" :key="sf.id"
                         class="rounded-2xl border border-white/5 bg-black/40 overflow-hidden">
                        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 p-5">
                            <div>
                                <div class="text-sm font-black uppercase italic">
                                    {{ kindLabel(sf.kind) }} · {{ sf.name }}
                                </div>
                                <div class="text-[10px] text-white/40 font-mono mt-1">
                                    http://{{ sf.relay_board?.host }}/{{ sf.relay_board?.port }}/
                                    · K{{ sf.channel }}+K{{ sf.channel2 }}
                                    · load {{ sf.load_pct }}% → desired {{ speedLabel(sf.desired_power) }}
                                    · applied {{ speedLabel(sf.applied_power) }}
                                </div>
                                <div v-if="sf.kind === 'supply'" class="text-[10px] text-cyan-400/70 mt-1 uppercase tracking-wider">
                                    Пул: среднее по всем персональным клуба
                                </div>
                            </div>
                            <div class="flex items-center gap-4">
                                <button type="button"
                                        class="text-cyan-400/70 hover:text-cyan-300 text-[10px] font-black uppercase"
                                        @click="expandedSharedId = expandedSharedId === sf.id ? null : sf.id">
                                    {{ expandedSharedId === sf.id ? 'Свернуть' : 'Настроить' }}
                                </button>
                                <button type="button" @click="deleteShared(sf.id)"
                                        class="text-red-500/50 hover:text-red-500 text-[10px] font-black uppercase">
                                    Удалить
                                </button>
                            </div>
                        </div>

                        <div v-if="expandedSharedId === sf.id" class="border-t border-white/5 p-5 space-y-5 bg-black/30">
                            <div>
                                <div class="text-[10px] uppercase tracking-widest text-white/40 mb-3">
                                    Сопоставление load → общий (50 или 100)
                                </div>
                                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-2">
                                    <label v-for="(row, idx) in (mapDrafts[sf.id] || [])" :key="row.load_pct"
                                           class="rounded-xl border border-white/10 p-3 space-y-2">
                                        <div class="text-[10px] font-black text-white/50">load {{ row.load_pct }}%</div>
                                        <select v-model.number="mapDrafts[sf.id][idx].output_pct"
                                                class="w-full bg-black border border-white/10 rounded-lg p-2 text-xs outline-none">
                                            <option :value="50">50%</option>
                                            <option :value="100">100%</option>
                                        </select>
                                    </label>
                                </div>
                                <button type="button" @click="saveMaps(sf.id)"
                                        class="mt-3 px-4 py-2 bg-cyan-500 text-black text-[10px] font-black uppercase rounded-xl tracking-widest">
                                    Сохранить map
                                </button>
                            </div>

                            <div v-if="sf.kind === 'exhaust'" class="space-y-3">
                                <div class="text-[10px] uppercase tracking-widest text-white/40">
                                    Привязка персональных
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <span v-for="lf in sf.linked_fans" :key="lf.id"
                                          class="inline-flex items-center gap-2 px-3 py-2 rounded-xl border border-amber-500/30 bg-amber-500/10 text-[10px]">
                                        #{{ lf.id }} space {{ lf.space_id }} {{ lf.label }}
                                        <button type="button" class="text-red-400" @click="unlinkPersonal(sf.id, lf.id)">×</button>
                                    </span>
                                    <span v-if="!sf.linked_fans?.length" class="text-[10px] text-white/25 italic">нет привязок</span>
                                </div>
                                <div class="flex flex-col sm:flex-row gap-2">
                                    <select v-model.number="linkForm.space_fan_id"
                                            class="flex-1 bg-black border border-white/10 rounded-xl p-3 text-sm outline-none">
                                        <option :value="null" disabled>Персональный вентилятор</option>
                                        <option v-for="f in freeForExhaust(sf.id)" :key="'lf'+f.id" :value="f.id">
                                            #{{ f.id }} space {{ f.space_id }} K{{ f.channel }}+K{{ f.channel2 }}
                                        </option>
                                    </select>
                                    <button type="button" @click="linkPersonal(sf.id)"
                                            class="px-4 py-3 bg-[#22c55e] text-black text-[10px] font-black uppercase rounded-xl tracking-widest">
                                        Привязать
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-if="!sharedFans.length"
                         class="py-10 text-center text-white/20 text-[10px] uppercase tracking-widest italic border border-dashed border-white/5 rounded-2xl">
                        Общие вентиляторы не заведены
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
