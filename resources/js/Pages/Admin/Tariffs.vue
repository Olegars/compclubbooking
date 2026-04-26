<script setup>
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

// Добавили zones
const props = defineProps({ tariffs: Array, zones: Array })

// --- УПРАВЛЕНИЕ МОДАЛКОЙ И ФОРМОЙ ---
const showAddModal = ref(false)

const form = useForm({
    name: '',
    category: props.zones && props.zones.length > 0 ? props.zones[0].slug : '', // Берем первый зал по умолчанию
    threshold_hours: 1,
    price_per_package: ''
})

const submitTariff = () => {
    form.post('/admin/tariffs', {
        preserveScroll: true,
        onSuccess: () => {
            showAddModal.value = false
            form.reset()
        }
    })
}

// --- УДАЛЕНИЕ И СТАТУС ---
const deleteTariff = (id) => {
    if (confirm('Удалить этот тариф навсегда?')) {
        router.delete(`/admin/tariffs/${id}`, { preserveScroll: true })
    }
}

const toggleStatus = (tariff) => {
    router.put(`/admin/tariffs/${tariff.id}`, { is_active: !tariff.is_active }, { preserveScroll: true })
}

// Поиск красивого названия зоны по slug
const getZoneName = (slug) => {
    if (!props.zones) return slug
    const zone = props.zones.find(z => z.slug === slug)
    return zone ? zone.name : slug
}

// Поиск цвета зоны для красивого бейджа
const getZoneColor = (slug) => {
    if (!props.zones) return '#ffffff'
    const zone = props.zones.find(z => z.slug === slug)
    return zone ? zone.color : '#ffffff'
}
</script>

