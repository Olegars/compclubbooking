<script setup lang="ts">
import { ref, onMounted, onUnmounted, computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import axios from 'axios'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const props = defineProps<{
    stats: { TOTAL_REVENUE: number | string, ACTIVE_SESSIONS: number, NEW_USERS_TODAY: number },
    computers: any[]
}>()

const localComputers = ref([...props.computers])
const selectedPc = ref<any>(null)
const statusTimer = ref<any>(null)
const activeCalls = ref<any[]>([])

const refreshStatuses = async () => {
    try {
        const { data: pcData } = await axios.get('/admin/api/pc-statuses')
        localComputers.value.forEach(pc => {
            const updated = pcData.find((d: any) => d.id === pc.id)
            if (updated) pc.status = updated.status
        })

        const { data: callData } = await axios.get('/admin/api/active-calls')
        if (callData.length > activeCalls.value.length) {
            new Audio('/sounds/notification.mp3').play().catch(() => {})
        }
        activeCalls.value = callData
    } catch (e) { console.error('📡 REACTOR Link Error') }
}

const resolveCall = async (callId: number) => {
    try {
        await axios.post(`/admin/api/calls/${callId}/resolve`)
        activeCalls.value = activeCalls.value.filter(c => c.id !== callId)
    } catch (e) { alert('Ошибка закрытия сигнала') }
}

// Поиск и бонусы
const searchPhone = ref('')
const foundUser = ref<any>(null)
const bonusMinutes = ref(60)
const bonusReason = ref('')
const topUpAmount = ref(500)
const isProcessing = ref(false)

const search = async () => {
    if (searchPhone.value.length < 4) { foundUser.value = null; return }
    try {
        const { data } = await axios.get(`/admin/search-user?phone=${searchPhone.value}`)
        foundUser.value = data
    } catch (e) { foundUser.value = null }
}

const handleBonus = async () => {
    if (!foundUser.value || isProcessing.value) return
    if (!bonusReason.value.trim()) return alert('Укажите причину!')
    isProcessing.value = true
    try {
        await axios.post('/admin/give-bonus', {
            user_id: foundUser.value.id,
            minutes: bonusMinutes.value,
            reason: bonusReason.value
        })
        foundUser.value = null
        searchPhone.value = ''; bonusReason.value = ''
    } catch (e) { alert('Ошибка начисления') }
    finally { isProcessing.value = false }
}

const handleTopUp = async () => {
    if (!foundUser.value || isProcessing.value) return
    if (topUpAmount.value < 100) return alert('Минимум 100 ₽')
    isProcessing.value = true
    try {
        const { data } = await axios.post('/admin/topup', {
            user_id: foundUser.value.id,
            amount: topUpAmount.value,
            reason: bonusReason.value || 'Кассовое пополнение',
        })
        foundUser.value = {
            ...foundUser.value,
            balance: data.new_balance ?? data.balance,
            total_balance: data.new_balance ?? data.balance,
        }
        alert(`Баланс пополнен: ${data.new_balance ?? data.balance} ₽`)
    } catch (e: any) {
        alert(e.response?.data?.message || 'Ошибка пополнения')
    } finally {
        isProcessing.value = false
    }
}

onMounted(() => {
    refreshStatuses()
    statusTimer.value = setInterval(refreshStatuses, 5000)
})

onUnmounted(() => { if (statusTimer.value) clearInterval(statusTimer.value) })
const formatMoney = (val: number | string) => Number(val).toLocaleString('ru-RU')
</script>

<template>
    <AdminLayout>
        <div class="max-w-7xl mx-auto space-y-8 animate-in fade-in duration-500 pb-10 font-mono text-white p-6">

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="bg-[#0a0a0a] border border-white/5 p-8 rounded-[2.5rem] shadow-xl relative overflow-hidden">
                    <div class="absolute right-0 top-0 p-4 opacity-5"><span class="text-6xl italic">₽</span></div>
                    <span class="text-[10px] text-cyan-500/50 uppercase font-black tracking-[0.3em] italic">Выручка (24h)</span>
                    <div class="text-5xl font-black mt-2 tracking-tighter italic">{{ formatMoney(stats.TOTAL_REVENUE) }}<span class="text-sm ml-2 text-white/20">₽</span></div>
                </div>
                <div class="bg-[#0a0a0a] border border-white/5 p-8 rounded-[2.5rem] shadow-xl">
                    <span class="text-[10px] text-[#22c55e]/50 uppercase font-black tracking-[0.3em] italic">Active Nodes</span>
                    <div class="text-5xl font-black mt-2 tracking-tighter italic">{{ stats.ACTIVE_SESSIONS }}</div>
                </div>
                <div class="bg-[#0a0a0a] border border-white/5 p-8 rounded-[2.5rem] shadow-xl">
                    <span class="text-[10px] text-purple-500/50 uppercase font-black tracking-[0.3em] italic">New Stalkers</span>
                    <div class="text-5xl font-black mt-2 tracking-tighter italic">+{{ stats.NEW_USERS_TODAY }}</div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
                <div class="lg:col-span-8 bg-[#0a0a0a] border border-white/5 rounded-[3rem] p-10 shadow-2xl">
                    <h2 class="text-2xl font-black uppercase italic mb-10 flex items-center gap-4 tracking-tighter">
                        <span class="w-2 h-10 bg-cyan-500 rounded-full shadow-[0_0_15px_rgba(6,182,212,0.5)]"></span>
                        Мониторинг залов
                    </h2>
                    <div class="grid grid-cols-4 sm:grid-cols-6 md:grid-cols-8 gap-4">
                        <div v-for="pc in localComputers" :key="pc.id" @click="selectedPc = pc"
                             class="aspect-square rounded-2xl border transition-all cursor-pointer flex flex-col items-center justify-center group relative overflow-hidden"
                             :class="[ pc.status === 'busy' ? 'bg-cyan-500/10 border-cyan-500/40' : 'bg-white/[0.02] border-white/5',
                                       selectedPc?.id === pc.id ? 'ring-2 ring-cyan-500 ring-offset-4 ring-offset-[#050505] scale-90' : 'hover:scale-105' ]">
                            <div v-if="activeCalls.some(c => c.pc_id === pc.id)" class="absolute top-1 right-1 w-4 h-4 bg-red-500 rounded-full animate-ping"></div>
                            <span class="text-[11px] font-black" :class="pc.status === 'busy' ? 'text-cyan-400' : 'text-white/20'">{{ pc.name }}</span>
                        </div>
                    </div>
                </div>

                <div class="lg:col-span-4 space-y-6">
                    <div class="bg-[#0a0a0a] border border-cyan-500/10 rounded-[2.5rem] p-8 shadow-2xl relative overflow-hidden group">
                        <h3 class="text-lg font-black text-cyan-500 uppercase italic mb-6 flex items-center gap-3">
                            <span class="w-1.5 h-6 bg-cyan-500 rounded-full"></span> Настройка цен
                        </h3>
                        <Link href="/admin/tariffs" class="w-full flex items-center justify-between p-4 bg-white/[0.02] border border-white/5 rounded-2xl hover:border-[#22c55e]/50 hover:bg-[#22c55e]/5 transition-all group/link">
                            <div class="flex items-center gap-4">
                                <span class="text-lg">🏷️</span>
                                <span class="text-xs font-black uppercase tracking-widest text-white/70 group-hover/link:text-white">Тарифы и пакеты</span>
                            </div>
                            <svg class="w-5 h-5 text-white/10 group-hover/link:text-[#22c55e]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M9 5l7 7-7 7"/></svg>
                        </Link>
                    </div>

                    <div class="bg-[#0a0a0a] border border-red-500/20 rounded-[2.5rem] p-8 shadow-2xl">
                        <h3 class="text-lg font-black text-red-500 uppercase italic mb-6 flex items-center gap-3">
                            <span class="w-1.5 h-6 bg-red-500 rounded-full animate-pulse"></span> Вызовы
                        </h3>
                        <div v-if="activeCalls.length > 0" class="space-y-4 max-h-[300px] overflow-y-auto custom-scrollbar">
                            <div v-for="call in activeCalls" :key="call.id" class="bg-red-500/5 border border-red-500/20 p-4 rounded-2xl">
                                <div class="flex justify-between items-start mb-2">
                                    <span class="text-[10px] font-black text-red-500 uppercase italic tracking-widest">{{ call.pc_name }}</span>
                                    <span class="text-[8px] text-white/20 font-mono">{{ call.time }}</span>
                                </div>
                                <p class="text-xs text-white/80 italic mb-4">"{{ call.message }}"</p>
                                <button @click="resolveCall(call.id)" class="w-full py-2 bg-red-500/10 hover:bg-red-500 text-red-500 hover:text-black rounded-xl text-[9px] font-black uppercase transition-all">Принято</button>
                            </div>
                        </div>
                        <div v-else class="py-10 text-center border border-dashed border-white/5 rounded-2xl italic text-[10px] text-white/10 uppercase tracking-widest">Сигналов нет</div>
                    </div>

                    <div class="bg-[#0a0a0a] border border-white/5 rounded-[2.5rem] p-8 shadow-2xl">
                        <h3 class="text-lg font-black text-white uppercase italic mb-8 flex items-center gap-3">
                            <span class="w-1.5 h-6 bg-[#22c55e] rounded-full"></span> Гость / Касса
                        </h3>
                        <div class="space-y-4">
                            <input v-model="searchPhone" @input="search" type="text" placeholder="Поиск по телефону..." class="w-full bg-black border border-white/10 rounded-2xl p-5 text-white focus:border-[#22c55e] outline-none text-sm transition-all" />
                            <div v-if="foundUser" class="space-y-4 animate-in slide-in-from-top-4">
                                <div class="p-4 bg-[#22c55e]/5 rounded-xl border border-[#22c55e]/20 text-sm font-black flex justify-between gap-3">
                                    <span>{{ foundUser.name }}</span>
                                    <span class="text-[#22c55e] font-mono">{{ Math.floor(foundUser.balance ?? foundUser.total_balance ?? 0) }} ₽</span>
                                </div>
                                <input v-model.number="topUpAmount" type="number" min="100" placeholder="Сумма пополнения" class="w-full bg-black border border-white/10 rounded-xl p-4 text-xs" />
                                <input v-model="bonusReason" type="text" placeholder="Причина / комментарий..." class="w-full bg-black border border-white/10 rounded-xl p-4 text-xs" />
                                <button @click="handleTopUp" class="w-full bg-[#22c55e] text-black font-black py-4 rounded-xl uppercase text-[10px]">Пополнить баланс</button>
                                <button @click="handleBonus" class="w-full bg-white/5 border border-white/10 text-white font-black py-4 rounded-xl uppercase text-[10px]">Выдать бонус (мин)</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar { width: 4px; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.05); border-radius: 10px; }
</style>
