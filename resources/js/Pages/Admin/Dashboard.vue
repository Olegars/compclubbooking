<script setup lang="ts">
import { ref, onMounted, onUnmounted, watch } from 'vue'
import axios from 'axios'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const props = defineProps<{
    stats: {
        TOTAL_REVENUE: number | string,
        ACTIVE_SESSIONS: number,
        NEW_USERS_TODAY: number
    },
    computers: any[]
}>()

// --- СОСТОЯНИЕ УЗЛОВ (РЕАКТИВНОСТЬ) ---
const localComputers = ref([...props.computers])
const selectedPc = ref<any>(null)
const statusTimer = ref<any>(null)

// --- СОСТОЯНИЕ ПОИСКА И БОНУСОВ ---
const searchPhone = ref('')
const foundUser = ref<any>(null)
const bonusMinutes = ref(60)
const bonusReason = ref('')
const isProcessing = ref(false)

// --- ЛОГИКА ОБНОВЛЕНИЯ СТАТУСОВ ---
const refreshStatuses = async () => {
    try {
        const { data } = await axios.get('/admin/api/pc-statuses')
        // Мапим новые статусы на локальный массив
        localComputers.value.forEach(pc => {
            const updated = data.find((d: any) => d.id === pc.id)
            if (updated) pc.status = updated.status
        })
        console.log('📡 Синхронизация с ядром REACTOR завершена');
    } catch (e) {
        console.error('❌ Ошибка синхронизации статусов');
    }
}

// --- ЛОГИКА ПОИСКА И КОМПЕНСАЦИИ ---
const search = async () => {
    if (searchPhone.value.length < 4) {
        foundUser.value = null
        return
    }
    try {
        const { data } = await axios.get(`/admin/search-user?phone=${searchPhone.value}`)
        foundUser.value = data
    } catch (e) {
        foundUser.value = null
    }
}

const handleBonus = async () => {
    if (!foundUser.value || isProcessing.value) return
    if (!bonusReason.value.trim()) return alert('Укажите причину компенсации!')

    isProcessing.value = true
    try {
        await axios.post('/admin/give-bonus', {
            user_id: foundUser.value.id,
            minutes: bonusMinutes.value,
            reason: bonusReason.value
        })
        alert(`Начислено ${bonusMinutes.value} минут гостю ${foundUser.value.name}`)
        foundUser.value = null
        searchPhone.value = ''
        bonusReason.value = ''
    } catch (e) {
        alert('Ошибка при начислении бонуса')
    } finally {
        isProcessing.value = false
    }
}

// --- ЖИЗНЕННЫЙ ЦИКЛ ---
onMounted(() => {
    // Опрашиваем сервер каждые 10 секунд
    statusTimer.value = setInterval(refreshStatuses, 10000)
})

onUnmounted(() => {
    if (statusTimer.value) clearInterval(statusTimer.value)
})

// Хелпер для форматирования валюты
const formatMoney = (val: number | string) => {
    return Number(val).toLocaleString('ru-RU')
}
</script>

