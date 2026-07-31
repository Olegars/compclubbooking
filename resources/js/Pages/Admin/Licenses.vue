<script setup>
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const props = defineProps({ games: Array, clubs: Array })

// --- СОСТОЯНИЕ ИНТЕРФЕЙСА ---
const selectedGame = ref(null)
const showGameModal = ref(false)
const showAccountModal = ref(false)
const posterPreview = ref(null)
const isEditing = ref(false) // Флаг: создание новой игры или изменение старой

// --- ФОРМЫ ---
const gameForm = useForm({
    id: null, // Добавлено для отслеживания редактируемой игры
    title: '',
    platform: 'Steam',
    category: '',
    poster: null,
    exe_path: '',
    launch_args: ''
})

const accountForm = useForm({
    login: '',
    password: '',
    status: 'free',
    club_id: null
})

const offerForm = useForm({
    club_id: null,
    billing_mode: 'free',
    unit_price_rubles: 0,
    billing_unit_minutes: 60,
    is_enabled: true
})

// --- ЛОГИКА ---
const selectGame = (game) => {
    selectedGame.value = game
    offerForm.club_id = game.club_offers?.[0]?.club_id ?? props.clubs?.[0]?.id ?? null
    selectOffer()
}

// Строки club_games может ещё не быть: тогда показываем дефолт «бесплатно»,
// а сохранение создаст запись через updateOrCreate.
const loadOffer = (offer) => {
    offerForm.billing_mode = offer?.billing_mode ?? 'free'
    offerForm.unit_price_rubles = Number(offer?.unit_price_minor ?? 0) / 100
    offerForm.billing_unit_minutes = offer?.billing_unit_minutes ?? 60
    offerForm.is_enabled = offer ? Boolean(offer.is_enabled) : true
}

const findOffer = (clubId) =>
    selectedGame.value?.club_offers?.find(offer => Number(offer.club_id) === Number(clubId))

const selectOffer = () => {
    loadOffer(findOffer(offerForm.club_id))
}

const submitOffer = () => {
    if (!selectedGame.value || !offerForm.club_id) return
    offerForm.put(`/admin/licenses/games/${selectedGame.value.id}/offers/${offerForm.club_id}`, {
        preserveScroll: true,
        onSuccess: () => {
            const updated = props.games.find(game => game.id === selectedGame.value.id)
            if (updated) {
                selectedGame.value = updated
                selectOffer()
            }
        }
    })
}

// Открытие модалки для добавления новой игры
const openCreateModal = () => {
    isEditing.value = false
    posterPreview.value = null
    gameForm.reset()
    gameForm.id = null
    gameForm.platform = 'Steam'
    showGameModal.value = true
}

// Открытие модалки для ИЗМЕНЕНИЯ существующей игры
const openEditModal = (game) => {
    isEditing.value = true
    gameForm.id = game.id
    gameForm.title = game.title
    gameForm.platform = game.platform
    gameForm.category = game.category || ''
    gameForm.exe_path = game.exe_path || ''
    gameForm.launch_args = game.launch_args || ''
    gameForm.poster = null

    // Если у игры уже есть постер, показываем его в превью
    posterPreview.value = game.poster
        ? (game.poster.startsWith('/') || game.poster.startsWith('http') ? game.poster : '/' + game.poster)
        : null

    showGameModal.value = true
}

// Обработка выбора файла постера
const handlePosterChange = (e) => {
    const file = e.target.files[0]
    if (file) {
        gameForm.poster = file
        posterPreview.value = URL.createObjectURL(file)
    }
}

const submitGame = () => {
    // Если мы редактируем, шлем на тот же эндпоинт добавления/сохранения,
    // но бэкенд определит изменение по наличию переданного параметра id
    gameForm.post('/admin/licenses/games', {
        preserveScroll: true,
        onSuccess: () => {
            showGameModal.value = false
            gameForm.reset()
            posterPreview.value = null

            // Синхронизируем состояние правой панели с обновленными данными
            if (selectedGame.value) {
                const updated = props.games.find(g => g.id === selectedGame.value.id)
                if (updated) selectedGame.value = updated
            } else if (isEditing.value && gameForm.id) {
                const updated = props.games.find(g => g.id === gameForm.id)
                if (updated) selectedGame.value = updated
            }
        }
    })
}

