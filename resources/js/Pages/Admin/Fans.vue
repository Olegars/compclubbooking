<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const props = defineProps<{
    clubs: Array<{ id: number, name: string }>
    clubId: number
    boards: any[]
    fans: any[]
    spaces: any[]
    mapPreview?: { viewbox?: string | null, walls?: any[], labels?: any[] }
    defaults: { port: number, thermal_on_c: number, thermal_off_c: number }
}>()

const selectedClubId = ref(props.clubId || props.clubs[0]?.id || 0)
const selectedSpaceId = ref<number | null>(null)

watch(selectedClubId, (id) => {
    selectedSpaceId.value = null
    fanForm.space_id = null
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
})

const fanForm = useForm({
    club_id: props.clubId,
    space_id: null as number | null,
    relay_board_id: null as number | null,
    channel: 1,
    channel2: 2,
    thermal_on_c: props.defaults.thermal_on_c,
    thermal_off_c: props.defaults.thermal_off_c,
})

const pickSpace = (spaceId: number, allowTaken = false) => {
    const space = props.spaces.find((s: any) => Number(s.id) === Number(spaceId))
    if (!space) return
    if (space.has_fan && !allowTaken) return
    selectedSpaceId.value = spaceId
    fanForm.space_id = spaceId
}

const submitBoard = () => {
    boardForm.club_id = selectedClubId.value
    boardForm.post('/admin/fans/boards', {
        onSuccess: () => boardForm.reset('name', 'host'),
    })
}