<template>
    <AdminLayout>
        <div class="max-w-7xl mx-auto space-y-8 animate-in fade-in duration-500 pb-10 font-mono">

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="bg-[#0a0a0a] border border-white/5 p-8 rounded-[2.5rem] shadow-xl relative overflow-hidden group">
                    <div class="absolute -right-4 -top-4 w-24 h-24 bg-cyan-500/10 blur-3xl rounded-full"></div>
                    <span class="text-[10px] text-cyan-500/50 uppercase font-black tracking-[0.3em] italic">Выручка (24h)</span>
                    <div class="text-5xl font-black text-white mt-2 tracking-tighter italic">
                        {{ formatMoney(stats.TOTAL_REVENUE) }}<span class="text-sm ml-2 text-white/20">₽</span>
                    </div>
                </div>

                <div class="bg-[#0a0a0a] border border-white/5 p-8 rounded-[2.5rem] shadow-xl relative overflow-hidden group">
                    <div class="absolute -right-4 -top-4 w-24 h-24 bg-[#22c55e]/10 blur-3xl rounded-full"></div>
                    <span class="text-[10px] text-[#22c55e]/50 uppercase font-black tracking-[0.3em] italic">Активные сессии</span>
                    <div class="text-5xl font-black text-white mt-2 tracking-tighter italic">
                        {{ stats.ACTIVE_SESSIONS }}<span class="text-sm ml-2 text-white/20">NODES</span>
                    </div>
                </div>

                <div class="bg-[#0a0a0a] border border-white/5 p-8 rounded-[2.5rem] shadow-xl relative overflow-hidden group">
                    <div class="absolute -right-4 -top-4 w-24 h-24 bg-purple-500/10 blur-3xl rounded-full"></div>
                    <span class="text-[10px] text-purple-500/50 uppercase font-black tracking-[0.3em] italic">Новые юзеры</span>
                    <div class="text-5xl font-black text-white mt-2 tracking-tighter italic">
                        +{{ stats.NEW_USERS_TODAY }}
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">

                <div class="lg:col-span-8 bg-[#0a0a0a] border border-white/5 rounded-[3rem] p-10 shadow-2xl">
                    <div class="flex justify-between items-center mb-10">
                        <h2 class="text-2xl font-black text-white uppercase italic flex items-center gap-4 tracking-tighter">
                            <span class="w-2 h-10 bg-cyan-500 rounded-full shadow-[0_0_15px_rgba(6,182,212,0.5)]"></span>
                            Состояние системы
                        </h2>
                        <div class="flex gap-6 text-[9px] font-black uppercase tracking-widest text-white/30 bg-white/5 px-6 py-2 rounded-full border border-white/5">
                            <span class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-white/10"></span> Свободен</span>
                            <span class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-cyan-500 animate-pulse"></span> В сети</span>
                        </div>
                    </div>

                    <div class="grid grid-cols-4 sm:grid-cols-6 md:grid-cols-8 gap-4">
                        <div v-for="pc in localComputers" :key="pc.id"
                             @click="selectedPc = pc"
                             class="aspect-square rounded-2xl border transition-all cursor-pointer flex flex-col items-center justify-center group relative overflow-hidden"
                             :class="[
                                 pc.status === 'busy' ? 'bg-cyan-500/10 border-cyan-500/40 shadow-[0_0_20px_rgba(6,182,212,0.1)]' : 'bg-white/[0.02] border-white/5 hover:border-white/20',
                                 selectedPc?.id === pc.id ? 'ring-2 ring-cyan-500 ring-offset-8 ring-offset-[#050505] scale-90' : 'hover:scale-105'
                             ]">
                            <span class="text-[11px] font-black transition-all"
                                  :class="pc.status === 'busy' ? 'text-cyan-400' : 'text-white/20 group-hover:text-white/60'">
                                {{ pc.name }}
                            </span>
                            <div v-if="pc.status === 'busy'" class="w-1.5 h-1.5 rounded-full bg-cyan-500 mt-2 animate-pulse shadow-[0_0_8px_rgba(6,182,212,1)]"></div>
                        </div>

                        <div v-if="!localComputers.length" class="col-span-full py-20 text-center">
                            <div class="text-white/10 text-3xl font-black uppercase italic tracking-[0.3em]">No Nodes Found</div>
                            <p class="text-[10px] text-white/20 uppercase mt-4">Настройте карту в редакторе</p>
                        </div>
                    </div>
                </div>

                <div class="lg:col-span-4 space-y-6">

                    <div v-if="selectedPc" class="bg-cyan-500/5 border border-cyan-500/20 rounded-[2.5rem] p-8 animate-in zoom-in duration-300 shadow-2xl">
                        <div class="flex justify-between items-start mb-8">
                            <div>
                                <div class="text-[9px] text-cyan-500 uppercase font-black mb-1 tracking-widest">Selected Node</div>
                                <div class="text-4xl font-black text-white italic uppercase tracking-tighter">{{ selectedPc.name }}</div>
                            </div>
                            <button @click="selectedPc = null" class="w-8 h-8 rounded-full bg-white/5 flex items-center justify-center hover:bg-white/10 transition-colors">✕</button>
                        </div>

                        <div class="space-y-3">
                            <button class="w-full py-4 bg-white/5 border border-white/10 text-white rounded-2xl font-black uppercase text-[10px] tracking-[0.2em] hover:bg-white/10 transition-all">
                                📱 Послать уведомление
                            </button>
                            <button class="w-full py-4 border border-red-500/30 text-red-500 hover:bg-red-500 hover:text-black rounded-2xl font-black uppercase text-[10px] tracking-[0.2em] transition-all">
                                ⚡ Перезагрузить узел
                            </button>
                        </div>
                    </div>

                    <div class="bg-[#0a0a0a] border border-white/5 rounded-[2.5rem] p-8 shadow-2xl">
                        <h3 class="text-lg font-black text-white uppercase italic mb-8 flex items-center gap-3">
                            <span class="w-1.5 h-6 bg-[#22c55e] rounded-full"></span>
                            Компенсация времени
                        </h3>

                        <div class="space-y-4">
                            <div>
                                <label class="text-[9px] text-white/30 uppercase font-black mb-2 block ml-2">Поиск по телефону</label>
                                <input v-model="searchPhone" @input="search" type="text" placeholder="+7 (___) ___ - __ - __"
                                       class="w-full bg-black border border-white/10 rounded-2xl p-5 text-white font-mono focus:border-cyan-500 outline-none transition-all text-sm" />
                            </div>

                            <div v-if="foundUser" class="space-y-6 animate-in slide-in-from-top-4 duration-300">
                                <div class="p-5 bg-[#22c55e]/5 rounded-2xl border border-[#22c55e]/20">
                                    <div class="text-white font-black uppercase text-sm tracking-tight">{{ foundUser.name }}</div>
                                    <div class="text-[10px] text-[#22c55e] font-black mt-1 uppercase tracking-widest">Баланс: {{ foundUser.wallet?.balance }} ₽</div>
                                </div>

                                <div class="space-y-4 pt-4 border-t border-white/5">
                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <label class="text-[9px] text-white/30 uppercase font-black mb-2 block ml-2">Минуты</label>
                                            <input v-model.number="bonusMinutes" type="number" class="w-full bg-black border border-white/10 rounded-xl p-4 text-white font-black outline-none focus:border-cyan-500 text-center" />
                                        </div>
                                        <div class="flex items-end">
                                            <div class="flex gap-1 w-full">
                                                <button v-for="t in [30, 60, 120]" :key="t" @click="bonusMinutes = t"
                                                        class="flex-1 bg-white/5 text-[9px] font-black py-4 rounded-xl hover:bg-white/10 border border-white/5">
                                                    {{ t }}
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="text-[9px] text-white/30 uppercase font-black mb-2 block ml-2">Причина (обязательно)</label>
                                        <input v-model="bonusReason" type="text" placeholder="Тех. сбой / Лаги..." class="w-full bg-black border border-white/10 rounded-2xl p-4 text-white text-xs outline-none focus:border-cyan-500" />
                                    </div>

                                    <button @click="handleBonus" :disabled="isProcessing || !bonusReason"
                                            class="w-full bg-[#22c55e] hover:bg-[#1ea34d] text-black font-black py-5 rounded-2xl uppercase text-[11px] tracking-[0.2em] disabled:opacity-30 transition-all shadow-[0_10px_20px_rgba(34,197,94,0.2)] active:scale-95">
                                        {{ isProcessing ? 'ПЕРЕДАЧА...' : 'НАЧИСЛИТЬ БОНУС' }}
                                    </button>
                                </div>
                            </div>

                            <div v-else-if="searchPhone.length > 5" class="text-center py-10">
                                <div class="text-white/10 uppercase font-black italic tracking-widest text-xs">User Terminal Not Found</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>

<style scoped>
@reference "../../../css/app.css";
.animate-in { animation: fade-in 0.4s ease-out forwards; }
@keyframes fade-in { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
@keyframes zoom-in { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }

/* Кастомный скроллбар для правой панели */
.custom-scrollbar::-webkit-scrollbar { width: 4px; }
.custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 10px; }
</style>