const openAccountModal = () => {
    accountForm.reset()
    accountForm.club_id = selectedGame.value?.club_offers?.[0]?.club_id ?? props.clubs?.[0]?.id ?? null
    showAccountModal.value = true
}

const submitAccount = () => {
    if (!selectedGame.value) return
    accountForm.club_id ??= props.clubs?.[0]?.id ?? null

    accountForm.post(`/admin/licenses/games/${selectedGame.value.id}/accounts`, {
        preserveScroll: true,
        onSuccess: () => {
            showAccountModal.value = false
            accountForm.reset()
            const updatedGame = props.games.find(g => g.id === selectedGame.value.id)
            if (updatedGame) selectedGame.value = updatedGame
        }
    })
}

const deleteAccount = (id) => {
    if(confirm('Удалить этот аккаунт из пула?')) {
        router.delete(`/admin/licenses/accounts/${id}`, {
            preserveScroll: true,
            onSuccess: () => {
                const updatedGame = props.games.find(g => g.id === selectedGame.value.id)
                if (updatedGame) selectedGame.value = updatedGame
            }
        })
    }
}

const deleteGame = (id) => {
    if(confirm('ВНИМАНИЕ: Вы собираетесь удалить игру и ВСЕ ПРИВЯЗАННЫЕ К НЕЙ АККАУНТЫ. Продолжить?')) {
        router.delete(`/admin/licenses/games/${id}`, {
            preserveScroll: true,
            onSuccess: () => { selectedGame.value = null }
        })
    }
}

// --- ВИЗУАЛЬНЫЕ ХЕЛПЕРЫ ---
const getPlatformColor = (platform) => {
    const colors = {
        'Steam': 'text-blue-500',
        'Battle.net': 'text-cyan-500',
        'Epic': 'text-gray-300',
        'EA': 'text-orange-500',
        'VK Play': 'text-[#0077ff]',
        'Riot': 'text-red-500',
        'Lesta': 'text-amber-400'
    }
    return colors[platform] || 'text-white'
}

const getStatusBadge = (status) => {
    const badges = {
        'free': 'bg-[#22c55e]/10 text-[#22c55e] border-[#22c55e]/30 shadow-[0_0_10px_rgba(34,197,94,0.1)]',
        'in_use': 'bg-cyan-500/10 text-cyan-500 border-cyan-500/30 shadow-[0_0_10px_rgba(6,182,212,0.1)]',
        'banned': 'bg-red-500/10 text-red-500 border-red-500/30 shadow-[0_0_10px_rgba(239,68,68,0.1)]',
        'maintenance': 'bg-orange-500/10 text-orange-500 border-orange-500/30'
    }
    return badges[status] || 'bg-white/5 text-white'
}
</script>

