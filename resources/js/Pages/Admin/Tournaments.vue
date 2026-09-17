<script setup>
import { computed, ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import axios from 'axios'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useToast } from '@/Composables/useToast'

const props = defineProps({
    tournaments: Array,
    challenges: { type: Array, default: () => [] },
    games: Array,
    computers: Array,
    clubs: { type: Array, default: () => [] },
    host_club_id: [Number, String],
    join_url: { type: String, default: '/clubs/join' },
})

const { success, error } = useToast()
const showCreateModal = ref(false)
const showChallengeModal = ref(false)
const counterId = ref(null)
const openId = ref(null)
const openChallenge = ref(null)
const playerPhone = ref('')
const matchScores = ref({})
const declineReason = ref('')

const emptyChallenge = () => ({
    guest_club_id: '',
    name: '',
    game_id: '',
    format: 'single_elim',
    start_at: '',
    end_at: '',
    roster_size: 5,
    entry_fee: 0,
    prize_pool: '',
    prize_first: 0,
    prize_second: 0,
    prize_third: 0,
    prize_funding: 'host',
    venue: 'host',
    lock_games: true,
    rules: '',
    comment: '',
})

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

const challengeForm = useForm(emptyChallenge())

const pendingChallenges = computed(() => (props.challenges || []).filter((c) => c.status === 'pending'))
const closedChallenges = computed(() => (props.challenges || []).filter((c) => c.status !== 'pending'))

const getStatusClass = (status) => {
    if (status === 'active' || status === 'agreed') return 'bg-green-500/15 text-green-400 border-green-500/40'
    if (status === 'finished') return 'bg-white/10 text-white/50 border-white/20'
    if (status === 'cancelled' || status === 'declined') return 'bg-red-500/15 text-red-400 border-red-500/40'
    if (status === 'pending') return 'bg-amber-500/15 text-amber-300 border-amber-500/40'
    return 'bg-blue-500/15 text-blue-300 border-blue-500/40'
}

const formatLabel = (format) => {
    if (format === 'double_elim') return 'Double Elim'
    if (format === 'round_robin') return 'Round Robin'
    return 'Single Elim'
}

const venueLabel = (venue) => {
    if (venue === 'guest') return 'Площадка гостя'
    if (venue === 'split') return 'Обе площадки'
    return 'Площадка хозяина'
}

const fundingLabel = (v) => {
    if (v === 'guest') return 'Призы платит гость'
    if (v === 'split') return 'Призы пополам'
    if (v === 'each') return 'Каждый своих'
    return 'Призы платит хозяин'
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
            success('Локальный ивент создан')
        },
        onError: (errors) => error(Object.values(errors)[0] || 'Не удалось создать ивент'),
    })
}

const fillChallengeFrom = (c) => {
    challengeForm.guest_club_id = c.guest?.id || ''
    challengeForm.name = c.name
    challengeForm.game_id = c.game?.id || ''
    challengeForm.format = c.format || 'single_elim'
    challengeForm.start_at = c.start_at || ''
    challengeForm.end_at = c.end_at || ''
    challengeForm.roster_size = c.roster_size || 5
    challengeForm.entry_fee = c.entry_fee || 0
    challengeForm.prize_pool = c.prize_pool || ''
    challengeForm.prize_first = c.prize_first || 0
    challengeForm.prize_second = c.prize_second || 0
    challengeForm.prize_third = c.prize_third || 0
    challengeForm.prize_funding = c.prize_funding || 'host'
    challengeForm.venue = c.venue || 'host'
    challengeForm.lock_games = c.lock_games !== false
    challengeForm.rules = c.rules || ''
    challengeForm.comment = ''
}

const openPropose = () => {
    counterId.value = null
    challengeForm.reset()
    Object.assign(challengeForm, emptyChallenge())
    showChallengeModal.value = true
}

const openCounter = (c) => {
    counterId.value = c.id
    fillChallengeFrom(c)
    showChallengeModal.value = true
}

