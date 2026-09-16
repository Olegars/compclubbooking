<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useToast } from '@/Composables/useToast'

const props = defineProps({
    wars: Array,
    board: Array,
    clubs: Array,
    host_club_id: [Number, String],
})

const { success, error } = useToast()
const showCreate = ref(false)
let poll = null

const form = useForm({
    name: '',
    mode: 'zone',
    game: 'any',
    duration_minutes: 60,
    side_a_club_id: '',
    side_b_club_id: '',
})

const hasLive = computed(() => (props.wars || []).some((w) => w.status === 'live'))

const statusClass = (status) => {
    if (status === 'live') return 'bg-green-500/15 text-green-400 border-green-500/40'
    if (status === 'finished') return 'bg-white/10 text-white/50 border-white/20'
    if (status === 'cancelled') return 'bg-red-500/15 text-red-400 border-red-500/40'
    return 'bg-blue-500/15 text-blue-300 border-blue-500/40'
}

const submit = () => {
    form.post('/admin/clan-wars', {
        onSuccess: () => {
            showCreate.value = false
            form.reset()
            form.mode = 'zone'
            form.game = 'any'
            form.duration_minutes = 60
            success('Война создана')
        },
        onError: (errors) => error(Object.values(errors)[0] || 'Не удалось создать'),
    })
}

const setStatus = (id, status) => {
    router.patch(`/admin/clan-wars/${id}/status`, { status }, {
        preserveScroll: true,
        onSuccess: () => success(status === 'live' ? 'Clan War в эфире' : 'Статус обновлён'),
        onError: (errors) => error(Object.values(errors)[0] || 'Ошибка статуса'),
    })
}

onMounted(() => {
    poll = setInterval(() => {
        if (!hasLive.value) return
        router.reload({ only: ['wars', 'board'], preserveScroll: true })
    }, 4000)
})
onUnmounted(() => {
    if (poll) clearInterval(poll)
})
</script>

