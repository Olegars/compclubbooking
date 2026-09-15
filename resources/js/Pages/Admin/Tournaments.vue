<script setup>
import { ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import axios from 'axios'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useToast } from '@/Composables/useToast'

const props = defineProps({
    tournaments: Array,
    games: Array,
    computers: Array,
})

const { success, error } = useToast()
const showCreateModal = ref(false)
const openId = ref(null)
const playerPhone = ref('')
const matchScores = ref({})

const form = useForm({
    name: '',
    game_id: '',
    start_at: '',
    end_at: '',
    entry_fee: 0,
    prize_pool: '',
    prize_first: 0,
    prize_second: 0,
    prize_third: 0,
    lock_games: true,
    selected_pcs: [],
})

const getStatusClass = (status) => {
    if (status === 'active') return 'bg-green-500/15 text-green-400 border-green-500/40'
    if (status === 'finished') return 'bg-white/10 text-white/50 border-white/20'
    if (status === 'cancelled') return 'bg-red-500/15 text-red-400 border-red-500/40'
    return 'bg-blue-500/15 text-blue-300 border-blue-500/40'
}

const togglePC = (id) => {
    const i = form.selected_pcs.indexOf(id)
    if (i === -1) form.selected_pcs.push(id)
    else form.selected_pcs.splice(i, 1)
}

const submit = () => {
    form.post('/admin/tournaments', {
        onSuccess: () => {
            showCreateModal.value = false
            form.reset()
            form.lock_games = true
            success('Ивент создан')
        },
        onError: () => error('Не удалось создать ивент'),
    })
}

const updateStatus = (id, status) => {
    router.patch(`/admin/tournaments/${id}/status`, { status }, {
        onSuccess: () => success(status === 'active' ? 'Турнир стартовал, сетка собрана' : 'Статус обновлён'),
        onError: (errors) => error(Object.values(errors)[0] || 'Ошибка статуса'),
    })
}

const addPlayer = async (eventId) => {
    if (!playerPhone.value.trim()) return
    try {
        const { data } = await axios.get(`/admin/search-user?phone=${encodeURIComponent(playerPhone.value)}`)
        router.post(`/admin/tournaments/${eventId}/players`, { user_id: data.id }, {
            onSuccess: () => {
                playerPhone.value = ''
                success('Игрок в сетке')
            },
            onError: (errors) => error(errors.phone || errors.user_id || 'Не удалось добавить'),
        })
    } catch (e) {
        error(e?.response?.data?.message || 'Гость не найден')
    }
}

const removePlayer = (eventId, playerId) => {
    router.delete(`/admin/tournaments/${eventId}/players/${playerId}`)
}

const ensureScore = (id) => {
    if (!matchScores.value[id]) {
        matchScores.value[id] = { score1: 0, score2: 0 }
    }
    return matchScores.value[id]
}

const reportMatch = (eventId, matchId) => {
    const s = ensureScore(matchId)
    router.patch(`/admin/tournaments/${eventId}/matches/${matchId}`, {
        score1: Number(s.score1) || 0,
        score2: Number(s.score2) || 0,
    }, {
        onSuccess: () => success('Счёт записан'),
        onError: (errors) => error(Object.values(errors)[0] || 'Не удалось записать счёт'),
    })
}

const payout = (id) => {
    if (!confirm('Завершить турнир и зачислить призы на депозит?')) return
    router.post(`/admin/tournaments/${id}/payout`, {}, {
        onSuccess: () => success('Призы на депозите победителей'),
        onError: () => error('Не удалось выплатить'),
    })
}

const roundLabel = (round, maxRound) => {
    if (round === maxRound) return 'Финал'
    if (round === maxRound - 1) return 'Полуфинал'
    return `Раунд ${round}`
}
</script>

