<script setup lang="ts">
import { ref, onMounted, onUnmounted, computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import axios from 'axios'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useToast } from '@/Composables/useToast'
import { useAdminAlerts } from '@/Composables/useAdminAlerts'

const props = defineProps<{
    stats: { TOTAL_REVENUE: number | string, ACTIVE_SESSIONS: number, NEW_USERS_TODAY: number },
    computers: any[],
    fanOrphans?: any[],
}>()

const page = usePage()
const adminRole = computed(() => (page.props.admin_user as any)?.role || null)
const isSupervisorPlus = computed(() => adminRole.value === 'supervisor' || adminRole.value === 'owner')

const { success, error, warning } = useToast()
const { setCounts } = useAdminAlerts()

const localComputers = ref([...props.computers])
const fanOrphans = ref<any[]>([...(props.fanOrphans || [])])
const selectedPc = ref<any>(null)
const statusTimer = ref<any>(null)
const activeCalls = ref<any[]>([])
const sosAlerts = ref<any[]>([])
const inputAlerts = ref<any[]>([])
const forceOffBusy = ref<number | null>(null)

const orphanFans = computed(() => fanOrphans.value.filter((f: any) => f.fan_orphan_on))

const spaceNameHint = (spaceId: number) => {
    const pcs = localComputers.value.filter((p: any) => Number(p.space_id) === Number(spaceId))
    if (!pcs.length) return `Space #${spaceId}`
    return `Space #${spaceId} · ${pcs.map((p: any) => p.name).slice(0, 3).join(', ')}`
}

const powerTileClass = (pc: any) => {
    if (pc.status === 'maintenance' || pc.maintenance) return 'bg-orange-500/15 border-orange-500/40'
    if (pc.cache_ok === false && (pc.power_state === 'on' || pc.status === 'busy'))
        return 'bg-fuchsia-500/15 border-fuchsia-500/40'
    if (pc.status === 'busy') return 'bg-cyan-500/10 border-cyan-500/40'
    const state = pc.power_state || 'off'
    if (state === 'on') return 'bg-emerald-500/15 border-emerald-500/40'
    if (state === 'booting') return 'bg-amber-400/15 border-amber-400/50 animate-pulse'
    if (state === 'error') return 'bg-red-500/15 border-red-500/50'
    return 'bg-white/[0.02] border-white/5'
}

const powerLabelClass = (pc: any) => {
    if (pc.status === 'maintenance' || pc.maintenance) return 'text-orange-400'
    if (pc.cache_ok === false && (pc.power_state === 'on' || pc.status === 'busy'))
        return 'text-fuchsia-300'
    if (pc.status === 'busy') return 'text-cyan-400'
    const state = pc.power_state || 'off'
    if (state === 'on') return 'text-emerald-400'
    if (state === 'booting') return 'text-amber-300'
    if (state === 'error') return 'text-red-400'
    return 'text-white/25'
}

const powerLabel = (pc: any) => {
    if (pc.status === 'maintenance' || pc.maintenance) return 'сервис'
    if (pc.cache_ok === false && (pc.power_state === 'on' || pc.status === 'busy'))
        return 'кэш'
    if (pc.status === 'busy') return 'сессия'
    const state = pc.power_state || 'off'
    if (state === 'on') return 'онлайн'
    if (state === 'booting') return 'загрузка'
    if (state === 'error') return 'ошибка'
    return 'выкл'
}

// Первый опрос только наполняет панели: сирена звучит лишь на новых сигналах
const isFirstPoll = ref(true)
const seenCallIds = ref<Set<number>>(new Set())
const seenSosIds = ref<Set<number>>(new Set())

const playSiren = () => {
    new Audio('/sounds/notification.mp3').play().catch(() => {})
}

const pickNew = (list: any[], seen: Set<number>) => list.filter(item => !seen.has(item.id))