<template>
    <AdminLayout>
        <div class="p-8 h-full flex flex-col font-mono text-white">
            <div class="flex justify-between items-center mb-8">
                <div>
                    <h1 class="text-4xl font-black italic tracking-tighter uppercase text-fuchsia-400">Clan Wars</h1>
                    <p class="text-[10px] text-white/30 uppercase tracking-widest mt-2">
                        Bootcamp vs Standard или локация vs локация · GSI CS2/Dota · счёт на TV-оверлеях
                    </p>
                </div>
                <button @click="showCreate = true" class="px-6 py-3 bg-fuchsia-600 hover:bg-fuchsia-500 text-white font-black rounded-xl tracking-widest text-xs uppercase">
                    Новая война
                </button>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
                <div class="xl:col-span-2 space-y-4">
                    <div v-if="!wars.length" class="border border-dashed border-white/10 rounded-3xl p-12 text-center text-white/30 uppercase text-xs tracking-widest">
                        Войн ещё не было
                    </div>
                    <div v-for="war in wars" :key="war.id" class="bg-[#0a0a0a] border border-white/5 rounded-3xl p-6">
                        <div class="flex justify-between items-start gap-4 mb-4">
                            <div>
                                <div class="text-[10px] text-fuchsia-400 font-black uppercase mb-1">
                                    {{ war.mode === 'location' ? 'Межлокационный' : 'Межзонный' }} · {{ war.game }}
                                </div>
                                <h3 class="text-xl font-black uppercase italic">{{ war.name }}</h3>
                            </div>
                            <span :class="statusClass(war.status)" class="text-[9px] px-3 py-1 rounded-full font-black border uppercase">{{ war.status }}</span>
                        </div>

                        <div class="grid grid-cols-3 items-center gap-4 mb-5">
                            <div class="text-center">
                                <div class="text-[10px] uppercase text-white/40 truncate">{{ war.side_a.label }}</div>
                                <div class="text-4xl font-black text-fuchsia-300">{{ war.side_a.score }}</div>
                                <div class="text-[9px] text-white/30 uppercase">побед {{ war.side_a.wins }} · раундов {{ war.side_a.rounds }}</div>
                            </div>
                            <div class="text-center text-white/20 font-black text-xl">VS</div>
                            <div class="text-center">
                                <div class="text-[10px] uppercase text-white/40 truncate">{{ war.side_b.label }}</div>
                                <div class="text-4xl font-black text-emerald-400">{{ war.side_b.score }}</div>
                                <div class="text-[9px] text-white/30 uppercase">побед {{ war.side_b.wins }} · раундов {{ war.side_b.rounds }}</div>
                            </div>
                        </div>

                        <div v-if="war.contributors?.length" class="mb-4 space-y-1">
                            <div v-for="c in war.contributors" :key="c.user_id + c.side" class="flex justify-between text-[11px] bg-white/5 rounded-lg px-3 py-2">
                                <span>{{ c.name }} · {{ c.side === 'a' ? war.side_a.label : war.side_b.label }}</span>
                                <span class="text-fuchsia-300">{{ c.points }}</span>
                            </div>
                        </div>

                        <div class="flex gap-2 flex-wrap">
                            <button v-if="war.status === 'planned'" @click="setStatus(war.id, 'live')" class="flex-1 py-2 bg-green-600 hover:bg-green-500 text-[10px] font-black uppercase rounded-lg">В эфир</button>
                            <button v-if="war.status === 'live'" @click="setStatus(war.id, 'finished')" class="flex-1 py-2 bg-white/10 hover:bg-white/20 text-[10px] font-black uppercase rounded-lg">Завершить + рейтинг</button>
                            <button v-if="war.status === 'planned' || war.status === 'live'" @click="setStatus(war.id, 'cancelled')" class="py-2 px-3 bg-red-500/20 text-red-300 text-[10px] font-black uppercase rounded-lg">Отмена</button>
                        </div>
                    </div>
                </div>

                <div class="bg-[#0a0a0a] border border-white/5 rounded-3xl p-6 h-fit">
                    <div class="text-[10px] uppercase text-white/40 tracking-widest font-black mb-4">Рейтинг кланов</div>
                    <div v-if="!board.length" class="text-white/25 text-xs">После первой войны</div>
                    <div v-for="(row, i) in board" :key="row.id" class="flex items-center justify-between py-2 border-b border-white/5 text-sm">
                        <div class="min-w-0">
                            <span class="text-white/30 mr-2">{{ i + 1 }}</span>
                            <span class="font-black uppercase italic truncate">{{ row.name }}</span>
                        </div>
                        <span class="text-fuchsia-300 font-mono">{{ row.rating }}</span>
                    </div>
                </div>
            </div>
        </div>

        <Teleport to="body">
            <div v-if="showCreate" class="fixed inset-0 flex items-center justify-center z-50 p-6">
                <div class="absolute inset-0 bg-black/90" @click="showCreate = false"></div>
                <div class="relative w-full max-w-xl bg-[#0a0a0a] border border-white/10 rounded-[1.125rem] p-10">
                    <h2 class="text-2xl font-black uppercase italic mb-8">Новая Clan War</h2>
                    <form @submit.prevent="submit" class="space-y-5">
                        <div>
                            <label class="text-[10px] uppercase text-white/40 mb-2 block">Название</label>
                            <input v-model="form.name" type="text" class="w-full bg-black border border-white/10 rounded-2xl p-4" placeholder="вечерний буткамп" />
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="text-[10px] uppercase text-white/40 mb-2 block">Режим</label>
                                <select v-model="form.mode" class="w-full bg-black border border-white/10 rounded-2xl p-4">
                                    <option value="zone">Зоны: Bootcamp vs Standard</option>
                                    <option value="location">Локации сети</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-[10px] uppercase text-white/40 mb-2 block">Игра</label>
                                <select v-model="form.game" class="w-full bg-black border border-white/10 rounded-2xl p-4">
                                    <option value="any">CS2 + Dota</option>
                                    <option value="cs2">Только CS2</option>
                                    <option value="dota">Только Dota</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="text-[10px] uppercase text-white/40 mb-2 block">Длительность, мин</label>
                            <input v-model.number="form.duration_minutes" type="number" min="10" max="240" class="w-full bg-black border border-white/10 rounded-2xl p-4" />
                        </div>
                        <div v-if="form.mode === 'location'" class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="text-[10px] uppercase text-white/40 mb-2 block">Локация A</label>
                                <select v-model="form.side_a_club_id" class="w-full bg-black border border-white/10 rounded-2xl p-4" required>
                                    <option disabled value="">Выберите</option>
                                    <option v-for="c in clubs" :key="c.id" :value="c.id">{{ c.name }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-[10px] uppercase text-white/40 mb-2 block">Локация B</label>
                                <select v-model="form.side_b_club_id" class="w-full bg-black border border-white/10 rounded-2xl p-4" required>
                                    <option disabled value="">Выберите</option>
                                    <option v-for="c in clubs" :key="'b'+c.id" :value="c.id">{{ c.name }}</option>
                                </select>
                            </div>
                        </div>
                        <p v-else class="text-[11px] text-white/35">Стороны: Bootcamp и все остальные зоны текущей локации (сингл/дуо/трио/кватро).</p>
                        <button type="submit" class="w-full py-5 bg-fuchsia-600 hover:bg-fuchsia-500 text-white font-black uppercase rounded-2xl tracking-widest">Создать</button>
                    </form>
                </div>
            </div>
        </Teleport>
    </AdminLayout>
</template>
