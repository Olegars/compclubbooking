<script setup>
import { ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const props = defineProps({
    tournaments: Array,
    games: Array,
    computers: Array // Список всех ПК клуба для выбора
})

const showCreateModal = ref(false)
const form = useForm({
    name: '',
    game_id: '',
    start_at: '',
    end_at: '',
    entry_fee: 0,
    prize_pool: '',
    selected_pcs: [] // ID выбранных компьютеров
})

const submit = () => {
    form.post('/admin/tournaments', {
        onSuccess: () => {
            showCreateModal.value = false
            form.reset()
        }
    })
}

const updateStatus = (id, status) => {
    router.patch(`/admin/tournaments/${id}/status`, { status })
}
</script>

<template>
    <AdminLayout>
        <div class="p-8 h-full flex flex-col font-mono text-white">
            <div class="flex justify-between items-center mb-8">
                <h1 class="text-4xl font-black italic tracking-tighter uppercase text-blue-500">Event Manager</h1>
                <button @click="showCreateModal = true" class="px-6 py-3 bg-blue-600 hover:bg-blue-500 text-white font-black rounded-xl tracking-widest text-xs uppercase transition-all shadow-[0_0_20px_rgba(37,99,235,0.3)]">
                    Создать Ивент
                </button>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <div v-for="event in tournaments" :key="event.id" class="bg-[#0a0a0a] border border-white/5 rounded-3xl p-6 relative overflow-hidden group">
                    <div class="flex justify-between items-start mb-4">
                        <div>
                            <div class="text-[10px] text-blue-500 font-black uppercase mb-1">{{ event.game.title }}</div>
                            <h3 class="text-xl font-black uppercase italic">{{ event.name }}</h3>
                        </div>
                        <span :class="getStatusClass(event.status)" class="text-[9px] px-3 py-1 rounded-full font-black border uppercase">
                            {{ event.status }}
                        </span>
                    </div>

                    <div class="space-y-2 mb-6">
                        <div class="flex justify-between text-xs">
                            <span class="text-white/40 uppercase">Взнос:</span>
                            <span class="font-bold">{{ event.entry_fee }} ₽</span>
                        </div>
                        <div class="flex justify-between text-xs">
                            <span class="text-white/40 uppercase">Задействовано:</span>
                            <span class="text-blue-400 font-bold">{{ event.computers_count }} ПК</span>
                        </div>
                    </div>

                    <div class="flex gap-2">
                        <button v-if="event.status === 'planned'" @click="updateStatus(event.id, 'active')" class="flex-1 py-2 bg-green-600 hover:bg-green-500 text-[10px] font-black uppercase rounded-lg transition-all">Старт</button>
                        <button v-if="event.status === 'active'" @click="updateStatus(event.id, 'finished')" class="flex-1 py-2 bg-white/10 hover:bg-white/20 text-[10px] font-black uppercase rounded-lg transition-all">Завершить</button>
                    </div>
                </div>
            </div>
        </div>

        <Teleport to="body">
            <div v-if="showCreateModal" class="fixed inset-0 flex items-center justify-center z-50 p-6">
                <div class="absolute inset-0 bg-black/90 backdrop-blur-xl" @click="showCreateModal = false"></div>
                <div class="relative w-full max-w-2xl bg-[#0a0a0a] border border-white/10 rounded-[1.125rem] p-10">
                    <h2 class="text-2xl font-black uppercase italic mb-8">Настройка Ивента</h2>
                    <form @submit.prevent="submit" class="grid grid-cols-2 gap-6">
                        <div class="col-span-2">
                            <label class="text-[10px] uppercase text-white/40 mb-2 block">Название турнира</label>
                            <input v-model="form.name" type="text" class="w-full bg-black border border-white/10 rounded-2xl p-4 outline-none focus:border-blue-500" required />
                        </div>
                        <div>
                            <label class="text-[10px] uppercase text-white/40 mb-2 block">Игра</label>
                            <select v-model="form.game_id" class="w-full bg-black border border-white/10 rounded-2xl p-4 outline-none focus:border-blue-500">
                                <option v-for="game in games" :key="game.id" :value="game.id">{{ game.title }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-[10px] uppercase text-white/40 mb-2 block">Взнос (₽)</label>
                            <input v-model="form.entry_fee" type="number" class="w-full bg-black border border-white/10 rounded-2xl p-4 outline-none focus:border-blue-500" />
                        </div>

                        <div class="col-span-2">
                            <label class="text-[10px] uppercase text-white/40 mb-2 block">Выберите компьютеры для брони</label>
                            <div class="grid grid-cols-8 gap-2">
                                <div v-for="pc in computers" :key="pc.id"
                                     @click="togglePC(pc.id)"
                                     :class="form.selected_pcs.includes(pc.id) ? 'bg-blue-600 border-blue-400' : 'bg-white/5 border-white/10'"
                                     class="aspect-square flex items-center justify-center border rounded-lg cursor-pointer text-[10px] font-black transition-all">
                                    {{ pc.number }}
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="col-span-2 py-5 bg-blue-600 hover:bg-blue-500 text-white font-black uppercase rounded-2xl tracking-widest mt-4">Опубликовать и забронировать ПК</button>
                    </form>
                </div>
            </div>
        </Teleport>
    </AdminLayout>
</template>
