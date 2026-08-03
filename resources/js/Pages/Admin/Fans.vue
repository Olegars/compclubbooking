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
    defaults: { port: number, thermal_on_c: number, thermal_off_c: number }
}>()

const selectedClubId = ref(props.clubId || props.clubs[0]?.id || 0)
const selectedSpaceId = ref<number | null>(null)

watch(selectedClubId, (id) => {
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
    thermal_on_c: props.defaults.thermal_on_c,
    thermal_off_c: props.defaults.thermal_off_c,
})

const pickSpace = (spaceId: number) => {
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
            fanForm.reset('channel')
            fanForm.channel = 1
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
</script>

<template>
    <AdminLayout>
        <div class="p-8 max-w-6xl mx-auto font-mono text-white space-y-8">
            <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
                <div>
                    <h1 class="text-4xl font-black italic tracking-tighter uppercase text-cyan-500">Вентиляторы</h1>
                    <p class="text-[10px] text-white/30 uppercase tracking-widest font-black mt-2">
                        W5100 · IP + канал · привязка к комнате · shell на LAN
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
                    <p class="text-[10px] text-white/30 uppercase tracking-wider">Выберите комнату ниже, затем канал 1–16</p>
                    <form @submit.prevent="submitFan" class="space-y-3">
                        <select v-model.number="fanForm.relay_board_id"
                                class="w-full bg-black border border-white/10 rounded-xl p-4 text-sm outline-none focus:border-cyan-500" required>
                            <option :value="null" disabled>Плата</option>
                            <option v-for="b in boards" :key="b.id" :value="b.id">{{ b.name }} ({{ b.host }})</option>
                        </select>
                        <div class="grid grid-cols-3 gap-3">
                            <input v-model.number="fanForm.channel" type="number" min="1" max="16" placeholder="Канал"
                                   class="bg-black border border-white/10 rounded-xl p-4 text-sm outline-none focus:border-cyan-500" required />
                            <input v-model.number="fanForm.thermal_on_c" type="number" placeholder="ON °C"
                                   class="bg-black border border-white/10 rounded-xl p-4 text-sm outline-none focus:border-cyan-500" />
                            <input v-model.number="fanForm.thermal_off_c" type="number" placeholder="OFF °C"
                                   class="bg-black border border-white/10 rounded-xl p-4 text-sm outline-none focus:border-cyan-500" />
                        </div>
                        <div class="text-[10px] text-white/40 uppercase tracking-wider">
                            Комната:
                            <span class="text-cyan-400 font-black">
                                {{ selectedSpaceId ? ('#' + selectedSpaceId) : 'не выбрана' }}
                            </span>
                        </div>
                        <button type="submit" :disabled="!fanForm.space_id && !selectedSpaceId"
                                class="w-full py-4 bg-[#22c55e] text-black font-black uppercase text-[10px] rounded-xl tracking-widest disabled:opacity-30">
                            Завести вентилятор
                        </button>
                    </form>

                    <div class="pt-2 space-y-2 max-h-64 overflow-y-auto">
                        <button v-for="s in freeSpaces" :key="s.id" type="button" @click="pickSpace(s.id)"
                                class="w-full text-left p-4 rounded-2xl border transition-all"
                                :class="selectedSpaceId === s.id ? 'border-cyan-500 bg-cyan-500/10' : 'border-white/5 bg-black/30 hover:border-white/20'">
                            <div class="flex items-center gap-3">
                                <span class="w-3 h-3 rounded-full" :style="{ background: s.zone_color || '#22c55e' }"></span>
                                <div>
                                    <div class="text-xs font-black uppercase">{{ s.name }}</div>
                                    <div class="text-[9px] text-white/30">{{ s.zone_name || 'zone' }} · space #{{ s.id }}</div>
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
                                {{ f.relay_board?.host }}:{{ f.relay_board?.port }} · ch {{ f.channel }} · mode {{ f.manual_mode }} · applied {{ f.applied_power }}
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