const submitChallenge = () => {
    const wasCounter = Boolean(counterId.value)
    const url = wasCounter
        ? `/admin/tournaments/challenges/${counterId.value}/counter`
        : '/admin/tournaments/challenges'
    challengeForm.post(url, {
        onSuccess: () => {
            showChallengeModal.value = false
            counterId.value = null
            challengeForm.reset()
            Object.assign(challengeForm, emptyChallenge())
            success(wasCounter ? 'Встречные условия отправлены' : 'Условия отправлены сопернику')
        },
        onError: (errors) => error(Object.values(errors)[0] || 'Не удалось отправить'),
    })
}

const acceptChallenge = (id) => {
    router.post(`/admin/tournaments/challenges/${id}/accept`, {}, {
        preserveScroll: true,
        onSuccess: () => success('Регламент зафиксирован, ивент в сетке'),
        onError: (errors) => error(Object.values(errors)[0] || 'Не удалось принять'),
    })
}

const declineChallenge = (id) => {
    router.post(`/admin/tournaments/challenges/${id}/decline`, { reason: declineReason.value }, {
        preserveScroll: true,
        onSuccess: () => {
            declineReason.value = ''
            success('Вызов отклонён')
        },
        onError: (errors) => error(Object.values(errors)[0] || 'Не удалось отклонить'),
    })
}