<template>
    <AdminLayout>
        <div class="p-8 h-full flex flex-col font-mono text-white">
            <div class="flex justify-between items-center mb-8">
                <div>
                    <h1 class="text-4xl font-black italic tracking-tighter uppercase text-blue-500">Event Manager</h1>
                    <p class="text-[10px] text-white/30 uppercase tracking-widest mt-2">Single elimination · счёт вручную · приз на депозит. Steam GC / клиппинг не подключены.</p>
                </div>
                <button @click="showCreateModal = true" class="px-6 py-3 bg-blue-600 hover:bg-blue-500 text-white font-black rounded-xl tracking-widest text-xs uppercase transition-all shadow-[0_0_20px_rgba(37,99,235,0.3)]">
                    Создать Ивент
                </button>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div v-for="event in tournaments" :key="event.id"
                     class="bg-[#0a0a0a] border border-white/5 rounded-3xl p-6 relative overflow-hidden">
                    <div class="flex justify-between items-start mb-4 cursor-pointer" @click="openId = openId === event.id ? null : event.id">
                        <div>
                            <div class="text-[10px] text-blue-500 font-black uppercase mb-1">{{ event.game?.title }}</div>
                            <h3 class="text-xl font-black uppercase italic">{{ event.name }}</h3>
                        </div>
                        <span :class="getStatusClass(event.status)" class="text-[9px] px-3 py-1 rounded-full font-black border uppercase">
                            {{ event.status }}
                        </span>
                    </div>

                    <div class="space-y-2 mb-6 text-xs">
                        <div class="flex justify-between">
                            <span class="text-white/40 uppercase">Взнос:</span>
                            <span class="font-bold">{{ event.entry_fee }} ₽</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-white/40 uppercase">Призы 1/2/3:</span>
                            <span class="text-blue-400 font-bold">{{ event.prize_first }}/{{ event.prize_second }}/{{ event.prize_third }} ₽</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-white/40 uppercase">ПК / игроки:</span>
                            <span class="font-bold">{{ event.computers_count }} / {{ event.players?.length || 0 }}</span>
                        </div>
                    </div>

                    <div class="flex gap-2 flex-wrap">
                        <button v-if="event.status === 'planned'" @click="updateStatus(event.id, 'active')" class="flex-1 py-2 bg-green-600 hover:bg-green-500 text-[10px] font-black uppercase rounded-lg">Старт</button>
                        <button v-if="event.status === 'active'" @click="payout(event.id)" class="flex-1 py-2 bg-white/10 hover:bg-white/20 text-[10px] font-black uppercase rounded-lg">Завершить + призы</button>
                        <button v-if="event.status === 'planned'" @click="updateStatus(event.id, 'cancelled')" class="py-2 px-3 bg-red-500/20 text-red-300 text-[10px] font-black uppercase rounded-lg">Отмена</button>
                    </div>

                    <div v-if="openId === event.id" class="mt-6 space-y-4 border-t border-white/10 pt-4">
                        <div v-if="event.status === 'planned'" class="flex gap-2">
                            <input v-model="playerPhone" type="text" placeholder="Телефон гостя" class="flex-1 bg-black border border-white/10 rounded-xl p-3 text-sm" />
                            <button @click="addPlayer(event.id)" class="px-4 bg-blue-600 rounded-xl text-[10px] font-black uppercase">В сетку</button>
                        </div>
                        <div class="space-y-1">
                            <div v-for="p in event.players" :key="p.id" class="flex justify-between text-[11px] bg-white/5 rounded-lg px-3 py-2">
                                <span>{{ p.name }} · {{ p.phone }} <span v-if="p.placement" class="text-blue-400">#{{ p.placement }}</span></span>
                                <button v-if="event.status === 'planned'" @click="removePlayer(event.id, p.id)" class="text-red-400 text-[9px] uppercase">убрать</button>
                            </div>
                        </div>

                        <div v-for="m in event.matches" :key="m.id" class="bg-black/40 border border-white/5 rounded-xl p-3">
                            <div class="text-[9px] uppercase text-white/30 mb-1">{{ roundLabel(m.round, event.matches.at(-1)?.round) }} · {{ m.status }}</div>
                            <div class="flex items-center justify-between gap-2 text-xs">
                                <span class="flex-1 truncate">{{ m.player1 || 'BYE' }}</span>
                                <template v-if="m.status !== 'done' && m.player1 && m.player2">
                                    <input v-model.number="ensureScore(m.id).score1" class="w-10 bg-black border border-white/20 rounded text-center" />
                                    <span>:</span>
                                    <input v-model.number="ensureScore(m.id).score2" class="w-10 bg-black border border-white/20 rounded text-center" />
                                    <button @click="reportMatch(event.id, m.id)" class="text-[9px] uppercase text-blue-400">ок</button>
                                </template>
                                <span v-else class="text-white/50">{{ m.score1 ?? '—' }}:{{ m.score2 ?? '—' }}</span>
                                <span class="flex-1 truncate text-right">{{ m.player2 || 'BYE' }}</span>
                            </div>
                            <div v-if="m.winner" class="text-[9px] text-green-400 uppercase mt-1">Победитель: {{ m.winner }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <Teleport to="body">
            <div v-if="showCreateModal" class="fixed inset-0 flex items-center justify-center z-50 p-6">
                <div class="absolute inset-0 bg-black/90 backdrop-blur-xl" @click="showCreateModal = false"></div>
                <div class="relative w-full max-w-2xl bg-[#0a0a0a] border border-white/10 rounded-[1.125rem] p-10 max-h-[90vh] overflow-y-auto">
                    <h2 class="text-2xl font-black uppercase italic mb-8">Настройка Ивента</h2>
                    <form @submit.prevent="submit" class="grid grid-cols-2 gap-6">
                        <div class="col-span-2">
                            <label class="text-[10px] uppercase text-white/40 mb-2 block">Название турнира</label>
                            <input v-model="form.name" type="text" class="w-full bg-black border border-white/10 rounded-2xl p-4 outline-none focus:border-blue-500" required />
                        </div>
                        <div>
                            <label class="text-[10px] uppercase text-white/40 mb-2 block">Игра</label>
                            <select v-model="form.game_id" class="w-full bg-black border border-white/10 rounded-2xl p-4 outline-none focus:border-blue-500" required>
                                <option disabled value="">Выберите</option>
                                <option v-for="game in games" :key="game.id" :value="game.id">{{ game.title }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-[10px] uppercase text-white/40 mb-2 block">Взнос (₽, справочно)</label>
                            <input v-model="form.entry_fee" type="number" min="0" class="w-full bg-black border border-white/10 rounded-2xl p-4 outline-none focus:border-blue-500" />
                        </div>
                        <div>
                            <label class="text-[10px] uppercase text-white/40 mb-2 block">1 место ₽</label>
                            <input v-model="form.prize_first" type="number" min="0" class="w-full bg-black border border-white/10 rounded-2xl p-4" />
                        </div>
                        <div>
                            <label class="text-[10px] uppercase text-white/40 mb-2 block">2 / 3 место ₽</label>
                            <div class="grid grid-cols-2 gap-2">
                                <input v-model="form.prize_second" type="number" min="0" class="bg-black border border-white/10 rounded-2xl p-4" />
                                <input v-model="form.prize_third" type="number" min="0" class="bg-black border border-white/10 rounded-2xl p-4" />
                            </div>
                        </div>
                        <label class="col-span-2 flex items-center gap-3 text-[11px] uppercase tracking-widest text-white/60">
                            <input v-model="form.lock_games" type="checkbox" class="accent-blue-500" />
                            На занятых ПК в шелле только эта игра
                        </label>
                        <div class="col-span-2">
                            <label class="text-[10px] uppercase text-white/40 mb-2 block">Компьютеры арены</label>
                            <div class="grid grid-cols-8 gap-2 max-h-48 overflow-y-auto">
                                <div v-for="pc in computers" :key="pc.id"
                                     @click="togglePC(pc.id)"
                                     :class="form.selected_pcs.includes(pc.id) ? 'bg-blue-600 border-blue-400' : 'bg-white/5 border-white/10'"
                                     class="aspect-square flex items-center justify-center border rounded-lg cursor-pointer text-[9px] font-black">
                                    {{ pc.name }}
                                </div>
                            </div>
                        </div>
                        <button type="submit" class="col-span-2 py-5 bg-blue-600 hover:bg-blue-500 text-white font-black uppercase rounded-2xl tracking-widest mt-4">Опубликовать</button>
                    </form>
                </div>
            </div>
        </Teleport>
    </AdminLayout>
</template>
