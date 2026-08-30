<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const props = defineProps<{
    clubs: Array<{ id: number, name: string }>
    clubId: number
    nodes: any[]
    lights: any[]
    spaces: any[]
    computers: Array<{ id: number, name: string, space_id: number | null, space_name?: string | null }>
    defaults: { port: number, brightness: number }
}>()

const selectedClubId = ref(props.clubId || props.clubs[0]?.id || 0)

watch(selectedClubId, (id) => {
    router.get('/admin/lights', { club_id: id }, { preserveState: true, replace: true })
})

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

watch(() => props.clubId, (id) => {
    nodeForm.club_id = id
    lightForm.club_id = id
})

const freeSpaces = computed(() => props.spaces.filter((s: any) => !s.has_light))

const submitNode = () => {
    nodeForm.club_id = selectedClubId.value
    nodeForm.post('/admin/lights/nodes', {
        onSuccess: () => nodeForm.reset('name', 'host'),
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
    }
    return map[color] || '#64748b'
}

const layoutLabel = (l: string) => {
    if (l === 'dimmer_rgb') return 'Dimmer + RGB'
    if (l === 'rgbw') return 'RGBW'
    return 'RGB'
}
</script>

<template>
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
    </AdminLayout>
</template>