const refreshStatuses = async () => {
    try {
        const { data: pcData } = await axios.get('/admin/api/pc-statuses')
        const list = Array.isArray(pcData) ? pcData : (pcData?.computers || [])
        fanOrphans.value = Array.isArray(pcData?.fan_orphans) ? pcData.fan_orphans : fanOrphans.value

        localComputers.value = localComputers.value.map(pc => {
            const updated = list.find((d: any) => Number(d.id) === Number(pc.id))
            if (!updated) return pc
            return {
                ...pc,
                status: updated.status,
                power_desired: updated.power_desired,
                power_state: updated.power_state,
                last_seen_at: updated.last_seen_at,
                space_id: updated.space_id ?? pc.space_id,
                cache_ok: updated.cache_ok,
                cache_free_gb: updated.cache_free_gb,
                data_root: updated.data_root,
                maintenance: updated.maintenance,
            }
        })

        const [{ data: callData }, { data: alertData }] = await Promise.all([
            axios.get('/admin/api/active-calls'),
            axios.get('/admin/api/sos-alerts'),
        ])

        const calls = callData || []
        const sos = alertData?.sos || []

        const newCalls = pickNew(calls, seenCallIds.value)
        const newSos = pickNew(sos, seenSosIds.value)

        seenCallIds.value = new Set(calls.map((c: any) => c.id))
        seenSosIds.value = new Set(sos.map((a: any) => a.id))

        activeCalls.value = calls
        sosAlerts.value = sos
        inputAlerts.value = alertData?.input || []
        setCounts(alertData?.counts)

        if (!isFirstPoll.value) {
            if (newSos.length) {
                playSiren()
                newSos.forEach((a: any) => warning(`SOS ${a.pc_name}: ${a.reason}`, 12000))
            } else if (newCalls.length) {
                playSiren()
            }
        }
        isFirstPoll.value = false
    } catch (e) { console.error('📡 REACTOR Link Error') }
}

const forceOffFan = async (fanId: number) => {
    if (forceOffBusy.value) return
    forceOffBusy.value = fanId
    try {
        const { data } = await axios.post(`/admin/api/fans/${fanId}/force-off`)
        success(data?.message || 'Команда выключения отправлена')
        await refreshStatuses()
    } catch (e: any) {
        error(e?.response?.data?.message || 'Не удалось выключить вентилятор')
    } finally {
        forceOffBusy.value = null
    }
}

const resolveCall = async (callId: number) => {
    try {
        await axios.post(`/admin/api/calls/${callId}/resolve`)
        activeCalls.value = activeCalls.value.filter(c => c.id !== callId)
    } catch (e) { error('Ошибка закрытия сигнала') }
}

const ackSos = async (alertId: number) => {
    try {
        const { data } = await axios.post(`/admin/api/sos-alerts/${alertId}/ack`)
        sosAlerts.value = sosAlerts.value.filter(a => a.id !== alertId)
        setCounts(data?.counts)
        success('SOS принят в работу')
    } catch (e) { error('Не удалось закрыть SOS-вызов') }
}

const ackInputAlert = async (alertId: number) => {
    try {
        const { data } = await axios.post(`/admin/api/input-alerts/${alertId}/ack`)
        inputAlerts.value = inputAlerts.value.filter(a => a.id !== alertId)
        setCounts(data?.counts)
        success('Сигнал периферии закрыт')
    } catch (e) { error('Не удалось закрыть сигнал') }
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
    if (!bonusReason.value.trim()) return warning('Укажите причину!')
    isProcessing.value = true
    try {
        await axios.post('/admin/give-bonus', {
            user_id: foundUser.value.id,
            minutes: bonusMinutes.value,
            reason: bonusReason.value
        })
        success(`Начислено ${bonusMinutes.value} мин бонусом`)
        foundUser.value = null
        searchPhone.value = ''; bonusReason.value = ''
    } catch (e) { error('Ошибка начисления') }
    finally { isProcessing.value = false }
}