const submitFan = () => {
    fanForm.club_id = selectedClubId.value
    if (!fanForm.space_id) {
        fanForm.space_id = selectedSpaceId.value
    }
    fanForm.post('/admin/fans', {
        onSuccess: () => {
            fanForm.reset('channel', 'channel2')
            fanForm.channel = 1
            fanForm.channel2 = 2
            selectedSpaceId.value = null
            fanForm.space_id = null
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

const freeSpaces = computed(() => props.spaces.filter((s: any) => !s.has_fan))

const selectedSpace = computed(() =>
    props.spaces.find((s: any) => Number(s.id) === Number(selectedSpaceId.value)) || null
)

const mapViewBox = computed(() => {
    const fromConfig = props.mapPreview?.viewbox
    if (fromConfig && String(fromConfig).trim()) return String(fromConfig).trim()

    const list = props.spaces.filter((s: any) => s.w > 0 && s.h > 0)
    if (!list.length) return '0 0 100 100'

    let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity
    for (const s of list) {
        minX = Math.min(minX, Number(s.x))
        minY = Math.min(minY, Number(s.y))
        maxX = Math.max(maxX, Number(s.x) + Number(s.w))
        maxY = Math.max(maxY, Number(s.y) + Number(s.h))
    }
    const pad = 4
    return `${minX - pad} ${minY - pad} ${maxX - minX + pad * 2} ${maxY - minY + pad * 2}`
})

const mapWalls = computed(() => (props.mapPreview?.walls || []).filter((w: any) => w?.d))

const labelFontSize = (s: any) => {
    const w = Math.max(1, Number(s.w) || 1)
    const h = Math.max(1, Number(s.h) || 1)
    return Math.max(1.2, Math.min(3.2, Math.min(w, h) * 0.28))
}

const spaceFill = (s: any) => {
    if (selectedSpaceId.value === s.id) return 'rgba(6,182,212,0.45)'
    if (s.has_fan) return 'rgba(34,197,94,0.18)'
    return (s.zone_color || '#22c55e') + '33'
}

const spaceStroke = (s: any) => {
    if (selectedSpaceId.value === s.id) return '#22d3ee'
    if (s.has_fan) return '#22c55e'
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

            <!-- Карта клуба: клик по комнате выбирает space -->
            <div class="bg-[#0a0a0a] border border-white/5 rounded-[1rem] p-6 md:p-8 space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div>
                        <h3 class="text-lg font-black uppercase italic">Карта клуба</h3>
                        <p class="text-[10px] text-white/30 uppercase tracking-wider mt-1">
                            Клик по комнате без вентилятора → выбор space #
                            <span v-if="selectedSpace" class="text-cyan-400 font-black">
                                {{ selectedSpace.id }}
                            </span>
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-4 text-[9px] font-black uppercase tracking-widest text-white/40">
                        <span class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-sm bg-cyan-400/80"></span> Выбрана</span>
                        <span class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-sm bg-[#22c55e]/50"></span> Уже с вентилятором</span>
                        <span class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-sm bg-white/20"></span> Свободна</span>
                    </div>
                </div>

                <div class="relative w-full overflow-hidden rounded-2xl border border-white/5 bg-black/60"
                     style="aspect-ratio: 16 / 10; min-height: 280px;">
                    <svg
                        v-if="spaces.length"
                        class="absolute inset-0 w-full h-full"
                        :viewBox="mapViewBox"
                        preserveAspectRatio="xMidYMid meet"
                    >
                        <g class="walls" fill="none" stroke="rgba(255,255,255,0.18)" stroke-width="0.35">
                            <path v-for="(w, i) in mapWalls" :key="'wall'+i" :d="w.d" />
                        </g>

                        <g class="spaces">
                            <g v-for="s in spaces" :key="s.id"
                               :class="s.has_fan ? 'cursor-default' : 'cursor-pointer'"
                               @click="pickSpace(s.id)">
                                <rect
                                    :x="s.x" :y="s.y" :width="s.w" :height="s.h"
                                    :fill="spaceFill(s)"
                                    :stroke="spaceStroke(s)"
                                    stroke-width="0.45"
                                    rx="0.4"
                                    class="transition-opacity"
                                    :opacity="s.has_fan && selectedSpaceId !== s.id ? 0.85 : 1"
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
                                >{{ s.has_fan ? 'fan' : (s.zone_name || s.name) }}</text>
                            </g>
                        </g>
                    </svg>
                    <div v-else class="absolute inset-0 flex items-center justify-center text-[10px] uppercase tracking-widest text-white/20 italic">
                        Нет rooms / spaces для этого клуба — сначала карта в редакторе
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <div class="bg-[#0a0a0a] border border-white/5 rounded-[1rem] p-8 space-y-4">
                    <h3 class="text-lg font-black uppercase italic">Новая плата W5100</h3>
                    <form @submit.prevent="submitBoard" class="space-y-3">
                        <input v-model="boardForm.name" type="text" placeholder="Имя (Hall A relays)"
                               class="w-full bg-black border border-white/10 rounded-xl p-4 text-sm outline-none focus:border-cyan-500" required />
                        <input v-model="boardForm.host" type="text" placeholder="IP (192.168.1.4)"
                               class="w-full bg-black border border-white/10 rounded-xl p-4 text-sm outline-none focus:border-cyan-500" required />
                        <input v-model.number="boardForm.port" type="number" min="1" max="65535" placeholder="Port"
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
                                <div class="text-[10px] text-white/40 font-mono mt-1">{{ b.host }}:{{ b.port }} · {{ b.driver }}</div>
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
                        Выберите комнату на карте · каналы K1/K2
                    </p>
                    <form @submit.prevent="submitFan" class="space-y-3">
                        <select v-model.number="fanForm.relay_board_id"
                                class="w-full bg-black border border-white/10 rounded-xl p-4 text-sm outline-none focus:border-cyan-500" required>
                            <option :value="null" disabled>Плата</option>
                            <option v-for="b in boards" :key="b.id" :value="b.id">{{ b.name }} ({{ b.host }})</option>
                        </select>
                        <div class="grid grid-cols-2 gap-3">
                            <input v-model.number="fanForm.channel" type="number" min="1" max="16" placeholder="K1 канал"
                                   class="bg-black border border-white/10 rounded-xl p-4 text-sm outline-none focus:border-cyan-500" required />
                            <input v-model.number="fanForm.channel2" type="number" min="1" max="16" placeholder="K2 канал"
                                   class="bg-black border border-white/10 rounded-xl p-4 text-sm outline-none focus:border-cyan-500" required />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <input v-model.number="fanForm.thermal_on_c" type="number" placeholder="ON °C"
                                   class="bg-black border border-white/10 rounded-xl p-4 text-sm outline-none focus:border-cyan-500" />
                            <input v-model.number="fanForm.thermal_off_c" type="number" placeholder="OFF °C"
                                   class="bg-black border border-white/10 rounded-xl p-4 text-sm outline-none focus:border-cyan-500" />
                        </div>
                        <div class="rounded-2xl border border-cyan-500/20 bg-cyan-500/5 p-4 text-[10px] uppercase tracking-wider">
                            Комната:
                            <span class="text-cyan-400 font-black ml-2">
                                <template v-if="selectedSpace">
                                    space #{{ selectedSpace.id }}
                                    <span class="text-white/40 font-bold normal-case tracking-normal ml-2">
                                        {{ selectedSpace.name }} · {{ selectedSpace.zone_name || 'zone' }}
                                    </span>
                                </template>
                                <template v-else>кликните на карте</template>
                            </span>
                        </div>
                        <button type="submit" :disabled="!fanForm.space_id && !selectedSpaceId"
                                class="w-full py-4 bg-[#22c55e] text-black font-black uppercase text-[10px] rounded-xl tracking-widest disabled:opacity-30">
                            Завести вентилятор
                        </button>
                    </form>

                    <div class="pt-2 space-y-2 max-h-48 overflow-y-auto">
                        <button v-for="s in freeSpaces" :key="s.id" type="button" @click="pickSpace(s.id)"
                                class="w-full text-left p-3 rounded-2xl border transition-all"
                                :class="selectedSpaceId === s.id ? 'border-cyan-500 bg-cyan-500/10' : 'border-white/5 bg-black/30 hover:border-white/20'">
                            <div class="flex items-center gap-3">
                                <span class="w-3 h-3 rounded-full" :style="{ background: s.zone_color || '#22c55e' }"></span>
                                <div>
                                    <div class="text-xs font-black uppercase">space #{{ s.id }}</div>
                                    <div class="text-[9px] text-white/30">{{ s.name }} · {{ s.zone_name || 'zone' }}</div>
                                </div>
                            </div>
                        </button>
                        <div v-if="!freeSpaces.length" class="text-[10px] text-white/20 uppercase tracking-widest italic py-4 text-center">
                            Все комнаты уже с вентилятором или spaces пусты
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-[#0a0a0a] border border-white/5 rounded-[1rem] p-8">
                <h3 class="text-lg font-black uppercase italic mb-6">Заведённые вентиляторы</h3>
                <div class="space-y-3">
                    <div v-for="f in fans" :key="f.id"
                         class="flex flex-col md:flex-row md:items-center justify-between gap-4 p-5 rounded-2xl border border-white/5 bg-black/40">
                        <div>
                            <div class="text-sm font-black uppercase italic">
                                Space #{{ f.space_id }} · {{ f.space?.name || 'room' }}
                            </div>
                            <div class="text-[10px] text-white/40 font-mono mt-1">
                                {{ f.relay_board?.host }}:{{ f.relay_board?.port }}
                                · K1={{ f.channel }} K2={{ f.channel2 }}
                                · speed {{ f.applied_power }}/3 · mode {{ f.manual_mode }}
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
        </div>
    </AdminLayout>
</template>