<template>
    <AdminLayout>
        <div class="p-8 min-h-full font-mono text-white animate-in fade-in duration-500">
            <div class="flex justify-between items-center mb-10">
                <h1 class="text-4xl font-black italic tracking-tighter uppercase text-[#22c55e]">Управление тарифами</h1>
                <button @click="showAddModal = true" class="px-6 py-4 bg-[#22c55e] text-black font-black rounded-xl hover:bg-[#1ea34d] transition-all tracking-widest text-xs uppercase shadow-[0_0_20px_rgba(34,197,94,0.3)] active:scale-95">
                    + Новый тариф
                </button>
            </div>

            <div v-if="tariffs.length > 0" class="grid grid-cols-1 gap-4">
                <div v-for="tariff in tariffs" :key="tariff.id"
                     class="bg-[#0a0a0a] border p-6 rounded-3xl flex items-center justify-between group transition-all"
                     :class="tariff.is_active ? 'border-white/5 hover:border-[#22c55e]/30' : 'border-red-500/10 opacity-50'">

                    <div class="flex gap-12 items-center">
                        <div>
                            <span class="text-[10px] text-white/30 uppercase block mb-1 font-black tracking-widest">Название</span>
                            <div class="text-xl font-black uppercase italic" :class="tariff.is_active ? 'text-white' : 'text-white/40'">
                                {{ tariff.name }}
                            </div>
                        </div>
                        <div>
                            <span class="text-[10px] text-white/30 uppercase block mb-1 font-black tracking-widest">Порог (ч)</span>
                            <div class="text-2xl font-black" :class="tariff.is_active ? 'text-[#22c55e]' : 'text-white/40'">
                                {{ tariff.threshold_hours }}<span class="text-sm ml-1 opacity-50">ч.</span>
                            </div>
                        </div>
                        <div>
                            <span class="text-[10px] text-white/30 uppercase block mb-1 font-black tracking-widest">Цена</span>
                            <div class="text-2xl font-black" :class="tariff.is_active ? 'text-white' : 'text-white/40'">
                                {{ Number(tariff.price_per_package).toFixed(0) }}<span class="text-sm ml-1 opacity-50">₽</span>
                            </div>
                        </div>
                        <div>
                            <span class="text-[10px] text-white/30 uppercase block mb-1 font-black tracking-widest">Категория зала</span>
                            <div class="flex items-center gap-2 px-4 py-1.5 bg-white/5 rounded-full border border-white/10">
                                <span class="w-2 h-2 rounded-full shadow-[0_0_8px_currentColor]" :style="{ backgroundColor: getZoneColor(tariff.category), color: getZoneColor(tariff.category) }"></span>
                                <span class="text-[10px] font-black uppercase tracking-widest">{{ getZoneName(tariff.category) }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="flex gap-4 items-center">
                        <button @click="toggleStatus(tariff)"
                                :class="tariff.is_active ? 'text-[#22c55e] hover:text-[#1ea34d]' : 'text-white/20 hover:text-white'"
                                class="transition-all active:scale-95" title="Включить/Выключить">
                            <svg class="w-8 h-8" fill="currentColor" viewBox="0 0 20 20"><path d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293l-4-4a1 1 0 00-1.414 1.414L10.586 10l-2.293 2.293a1 1 0 001.414 1.414l4-4a1 1 0 000-1.414z"/></svg>
                        </button>
                        <div class="w-px h-8 bg-white/10 mx-2"></div>
                        <button @click="deleteTariff(tariff.id)" class="text-red-500/40 hover:text-red-500 transition-colors active:scale-95" title="Удалить">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        </button>
                    </div>
                </div>
            </div>

            <div v-else class="py-20 text-center border-2 border-dashed border-white/5 rounded-[3rem]">
                <div class="text-4xl mb-4">🏷️</div>
                <div class="text-white/40 uppercase font-black italic tracking-widest text-sm">Тарифы не настроены</div>
                <div class="text-white/20 mt-2 text-xs">Добавьте первый пакет, чтобы REACTOR начал считать деньги</div>
            </div>
        </div>

        <Teleport to="body">
            <div v-if="showAddModal" class="fixed inset-0 flex items-center justify-center z-[9999900] p-6 font-mono">
                <div class="absolute inset-0 bg-black/95 backdrop-blur-2xl" @click="showAddModal = false"></div>
                <div class="relative w-full max-w-lg bg-[#0a0a0a] border border-[#22c55e]/30 rounded-[3.5rem] p-12 shadow-[0_0_120px_rgba(34,197,94,0.2)]">

                    <h2 class="text-[#22c55e] text-3xl font-black uppercase italic mb-8 tracking-tighter">Конструктор тарифа</h2>

                    <form @submit.prevent="submitTariff" class="space-y-5">

                        <div>
                            <label class="text-[10px] uppercase text-white/40 tracking-widest font-black italic mb-2 block">Название (например: Пакет 3 часа)</label>
                            <input v-model="form.name" type="text" class="w-full bg-black border-2 border-white/5 rounded-2xl p-4 text-white font-bold focus:border-[#22c55e] outline-none transition-colors" required />
                        </div>

                        <div class="grid grid-cols-2 gap-5">
                            <div>
                                <label class="text-[10px] uppercase text-white/40 tracking-widest font-black italic mb-2 block">Порог (часов)</label>
                                <input v-model="form.threshold_hours" type="number" min="1" step="1" class="no-spinners w-full bg-black border-2 border-white/5 rounded-2xl p-4 text-white font-bold focus:border-[#22c55e] outline-none transition-colors" required />
                            </div>
                            <div>
                                <label class="text-[10px] uppercase text-white/40 tracking-widest font-black italic mb-2 block">Цена за пакет (₽)</label>
                                <input v-model="form.price_per_package" type="number" min="0" step="1" class="no-spinners w-full bg-black border-2 border-white/5 rounded-2xl p-4 text-[#22c55e] font-black focus:border-[#22c55e] outline-none transition-colors" required />
                            </div>
                        </div>

                        <div>
                            <label class="text-[10px] uppercase text-white/40 tracking-widest font-black italic mb-2 block">Категория зала</label>

                            <select v-if="zones && zones.length > 0" v-model="form.category" class="w-full bg-black border-2 border-white/5 rounded-2xl p-4 text-white font-bold focus:border-[#22c55e] outline-none transition-colors appearance-none">
                                <option v-for="zone in zones" :key="zone.id" :value="zone.slug">
                                    {{ zone.name }}
                                </option>
                            </select>

                            <div v-else class="w-full bg-red-500/10 border-2 border-red-500/30 rounded-2xl p-4 text-red-500 text-xs font-black uppercase text-center">
                                ⚠️ Сначала создайте зоны в "Топологии залов"
                            </div>
                        </div>

                        <div class="flex gap-4 mt-10 pt-6 border-t border-white/5">
                            <button type="button" @click="showAddModal = false" class="w-1/3 py-5 border border-white/10 text-white/40 hover:text-white rounded-2xl font-black uppercase text-[10px] tracking-widest hover:bg-white/5 transition-all">Отмена</button>
                            <button type="submit" :disabled="form.processing || (!zones || zones.length === 0)" class="w-2/3 py-5 bg-[#22c55e] text-black font-black rounded-2xl uppercase text-[10px] tracking-widest hover:bg-[#1ea34d] transition-all disabled:opacity-50">
                                {{ form.processing ? 'Запись...' : 'Сохранить в ядро' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </AdminLayout>
</template>

<style scoped>
/* Убираем стрелочки у инпутов type="number" */
.no-spinners::-webkit-outer-spin-button,
.no-spinners::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}
.no-spinners {
    -moz-appearance: textfield;
}
</style>