const handleTopUp = async () => {
    if (!foundUser.value || isProcessing.value) return
    if (topUpAmount.value < 100) return warning('Минимум 100 ₽')
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
        success(`Баланс пополнен: ${data.new_balance ?? data.balance} ₽`)
    } catch (e: any) {
        error(e.response?.data?.message || 'Ошибка пополнения')
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

            <!-- SOS С ТЕРМИНАЛОВ: ЛИПКАЯ КРАСНАЯ ПАНЕЛЬ -->
            <div v-if="sosAlerts.length > 0" class="sticky top-0 z-40 -mx-6 -mt-6 px-6 pt-6 pb-4 bg-[#050505]/95 backdrop-blur-md">
                <div class="bg-red-600/[0.07] border-2 border-red-600/50 rounded-[1rem] p-6 shadow-[0_0_60px_rgba(220,38,38,0.2)]">
                    <div class="flex items-center justify-between gap-4 mb-5">
                        <div class="flex items-center gap-4">
                            <span class="w-3 h-3 bg-red-600 rounded-full animate-ping"></span>
                            <h2 class="text-xl font-black uppercase italic tracking-tighter text-red-500">
                                SOS с терминалов
                            </h2>
                        </div>
                        <span class="px-4 py-1.5 bg-red-600 text-black rounded-full text-[10px] font-black uppercase tracking-widest">
                            {{ sosAlerts.length }} активных
                        </span>
                    </div>

                    <div class="space-y-3 max-h-[320px] overflow-y-auto custom-scrollbar">
                        <div v-for="alert in sosAlerts" :key="alert.id"
                             class="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-black/40 border border-red-600/30 rounded-2xl p-5">
                            <div class="flex items-center gap-5 min-w-0">
                                <div class="shrink-0 px-4 py-3 bg-red-600 text-black rounded-xl text-lg font-black italic tracking-tighter">
                                    {{ alert.pc_name }}
                                </div>
                                <div class="min-w-0">
                                    <div class="text-sm font-black uppercase italic text-white truncate">{{ alert.reason }}</div>
                                    <div class="text-[9px] text-white/30 uppercase font-black tracking-widest mt-1">
                                        {{ alert.time }} · ожидает {{ alert.waiting_minutes }} мин
                                    </div>
                                </div>
                            </div>
                            <button @click="ackSos(alert.id)"
                                    class="shrink-0 px-8 py-4 bg-red-600 hover:bg-red-500 text-black font-black uppercase text-[10px] tracking-widest rounded-xl transition-all active:scale-95 shadow-[0_0_25px_rgba(220,38,38,0.3)]">
                                Принято
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="bg-[#0a0a0a] border border-white/5 p-8 rounded-[1rem] shadow-xl relative overflow-hidden">
                    <div class="absolute right-0 top-0 p-4 opacity-5"><span class="text-6xl italic">₽</span></div>
                    <span class="text-[10px] text-cyan-500/50 uppercase font-black tracking-[0.3em] italic">Выручка (24h)</span>
                    <div class="text-5xl font-black mt-2 tracking-tighter italic">{{ formatMoney(stats.TOTAL_REVENUE) }}<span class="text-sm ml-2 text-white/20">₽</span></div>
                </div>
                <div class="bg-[#0a0a0a] border border-white/5 p-8 rounded-[1rem] shadow-xl">
                    <span class="text-[10px] text-[#22c55e]/50 uppercase font-black tracking-[0.3em] italic">Active Nodes</span>
                    <div class="text-5xl font-black mt-2 tracking-tighter italic">{{ stats.ACTIVE_SESSIONS }}</div>
                </div>
                <div class="bg-[#0a0a0a] border border-white/5 p-8 rounded-[1rem] shadow-xl">
                    <span class="text-[10px] text-purple-500/50 uppercase font-black tracking-[0.3em] italic">New Stalkers</span>
                    <div class="text-5xl font-black mt-2 tracking-tighter italic">+{{ stats.NEW_USERS_TODAY }}</div>
                </div>
            </div>

            <div v-if="orphanFans.length" class="bg-amber-500/[0.07] border border-amber-500/40 rounded-[1rem] p-6 shadow-[0_0_40px_rgba(245,158,11,0.12)]">
                <div class="flex items-center gap-3 mb-4">
                    <span class="text-amber-400 text-xl">⚠</span>
                    <h2 class="text-lg font-black uppercase italic tracking-tighter text-amber-400">
                        Вентилятор mid/max при выкл. ПК
                    </h2>
                </div>
                <div class="space-y-3">
                    <div v-for="fan in orphanFans" :key="fan.fan_id"
                         class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-black/40 border border-amber-500/20 rounded-2xl p-5">
                        <div>
                            <div class="text-sm font-black uppercase italic text-white">{{ spaceNameHint(fan.space_id) }}</div>
                            <div class="text-[9px] text-white/30 uppercase font-black tracking-widest mt-1">
                                fan #{{ fan.fan_id }} · mode {{ fan.manual_mode }} · applied {{ fan.applied_power }}
                            </div>
                        </div>
                        <button
                            @click="forceOffFan(fan.fan_id)"
                            :disabled="forceOffBusy === fan.fan_id"
                            class="shrink-0 px-6 py-3 bg-amber-500 hover:bg-amber-400 disabled:opacity-40 text-black font-black uppercase text-[10px] tracking-widest rounded-xl transition-all">
                            Выключить до дежурного (120В)
                        </button>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
                <div class="lg:col-span-8 bg-[#0a0a0a] border border-white/5 rounded-[1.125rem] p-10 shadow-2xl">
                    <h2 class="text-2xl font-black uppercase italic mb-10 flex items-center gap-4 tracking-tighter">
                        <span class="w-2 h-10 bg-cyan-500 rounded-full shadow-[0_0_15px_rgba(6,182,212,0.5)]"></span>
                        Мониторинг залов
                    </h2>
                    <div class="flex flex-wrap gap-4 mb-6 text-[9px] font-black uppercase tracking-widest text-white/40">
                        <span class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span> В сети</span>
                        <span class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-full bg-white/25"></span> Выкл</span>
                        <span class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-full bg-amber-400"></span> Загрузка</span>
                        <span class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-full bg-red-500"></span> Ошибка WOL</span>
                        <span class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-full bg-cyan-400"></span> Сессия</span>
                        <span class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-full bg-orange-400"></span> Обслуживание</span>
                        <span class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-full bg-fuchsia-400"></span> Кэш SSD мёртв</span>
                    </div>
                    <div class="grid grid-cols-4 sm:grid-cols-6 md:grid-cols-8 gap-4">
                        <div v-for="pc in localComputers" :key="pc.id" @click="selectedPc = pc"
                             class="aspect-square rounded-2xl border transition-all cursor-pointer flex flex-col items-center justify-center group relative overflow-hidden"
                             :class="[ powerTileClass(pc),
                                       selectedPc?.id === pc.id ? 'ring-2 ring-cyan-500 ring-offset-4 ring-offset-[#050505] scale-90' : 'hover:scale-105' ]">
                            <div v-if="activeCalls.some(c => c.pc_id === pc.id) || sosAlerts.some(a => a.computer_id === pc.id)"
                                 class="absolute top-1 right-1 w-4 h-4 bg-red-500 rounded-full animate-ping"></div>
                            <div v-if="pc.status === 'busy'"
                                 class="absolute top-1 left-1 w-2 h-2 rounded-full bg-cyan-400 shadow-[0_0_8px_rgba(34,211,238,0.8)]"></div>
                            <span class="text-[11px] font-black" :class="powerLabelClass(pc)">{{ pc.name }}</span>
                            <span class="text-[8px] font-black uppercase tracking-wider mt-1 opacity-60" :class="powerLabelClass(pc)">
                                {{ powerLabel(pc) }}
                            </span>
                        </div>
                    </div>
                </div>

                <div class="lg:col-span-4 space-y-6">
                    <div v-if="isSupervisorPlus" class="bg-[#0a0a0a] border border-cyan-500/10 rounded-[1rem] p-8 shadow-2xl relative overflow-hidden group">
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

                    <div class="bg-[#0a0a0a] border border-red-500/20 rounded-[1rem] p-8 shadow-2xl">
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

                    <div v-if="inputAlerts.length > 0" class="bg-[#0a0a0a] border border-amber-500/20 rounded-[1rem] p-8 shadow-2xl">
                        <h3 class="text-lg font-black text-amber-500 uppercase italic mb-6 flex items-center gap-3">
                            <span class="w-1.5 h-6 bg-amber-500 rounded-full"></span> Периферия
                        </h3>
                        <div class="space-y-4 max-h-[300px] overflow-y-auto custom-scrollbar">
                            <div v-for="alert in inputAlerts" :key="alert.id" class="bg-amber-500/5 border border-amber-500/20 p-4 rounded-2xl">
                                <div class="flex justify-between items-start mb-2">
                                    <span class="text-[10px] font-black text-amber-500 uppercase italic tracking-widest">{{ alert.pc_name }}</span>
                                    <span class="text-[8px] text-white/20 font-mono">{{ alert.time }}</span>
                                </div>
                                <p class="text-xs text-white/80 italic">{{ alert.type_label }}</p>
                                <p class="text-[9px] text-white/25 uppercase tracking-wider font-bold mt-1 mb-4">{{ alert.details }}</p>
                                <button @click="ackInputAlert(alert.id)" class="w-full py-2 bg-amber-500/10 hover:bg-amber-500 text-amber-500 hover:text-black rounded-xl text-[9px] font-black uppercase transition-all">Принято</button>
                            </div>
                        </div>
                    </div>

                    <div class="bg-[#0a0a0a] border border-white/5 rounded-[1rem] p-8 shadow-2xl">
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
