<script setup lang="ts">
import { ref } from 'vue'
import axios from 'axios'
import AdminLayout from '@/Layouts/AdminLayout.vue' // <-- ИСПРАВЛЕН ИМПОРТ

const props = defineProps<{ stats: any }>()

const searchPhone = ref('')
const foundUser = ref<any>(null)

// Новые стейты для бонусных минут
const bonusMinutes = ref(60)
const bonusReason = ref('')
const isProcessing = ref(false)

const search = async () => {
    if (searchPhone.value.length < 4) return
    try {
        const { data } = await axios.get(`/admin/search-user?phone=${searchPhone.value}`)
        foundUser.value = data
    } catch (e) {
        foundUser.value = null
    }
}

// Новая функция выдачи компенсации
const handleBonus = async () => {
    if (!foundUser.value || isProcessing.value) return
    if (!bonusReason.value.trim()) return alert('ОШИБКА: Необходимо указать причину компенсации!')
    if (bonusMinutes.value <= 0) return alert('ОШИБКА: Укажите корректное время!')

    isProcessing.value = true

    try {
        // Отправляем запрос на выдачу минут
        await axios.post('/admin/give-bonus', {
            user_id: foundUser.value.id,
            minutes: bonusMinutes.value,
            reason: bonusReason.value
        })

        alert(`УСПЕХ: Пользователю начислено ${bonusMinutes.value} мин.`)

        // Сброс формы
        bonusMinutes.value = 60
        bonusReason.value = ''
        searchPhone.value = ''
        foundUser.value = null

    } catch (e: any) {
        alert(e.response?.data?.message || 'Ошибка начисления бонусного времени')
    } finally {
        isProcessing.value = false
    }
}
</script>

<template>
    <AdminLayout>
        <div class="max-w-6xl mx-auto space-y-8 animate-in fade-in duration-500">

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div v-for="(val, label) in stats" :key="label" class="bg-[#0f172a] border border-cyan-500/20 p-6 rounded-3xl shadow-lg">
                    <span class="text-[10px] text-cyan-500/50 uppercase font-black tracking-[0.3em]">{{ label }}</span>
                    <div class="text-4xl font-black text-white mt-2">{{ val }}</div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">

                <div class="bg-[#0a0a0a] border border-white/5 rounded-[40px] p-10 shadow-xl">
                    <h2 class="text-2xl font-black text-white uppercase italic mb-8 flex items-center gap-3">
                        <span class="w-2 h-8 bg-cyan-500"></span>
                        Компенсация времени
                    </h2>

                    <div class="space-y-6">
                        <div>
                            <label class="text-[10px] text-white/30 uppercase font-black mb-2 block">Поиск гостя (Телефон)</label>
                            <input v-model="searchPhone" @input="search" type="text" placeholder="+7 (___) ___ - __ - __"
                                   class="w-full bg-black border border-white/10 rounded-2xl p-5 text-white font-mono focus:border-cyan-500 outline-none transition-all placeholder:text-white/20" />
                        </div>

                        <div v-if="foundUser" class="bg-cyan-500/5 border border-cyan-500/20 rounded-3xl p-6 animate-in zoom-in duration-300">
                            <div class="flex justify-between items-start mb-6">
                                <div>
                                    <div class="text-xl font-black text-white uppercase italic">{{ foundUser.name }}</div>
                                    <div class="text-xs text-white/40 font-mono mt-1">{{ foundUser.phone }}</div>
                                </div>
                            </div>

                            <div class="space-y-4">
                                <div>
                                    <label class="text-[10px] text-cyan-500 uppercase font-black mb-1.5 block">Добавить (минут)</label>
                                    <input v-model.number="bonusMinutes" type="number"
                                           class="w-full bg-black border border-white/10 rounded-xl p-4 text-white font-black outline-none focus:border-cyan-500 transition-all" />
                                </div>

                                <div>
                                    <label class="text-[10px] text-red-400 uppercase font-black mb-1.5 block">Обоснование (Обязательно)</label>
                                    <input v-model="bonusReason" type="text" placeholder="Например: Зависла игра на ПК-5"
                                           class="w-full bg-black border border-red-500/30 rounded-xl p-4 text-white font-bold outline-none focus:border-red-500 transition-all placeholder:text-white/20 text-sm" />
                                </div>

                                <button @click="handleBonus" :disabled="isProcessing || !bonusReason.trim()"
                                        class="w-full mt-2 bg-cyan-500 hover:bg-cyan-400 text-black font-black py-4 rounded-xl uppercase text-xs tracking-widest transition-all disabled:opacity-50 disabled:grayscale">
                                    {{ isProcessing ? 'Обработка...' : 'Начислить бонус' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-[#0a0a0a] border border-white/5 rounded-[40px] p-10 relative overflow-hidden shadow-xl">
                    <h2 class="text-2xl font-black text-white uppercase italic mb-8">Состояние узлов</h2>
                    <div class="grid grid-cols-5 gap-3">
                        <div v-for="i in 20" :key="i"
                             class="aspect-square rounded-xl border border-white/5 flex flex-col items-center justify-center group cursor-help transition-all hover:border-cyan-500/50"
                             :class="i < 6 ? 'bg-cyan-500/10 border-cyan-500/30' : 'bg-white/5'">
                            <span class="text-[10px] font-black" :class="i < 6 ? 'text-cyan-500' : 'text-white/20'">{{ i }}</span>
                            <div v-if="i < 6" class="w-1 h-1 rounded-full bg-cyan-500 mt-1 animate-pulse"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>

<style scoped>
@reference "../../../css/app.css";
.animate-in { animation: fade-in 0.3s ease-out forwards; }
@keyframes fade-in { from { opacity: 0; transform: scale(0.98); } to { opacity: 1; transform: scale(1); } }
@keyframes zoom-in { from { opacity: 0; transform: scale(0.95) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }
</style>