const cancelChallenge = (id) => {
    router.post(`/admin/tournaments/challenges/${id}/cancel`, {}, {
        preserveScroll: true,
        onSuccess: () => success('Согласование снято'),
        onError: (errors) => error(Object.values(errors)[0] || 'Не удалось отменить'),
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

const actionLabel = (action) => {
    if (action === 'propose') return 'Предложили'
    if (action === 'counter') return 'Встречные условия'
    if (action === 'accept') return 'Приняли'
    if (action === 'decline') return 'Отклонили'
    if (action === 'cancel') return 'Сняли'
    return action
}
</script>

<template>
    <AdminLayout>
        <div class="p-8 h-full flex flex-col font-mono text-white">
            <div class="flex justify-between items-center mb-8 gap-4 flex-wrap">
                <div>
                    <h1 class="text-4xl font-black italic tracking-tighter uppercase text-blue-500">Event Manager</h1>
                    <p class="text-[10px] text-white/30 uppercase tracking-widest mt-2">
                        Любой клуб регистрируется сам и согласовывает регламент в админке. Clan Wars — отдельный live-счёт, не это.
                    </p>
                </div>
                <div class="flex gap-2">
                    <button @click="openPropose"
                            class="px-6 py-3 bg-cyan-600 hover:bg-cyan-500 text-white font-black rounded-xl tracking-widest text-xs uppercase">
                        Вызвать клуб
                    </button>
                    <button @click="showCreateModal = true"
                            class="px-6 py-3 bg-blue-600 hover:bg-blue-500 text-white font-black rounded-xl tracking-widest text-xs uppercase">
                        Локальный ивент
                    </button>
                </div>
            </div>

            <div class="mb-6 text-[11px] text-white/45 uppercase tracking-widest space-y-2">
                <p v-if="!clubs.length" class="text-amber-300/80">
                    Соперников пока нет. Скиньте ссылку — клуб из любой сети или независимый регистрируется сам.
                </p>
                <a :href="join_url" class="text-cyan-400 hover:text-cyan-300">{{ join_url }}</a>
            </div>

            <section v-if="pendingChallenges.length" class="mb-8">
                <h2 class="text-[11px] uppercase tracking-[0.3em] text-cyan-400 font-black mb-4">На согласовании</h2>
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div v-for="c in pendingChallenges" :key="c.id"
                         class="bg-[#0a0a0a] border rounded-3xl p-6"
                         :class="c.incoming ? 'border-cyan-500/40' : 'border-white/5'">
                        <div class="flex justify-between items-start gap-3 mb-3 cursor-pointer" @click="openChallenge = openChallenge === c.id ? null : c.id">
                            <div>
                                <div class="text-[10px] text-cyan-400 font-black uppercase mb-1">
                                    {{ c.host?.name }} → {{ c.guest?.name }} · {{ c.game?.title }}
                                </div>
                                <h3 class="text-xl font-black uppercase italic">{{ c.name }}</h3>
                            </div>
                            <span :class="getStatusClass(c.status)" class="text-[9px] px-3 py-1 rounded-full font-black border uppercase shrink-0">
                                {{ c.incoming ? 'ваш ход' : 'ждём ответ' }}
                            </span>
                        </div>
                        <div class="space-y-1 text-xs text-white/70 mb-4">
                            <div class="flex justify-between"><span class="text-white/40 uppercase">Когда</span><span>{{ c.start_label }} — {{ c.end_label }}</span></div>
                            <div class="flex justify-between"><span class="text-white/40 uppercase">Формат / состав</span><span>{{ formatLabel(c.format) }} · {{ c.roster_size }} с клуба</span></div>
                            <div class="flex justify-between"><span class="text-white/40 uppercase">Площадка</span><span>{{ venueLabel(c.venue) }}</span></div>
                            <div class="flex justify-between"><span class="text-white/40 uppercase">Призы 1/2/3</span><span class="text-blue-400">{{ c.prize_first }}/{{ c.prize_second }}/{{ c.prize_third }} ₽</span></div>
                            <div class="flex justify-between"><span class="text-white/40 uppercase">Кто платит</span><span>{{ fundingLabel(c.prize_funding) }}</span></div>
                        </div>
                        <p v-if="c.rules" class="text-[11px] text-white/50 mb-4 whitespace-pre-wrap">{{ c.rules }}</p>

                        <div v-if="c.incoming" class="flex flex-wrap gap-2">
                            <button @click="acceptChallenge(c.id)" class="flex-1 py-2 bg-green-600 hover:bg-green-500 text-[10px] font-black uppercase rounded-lg">Принять</button>
                            <button @click="openCounter(c)" class="flex-1 py-2 bg-cyan-700 hover:bg-cyan-600 text-[10px] font-black uppercase rounded-lg">Встречные</button>
                            <button @click="declineChallenge(c.id)" class="py-2 px-3 bg-red-500/20 text-red-300 text-[10px] font-black uppercase rounded-lg">Отклонить</button>
                        </div>
                        <div v-else class="flex gap-2">
                            <div class="flex-1 text-[10px] uppercase tracking-widest text-white/40 py-2">Ход: {{ c.waiting_club }}</div>
                            <button @click="cancelChallenge(c.id)" class="py-2 px-3 bg-white/10 text-[10px] font-black uppercase rounded-lg">Снять</button>
                        </div>
                        <input v-if="c.incoming" v-model="declineReason" type="text" placeholder="Комментарий к отказу (необязательно)"
                               class="mt-3 w-full bg-black border border-white/10 rounded-xl p-3 text-xs" />

                        <div v-if="openChallenge === c.id && c.history?.length" class="mt-4 border-t border-white/10 pt-3 space-y-1">
                            <div v-for="h in c.history" :key="h.id" class="text-[11px] text-white/50">
                                <span class="text-cyan-400 uppercase">{{ actionLabel(h.action) }}</span>
                                · {{ h.club }} · {{ h.admin }} · {{ h.at }}
                                <span v-if="h.comment" class="text-white/70"> — {{ h.comment }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div v-if="!tournaments.length && !pendingChallenges.length"
                     class="col-span-full border border-dashed border-white/10 rounded-3xl p-12 text-center text-white/30 uppercase text-xs tracking-widest">
                    Ивентов нет. Вызовите клуб из списка или создайте локальный турнир.
                </div>
                <div v-for="event in tournaments" :key="event.id"
                     class="bg-[#0a0a0a] border border-white/5 rounded-3xl p-6 relative overflow-hidden">
                    <div class="flex justify-between items-start mb-4 cursor-pointer" @click="openId = openId === event.id ? null : event.id">
                        <div>
                            <div class="text-[10px] text-blue-500 font-black uppercase mb-1">
                                {{ event.game?.title }}
                                <span v-if="event.opponent"> · {{ event.host?.name }} vs {{ event.opponent?.name }}</span>
                            </div>
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
                            <span class="font-bold">{{ event.computers_count }} / {{ event.players?.length || 0 }}<span v-if="event.roster_size"> · до {{ event.roster_size }} с клуба</span></span>
                        </div>
                        <div v-if="event.venue" class="flex justify-between">
                            <span class="text-white/40 uppercase">Площадка:</span>
                            <span>{{ venueLabel(event.venue) }}</span>
                        </div>
                    </div>
                    <p v-if="event.rules" class="text-[11px] text-white/40 mb-4 whitespace-pre-wrap">{{ event.rules }}</p>

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
                                <span>{{ p.name }} · {{ p.phone }} <span v-if="p.club" class="text-white/40">· {{ p.club }}</span> <span v-if="p.placement" class="text-blue-400">#{{ p.placement }}</span></span>
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

            <section v-if="closedChallenges.length" class="mt-10">
                <h2 class="text-[11px] uppercase tracking-[0.3em] text-white/30 font-black mb-4">Архив согласований</h2>
                <div class="space-y-2">
                    <div v-for="c in closedChallenges" :key="'x'+c.id" class="flex justify-between text-[11px] bg-white/5 rounded-xl px-4 py-3">
                        <span>{{ c.host?.name }} vs {{ c.guest?.name }} · {{ c.name }}</span>
                        <span :class="getStatusClass(c.status)" class="text-[9px] px-2 py-0.5 rounded-full border uppercase">{{ c.status }}</span>
                    </div>
                </div>
            </section>
        </div>

        <Teleport to="body">
            <div v-if="showCreateModal" class="fixed inset-0 flex items-center justify-center z-50 p-6">
                <div class="absolute inset-0 bg-black/90 backdrop-blur-xl" @click="showCreateModal = false"></div>
                <div class="relative w-full max-w-2xl bg-[#0a0a0a] border border-white/10 rounded-[1.125rem] p-10 max-h-[90vh] overflow-y-auto">
                    <h2 class="text-2xl font-black uppercase italic mb-8">Локальный ивент</h2>
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

        <Teleport to="body">
            <div v-if="showChallengeModal" class="fixed inset-0 flex items-center justify-center z-50 p-6">
                <div class="absolute inset-0 bg-black/90 backdrop-blur-xl" @click="showChallengeModal = false"></div>
                <div class="relative w-full max-w-2xl bg-[#0a0a0a] border border-cyan-500/20 rounded-[1.125rem] p-10 max-h-[90vh] overflow-y-auto">
                    <h2 class="text-2xl font-black uppercase italic mb-2">{{ counterId ? 'Встречные условия' : 'Вызов клуба' }}</h2>
                    <p class="text-[10px] text-white/35 uppercase tracking-widest mb-8">Соперник принимает регламент. Пока не приняли — ивента нет.</p>
                    <form @submit.prevent="submitChallenge" class="grid grid-cols-2 gap-6">
                        <div v-if="!counterId" class="col-span-2">
                            <label class="text-[10px] uppercase text-white/40 mb-2 block">Клуб-соперник</label>
                            <select v-if="clubs.length" v-model="challengeForm.guest_club_id" class="w-full bg-black border border-white/10 rounded-2xl p-4" required>
                                <option disabled value="">Любой зарегистрированный клуб</option>
                                <option v-for="club in clubs" :key="club.id" :value="club.id">{{ club.label || club.name }}</option>
                            </select>
                            <p v-else class="text-xs text-amber-300/80">
                                Список пуст.
                                <a :href="join_url" class="text-cyan-400 underline">Пусть соперник заведёт клуб</a>
                            </p>
                        </div>
                        <div class="col-span-2">
                            <label class="text-[10px] uppercase text-white/40 mb-2 block">Название</label>
                            <input v-model="challengeForm.name" type="text" class="w-full bg-black border border-white/10 rounded-2xl p-4" required />
                        </div>
                        <div>
                            <label class="text-[10px] uppercase text-white/40 mb-2 block">Игра</label>
                            <select v-model="challengeForm.game_id" class="w-full bg-black border border-white/10 rounded-2xl p-4" required>
                                <option disabled value="">Выберите</option>
                                <option v-for="game in games" :key="game.id" :value="game.id">{{ game.title }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-[10px] uppercase text-white/40 mb-2 block">Сетка</label>
                            <select v-model="challengeForm.format" class="w-full bg-black border border-white/10 rounded-2xl p-4">
                                <option value="single_elim">Single Elimination</option>
                                <option value="double_elim">Double Elimination</option>
                                <option value="round_robin">Round Robin</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-[10px] uppercase text-white/40 mb-2 block">Старт</label>
                            <input v-model="challengeForm.start_at" type="datetime-local" class="w-full bg-black border border-white/10 rounded-2xl p-4" required />
                        </div>
                        <div>
                            <label class="text-[10px] uppercase text-white/40 mb-2 block">Финиш</label>
                            <input v-model="challengeForm.end_at" type="datetime-local" class="w-full bg-black border border-white/10 rounded-2xl p-4" required />
                        </div>
                        <div>
                            <label class="text-[10px] uppercase text-white/40 mb-2 block">Игроков с клуба</label>
                            <input v-model="challengeForm.roster_size" type="number" min="1" max="32" class="w-full bg-black border border-white/10 rounded-2xl p-4" />
                        </div>
                        <div>
                            <label class="text-[10px] uppercase text-white/40 mb-2 block">Взнос ₽</label>
                            <input v-model="challengeForm.entry_fee" type="number" min="0" class="w-full bg-black border border-white/10 rounded-2xl p-4" />
                        </div>
                        <div>
                            <label class="text-[10px] uppercase text-white/40 mb-2 block">Площадка</label>
                            <select v-model="challengeForm.venue" class="w-full bg-black border border-white/10 rounded-2xl p-4">
                                <option value="host">Хозяин</option>
                                <option value="guest">Гость</option>
                                <option value="split">Обе</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-[10px] uppercase text-white/40 mb-2 block">Кто платит призы</label>
                            <select v-model="challengeForm.prize_funding" class="w-full bg-black border border-white/10 rounded-2xl p-4">
                                <option value="host">Хозяин</option>
                                <option value="guest">Гость</option>
                                <option value="split">Пополам</option>
                                <option value="each">Каждый своих</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-[10px] uppercase text-white/40 mb-2 block">1 место ₽</label>
                            <input v-model="challengeForm.prize_first" type="number" min="0" class="w-full bg-black border border-white/10 rounded-2xl p-4" />
                        </div>
                        <div>
                            <label class="text-[10px] uppercase text-white/40 mb-2 block">2 / 3 место ₽</label>
                            <div class="grid grid-cols-2 gap-2">
                                <input v-model="challengeForm.prize_second" type="number" min="0" class="bg-black border border-white/10 rounded-2xl p-4" />
                                <input v-model="challengeForm.prize_third" type="number" min="0" class="bg-black border border-white/10 rounded-2xl p-4" />
                            </div>
                        </div>
                        <div class="col-span-2">
                            <label class="text-[10px] uppercase text-white/40 mb-2 block">Регламент / заметки</label>
                            <textarea v-model="challengeForm.rules" rows="3" class="w-full bg-black border border-white/10 rounded-2xl p-4 text-sm" placeholder="BO3, FACEIT, только премьер, таймаут 10 мин…"></textarea>
                        </div>
                        <div class="col-span-2">
                            <label class="text-[10px] uppercase text-white/40 mb-2 block">Сообщение сопернику</label>
                            <input v-model="challengeForm.comment" type="text" class="w-full bg-black border border-white/10 rounded-2xl p-4" placeholder="Можем сдвинуть на час" />
                        </div>
                        <label class="col-span-2 flex items-center gap-3 text-[11px] uppercase tracking-widest text-white/60">
                            <input v-model="challengeForm.lock_games" type="checkbox" class="accent-cyan-500" />
                            На ПК арены в шелле только эта игра
                        </label>
                        <button type="submit" class="col-span-2 py-5 bg-cyan-600 hover:bg-cyan-500 text-white font-black uppercase rounded-2xl tracking-widest mt-2">
                            {{ counterId ? 'Отправить встречные' : 'Отправить на согласование' }}
                        </button>
                    </form>
                </div>
            </div>
        </Teleport>
    </AdminLayout>
</template>