<template>
    <AdminLayout>
        <div class="p-8 min-h-full flex flex-col font-mono text-white animate-in fade-in duration-500">
            <div class="flex justify-between items-center mb-10">
                <h1 class="text-4xl font-black italic tracking-tighter uppercase text-blue-500">Каталог и Лицензии</h1>
                <button @click="openCreateModal" class="px-6 py-4 bg-blue-600 hover:bg-blue-500 text-white font-black rounded-xl tracking-widest text-xs uppercase transition-all shadow-[0_0_20px_rgba(37,99,235,0.3)] active:scale-95">
                    + Добавить Игру
                </button>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 flex-1 items-start">

                <div class="lg:col-span-4 space-y-4 max-h-[80vh] overflow-y-auto custom-scrollbar pr-2">
                    <div v-for="game in games" :key="game.id"
                         @click="selectGame(game)"
                         class="bg-[#0a0a0a] border rounded-3xl p-5 cursor-pointer transition-all group relative overflow-hidden flex gap-4"
                         :class="selectedGame?.id === game.id ? 'border-blue-500 shadow-[0_0_30px_rgba(37,99,235,0.15)] scale-[1.02]' : 'border-white/5 hover:border-white/20 hover:bg-white/[0.02]'">

                        <div class="w-20 h-28 rounded-xl overflow-hidden bg-white/5 flex-shrink-0 relative">
                            <img v-if="game.poster" :src="game.poster.startsWith('/') || game.poster.startsWith('http') ? game.poster : '/' + game.poster" :alt="game.title" loading="lazy" decoding="async" class="w-full h-full object-cover opacity-80 group-hover:opacity-100 transition-opacity" />
                            <div v-else class="w-full h-full flex items-center justify-center text-3xl opacity-20 bg-gradient-to-br from-blue-900/20 to-black">🎮</div>
                        </div>

                        <div class="flex-1 flex flex-col justify-between py-1">
                            <div>
                                <div class="flex justify-between items-start">
                                    <div class="text-lg font-black uppercase italic leading-tight">{{ game.title }}</div>
                                    <button @click.stop="deleteGame(game.id)" class="text-red-500/20 hover:text-red-500 transition-all p-1 rounded-lg hover:bg-red-500/10" title="Удалить игру полностью">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </div>
                                <div class="flex items-center gap-2 mt-1">
                                    <span class="text-[9px] uppercase font-bold tracking-widest" :class="getPlatformColor(game.platform)">{{ game.platform }}</span>
                                    <span v-if="game.category" class="text-[9px] uppercase font-bold text-white/30 tracking-widest">• {{ game.category }}</span>
                                    <span v-if="game.club_offers?.some(o => o.is_paid || o.billing_mode !== 'free')"
                                          class="text-[8px] uppercase font-black tracking-widest px-1.5 py-0.5 rounded bg-[#22c55e]/15 text-[#22c55e] border border-[#22c55e]/30">
                                        Платная
                                    </span>
                                </div>
                            </div>

                            <div class="mt-3">
                                <div class="flex justify-between text-[9px] text-white/40 uppercase mb-1.5 font-black tracking-widest">
                                    <span class="text-[#22c55e]">Свободно: {{ game.accounts.filter(a => a.status === 'free').length }}</span>
                                    <span>Всего: {{ game.accounts.length }}</span>
                                </div>
                                <div class="w-full bg-white/5 h-2 rounded-full overflow-hidden flex">
                                    <div class="bg-[#22c55e] transition-all duration-500" :style="`width: ${(game.accounts.filter(a => a.status === 'free').length / (game.accounts.length || 1)) * 100}%`"></div>
                                    <div class="bg-cyan-500 transition-all duration-500" :style="`width: ${(game.accounts.filter(a => a.status === 'in_use').length / (game.accounts.length || 1)) * 100}%`"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-if="games.length === 0" class="p-10 border-2 border-dashed border-white/5 rounded-3xl text-center">
                        <div class="text-4xl mb-4 opacity-50">🕹️</div>
                        <div class="text-white/30 text-xs italic uppercase font-black tracking-widest">Каталог пуст</div>
                    </div>
                </div>

                <div class="lg:col-span-8 bg-[#0a0a0a] border border-white/5 rounded-[1.125rem] p-10 shadow-2xl min-h-[600px] flex flex-col relative overflow-hidden">

                    <div class="absolute -right-20 -top-20 opacity-[0.02] pointer-events-none">
                        <svg class="w-96 h-96 text-white" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 2a8 8 0 100 16 8 8 0 000-16zM8 7a1 1 0 112 0v4a1 1 0 11-2 0V7zm2 8a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" /></svg>
                    </div>

                    <template v-if="selectedGame">
                        <div class="flex justify-between items-end mb-6 relative z-10">
                            <div>
                                <div class="text-[10px] text-white/30 uppercase tracking-[0.4em] font-black italic mb-2">Реестр доступов</div>
                                <h2 class="text-3xl font-black uppercase italic flex items-center gap-4">
                                    {{ selectedGame.title }}
                                    <!-- КНОПКА ИЗМЕНЕНИЯ ИГРЫ -->
                                    <button @click="openEditModal(selectedGame)" class="p-2 bg-white/5 hover:bg-blue-600/20 text-white/40 hover:text-blue-400 border border-white/5 hover:border-blue-500/30 rounded-xl transition-all" title="Редактировать метаданные">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                    </button>
                                    <!-- КНОПКА УДАЛЕНИЯ ИГРЫ ИЗ ДЕТАЛКИ -->
                                    <button @click="deleteGame(selectedGame.id)" class="p-2 bg-white/5 hover:bg-red-600/20 text-white/40 hover:text-red-500 border border-white/5 hover:border-red-500/30 rounded-xl transition-all" title="Удалить игру из базы">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </h2>
                            </div>
                            <button @click="openAccountModal" class="px-6 py-3 bg-white/5 hover:bg-white/10 text-white border border-white/10 font-black rounded-xl tracking-widest text-[10px] uppercase transition-all active:scale-95">
                                + Внести аккаунт
                            </button>
                        </div>

                        <div class="mb-8 p-4 bg-blue-900/10 border border-blue-500/20 rounded-2xl relative z-10 flex flex-col gap-2">
                            <div class="text-[9px] text-blue-500 font-black uppercase tracking-widest mb-1">C++ Shell Bridge Data</div>
                            <div class="flex gap-4 items-center">
                                <span class="text-white/40 text-[10px] uppercase w-16">Путь:</span>
                                <code class="text-xs text-green-400 bg-black/50 px-2 py-1 rounded w-full">{{ selectedGame.exe_path || 'Не задан' }}</code>
                            </div>
                            <div class="flex gap-4 items-center">
                                <span class="text-white/40 text-[10px] uppercase w-16">Ключи:</span>
                                <code class="text-xs text-yellow-400 bg-black/50 px-2 py-1 rounded w-full">{{ selectedGame.launch_args || 'Нет параметров' }}</code>
                            </div>
                        </div>

                        <form v-if="props.clubs?.length" @submit.prevent="submitOffer"
                              class="mb-8 p-5 bg-white/[0.02] border border-white/10 rounded-2xl relative z-10">
                            <div class="flex items-center justify-between mb-4">
                                <div>
                                    <div class="text-[10px] text-[#22c55e] font-black uppercase tracking-widest">Тариф игры в клубе</div>
                                    <p class="mt-1 text-[8px] text-white/30 uppercase tracking-widest leading-relaxed">
                                        «Бесплатно» — только shell. «Платно» — можно бронировать заранее, в shell игра всё равно доступна бесплатно (если аккаунт свободен).
                                    </p>
                                </div>
                                <label class="flex items-center gap-2 text-[9px] uppercase text-white/50 font-black">
                                    <input v-model="offerForm.is_enabled" type="checkbox" class="accent-green-500" />
                                    Доступна
                                </label>
                            </div>
                            <div class="grid grid-cols-4 gap-3">
                                <select v-model.number="offerForm.club_id" @change="selectOffer"
                                        class="bg-black border border-white/10 rounded-xl p-3 text-xs text-white">
                                    <option v-for="club in props.clubs" :key="club.id" :value="club.id">
                                        {{ club.name }}
                                    </option>
                                </select>
                                <select v-model="offerForm.billing_mode"
                                        class="bg-black border border-white/10 rounded-xl p-3 text-xs text-white">
                                    <option value="free">Бесплатно (только shell)</option>
                                    <option value="per_seat_hour">Платная: место / период (+ shell)</option>
                                    <option value="per_seat_booking">Платная: место / бронь (+ shell)</option>
                                    <option value="per_booking_hour">Платная: бронь / период (+ shell)</option>
                                    <option value="fixed">Платная: фикс (+ shell)</option>
                                </select>
                                <input v-model.number="offerForm.unit_price_rubles" type="number" min="0" step="0.01"
                                       placeholder="Цена, ₽"
                                       :disabled="offerForm.billing_mode === 'free'"
                                       class="bg-black border border-white/10 rounded-xl p-3 text-xs text-white disabled:opacity-40" />
                                <div class="flex gap-2">
                                    <input v-model.number="offerForm.billing_unit_minutes" type="number" min="1"
                                           title="Размер периода в минутах"
                                           :disabled="offerForm.billing_mode === 'free'"
                                           class="w-full bg-black border border-white/10 rounded-xl p-3 text-xs text-white disabled:opacity-40" />
                                    <button type="submit" :disabled="offerForm.processing"
                                            class="px-4 bg-[#22c55e] text-black rounded-xl text-[9px] font-black uppercase disabled:opacity-50">
                                        Сохранить
                                    </button>
                                </div>
                            </div>
                            <div class="mt-3 flex items-center gap-2">
                                <span :class="['px-2 py-1 rounded-lg text-[8px] font-black uppercase tracking-widest',
                                               offerForm.billing_mode !== 'free'
                                                 ? 'bg-[#22c55e]/15 text-[#22c55e] border border-[#22c55e]/30'
                                                 : 'bg-white/5 text-white/40 border border-white/10']">
                                    {{ offerForm.billing_mode !== 'free' ? 'Платная · бронь + shell' : 'Бесплатная · только shell' }}
                                </span>
                            </div>
                        </form>

                        <div class="space-y-3 flex-1 overflow-y-auto custom-scrollbar pr-2 relative z-10">
                            <div v-for="acc in selectedGame.accounts" :key="acc.id"
                                 class="flex items-center justify-between p-5 bg-white/[0.02] border border-white/5 rounded-2xl group hover:border-white/10 transition-colors">

                                <div class="w-1/4">
                                    <span class="px-4 py-1.5 text-[9px] uppercase font-black border rounded-full tracking-widest" :class="getStatusBadge(acc.status)">
                                        {{ acc.status }}
                                    </span>
                                </div>

                                <div class="w-1/3 text-sm font-bold tracking-wider">{{ acc.login }}</div>

                                <div class="w-1/4 text-sm font-mono text-white/20 group-hover:text-white transition-colors cursor-pointer">
                                    ••••••••••••
                                </div>

                                <button @click="deleteAccount(acc.id)" class="text-red-500/20 hover:text-red-500 p-2 bg-red-500/0 hover:bg-red-500/10 rounded-lg transition-all" title="Удалить аккаунт">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </div>

                            <div v-if="selectedGame.accounts.length === 0" class="text-center py-16 text-white/20 text-xs uppercase tracking-widest font-black italic border-2 border-dashed border-white/5 rounded-2xl">
                                Нет привязанных доступов
                            </div>
                        </div>
                    </template>

                    <div v-else class="h-full flex flex-col items-center justify-center text-white/20 opacity-50 relative z-10">
                        <div class="text-6xl mb-6">🗄️</div>
                        <div class="text-xs uppercase tracking-[0.4em] font-black italic text-center leading-relaxed">
                            Выберите игру из пула<br>для управления ключами
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- МОДАЛЬНОЕ ОКНО ДОБАВЛЕНИЯ / РЕДАКТИРОВАНИЯ -->
        <Teleport to="body">
            <div v-if="showGameModal" class="fixed inset-0 flex items-center justify-center z-[9999900] p-6 font-mono">
                <div class="absolute inset-0 bg-black/95 backdrop-blur-md" @click="showGameModal = false"></div>
                <div class="relative w-full max-w-xl bg-[#0a0a0a] border border-blue-500/30 rounded-[1.125rem] p-10 shadow-[0_0_100px_rgba(37,99,235,0.15)] animate-in zoom-in-95 duration-200 max-h-[95vh] overflow-y-auto custom-scrollbar">
                    <h2 class="text-blue-500 text-3xl font-black uppercase italic mb-8 tracking-tighter">
                        {{ isEditing ? 'Изменение метаданных' : 'Метаданные игры' }}
                    </h2>

                    <form @submit.prevent="submitGame" class="space-y-6">
                        <!-- Скрытое ID для бэкенда -->
                        <input v-model="gameForm.id" type="hidden" />

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="text-[10px] uppercase text-white/40 tracking-widest font-black italic mb-2 block">Название игры</label>
                                <input v-model="gameForm.title" type="text" placeholder="Dota 2" class="w-full bg-black border-2 border-white/5 rounded-2xl p-4 text-white font-bold focus:border-blue-500 outline-none transition-colors" required />
                            </div>
                            <div>
                                <label class="text-[10px] uppercase text-white/40 tracking-widest font-black italic mb-2 block">Платформа</label>
                                <select v-model="gameForm.platform" class="w-full bg-black border-2 border-white/5 rounded-2xl p-4 text-white font-bold focus:border-blue-500 outline-none transition-colors appearance-none cursor-pointer">
                                    <option>Steam</option><option>Battle.net</option><option>Epic</option><option>EA</option><option>VK Play</option><option>Riot</option><option>Lesta</option><option>Other</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4 items-end">
                            <div>
                                <label class="text-[10px] uppercase text-white/40 tracking-widest font-black italic mb-2 block">Категория</label>
                                <input v-model="gameForm.category" type="text" placeholder="MOBA / Шутер" class="w-full bg-black border-2 border-white/5 rounded-2xl p-4 text-white font-bold focus:border-blue-500 outline-none transition-colors" />
                            </div>

                            <div>
                                <label class="text-[10px] uppercase text-white/40 tracking-widest font-black italic mb-2 block">Постер игры</label>
                                <div class="flex gap-3 items-center">
                                    <label class="flex-1 flex flex-col items-center justify-center bg-black border-2 border-dashed border-white/10 hover:border-blue-500/50 rounded-2xl p-3 cursor-pointer transition-colors group/upload">
                                        <div class="flex items-center gap-2 text-xs font-bold text-white/40 group-hover/upload:text-blue-400">
                                            <span>📁</span>
                                            <span>{{ gameForm.poster || (isEditing && posterPreview) ? 'Изменить файл' : 'Выбрать постер' }}</span>
                                        </div>
                                        <input type="file" accept="image/*" @change="handlePosterChange" class="hidden" />
                                    </label>

                                    <div v-if="posterPreview" class="w-12 h-14 bg-white/5 rounded-xl border border-white/10 overflow-hidden shrink-0">
                                        <img :src="posterPreview" class="w-full h-full object-cover" />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="p-6 bg-blue-500/5 border border-blue-500/20 rounded-[0.875rem] space-y-4">
                            <div class="flex items-center gap-3 mb-2">
                                <div class="w-2 h-2 bg-blue-500 rounded-full animate-pulse"></div>
                                <div class="text-[10px] text-blue-400 font-black uppercase tracking-widest">Инструкции запуска (Shell Bridge)</div>
                            </div>

                            <div>
                                <label class="text-[10px] uppercase text-white/40 tracking-widest font-black italic mb-2 block">Путь к лаунчеру / .exe</label>
                                <input v-model="gameForm.exe_path" type="text" placeholder="D:\Games\Steam\steam.exe" class="w-full bg-black/50 border-2 border-white/5 rounded-xl p-4 text-blue-200 text-xs font-mono focus:border-blue-500 outline-none transition-colors" />
                            </div>
                            <div>
                                <label class="text-[10px] uppercase text-white/40 tracking-widest font-black italic mb-2 block">Аргументы (Ключи)</label>
                                <input v-model="gameForm.launch_args" type="text" placeholder="-applaunch 570" class="w-full bg-black/50 border-2 border-white/5 rounded-xl p-4 text-yellow-200 text-xs font-mono focus:border-blue-500 outline-none transition-colors" />
                            </div>
                        </div>

                        <div class="flex gap-4 mt-8 pt-6 border-t border-white/5">
                            <button type="button" @click="showGameModal = false" class="w-1/3 py-5 border border-white/10 text-white/40 hover:text-white rounded-2xl font-black uppercase text-[10px] tracking-widest transition-all">Отмена</button>
                            <button type="submit" :disabled="gameForm.processing" class="w-2/3 py-5 bg-blue-600 hover:bg-blue-500 text-white font-black uppercase text-[10px] rounded-2xl tracking-widest transition-all disabled:opacity-50">
                                {{ gameForm.processing ? 'Запись...' : 'Сохранить в базу' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

        <!-- ВНЕСЕНИЕ АККАУНТА -->
        <Teleport to="body">
            <div v-if="showAccountModal" class="fixed inset-0 flex items-center justify-center z-[9999900] p-6 font-mono">
                <div class="absolute inset-0 bg-black/95 backdrop-blur-md" @click="showAccountModal = false"></div>
                <div class="relative w-full max-w-md bg-[#0a0a0a] border border-white/20 rounded-[1.125rem] p-10 shadow-[0_0_100px_rgba(255,255,255,0.05)] animate-in zoom-in-95 duration-200">
                    <h2 class="text-white text-3xl font-black uppercase italic mb-8 tracking-tighter">Внести данные</h2>

                    <form @submit.prevent="submitAccount" class="space-y-5">
                        <div>
                            <label class="text-[10px] uppercase text-white/40 tracking-widest font-black italic mb-2 block">Логин / Email</label>
                            <input v-model="accountForm.login" type="text" placeholder="cyber_acc_1@mail.ru" class="w-full bg-black border-2 border-white/5 rounded-2xl p-4 text-white font-bold focus:border-white/50 outline-none transition-colors" required autocomplete="off" />
                        </div>

                        <div>
                            <label class="text-[10px] uppercase text-white/40 tracking-widest font-black italic mb-2 block">Пароль</label>
                            <input v-model="accountForm.password" type="text" placeholder="Pass123!@#" class="w-full bg-black border-2 border-white/5 rounded-2xl p-4 text-white font-bold focus:border-white/50 outline-none transition-colors" required autocomplete="off" />
                        </div>

                        <div>
                            <label class="text-[10px] uppercase text-white/40 tracking-widest font-black italic mb-2 block">Клуб</label>
                            <select v-model.number="accountForm.club_id" class="w-full bg-black border-2 border-white/5 rounded-2xl p-4 text-white font-bold focus:border-white/50 outline-none transition-colors appearance-none cursor-pointer">
                                <option v-for="club in props.clubs" :key="club.id" :value="club.id">{{ club.name }}</option>
                            </select>
                        </div>

                        <div>
                            <label class="text-[10px] uppercase text-white/40 tracking-widest font-black italic mb-2 block">Начальный статус</label>
                            <select v-model="accountForm.status" class="w-full bg-black border-2 border-white/5 rounded-2xl p-4 text-white font-bold focus:border-white/50 outline-none transition-colors appearance-none cursor-pointer">
                                <option value="free">Свободен (Готов к выдаче)</option>
                                <option value="banned">В бане / Требует проверки</option>
                                <option value="maintenance">Техобслуживание</option>
                            </select>
                        </div>

                        <div class="flex gap-4 mt-8 pt-6 border-t border-white/5">
                            <button type="button" @click="showAccountModal = false" class="w-1/3 py-5 border border-white/10 text-white/40 hover:text-white rounded-2xl font-black uppercase text-[10px] tracking-widest transition-all">Отмена</button>
                            <button type="submit" :disabled="accountForm.processing" class="w-2/3 py-5 bg-white text-black hover:bg-white/80 font-black uppercase text-[10px] rounded-2xl tracking-widest transition-all disabled:opacity-50">
                                {{ accountForm.processing ? 'Запись...' : 'Сохранить' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </AdminLayout>
</template>

<style scoped>
@reference "../../../css/app.css";

.custom-scrollbar::-webkit-scrollbar { width: 4px; }
.custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 10px; }
.custom-scrollbar::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.2); }
</style>
