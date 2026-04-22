<script setup lang="ts">
import { ref, computed, onUnmounted } from 'vue'

import MainLayout from '@/Layouts/MainLayout.vue'
import TerminalLayout from '@/Layouts/TerminalLayout.vue'

import ClubMap from '@/Components/ClubMap.vue'
import ConfirmModal from '@/Components/ConfirmModal.vue'
import SmsModal from '@/Components/SmsModal.vue'
import PaymentModal from '@/Components/PaymentModal.vue'
import ZoneInfoModal from '@/Components/ZoneInfoModal.vue'
import TariffsModal from '@/Components/TariffsModal.vue'

const props = defineProps<{
    clubData: {
        id: number;
        name: string;
        map_config: string;
        viewbox: string;
    };
    computersList: any[];
    zonesList: any[];
    zoneRectsList: any[];
    isTerminal?: boolean;
}>()

const layout = computed(() => props.isTerminal ? TerminalLayout : MainLayout)

// --- ФИКС ОШИБКИ "wall is null" ---
// Чистим конфиг от битых данных (null) перед отрисовкой
const cleanMapConfig = computed(() => {
    try {
        let config = typeof props.clubData.map_config === 'string'
            ? JSON.parse(props.clubData.map_config)
            : props.clubData.map_config;

        if (!config) return { walls: [], zoneRects: [], labels: [] };

        // Очистка: убираем null и элементы без необходимых данных
        return {
            ...config,
            walls: Array.isArray(config.walls) ? config.walls.filter((w: any) => w && w.d) : [],
            zoneRects: Array.isArray(config.zoneRects) ? config.zoneRects.filter((z: any) => z && z.w) : [],
            labels: Array.isArray(config.labels) ? config.labels.filter((l: any) => l && l.content) : []
        };
    } catch (e) {
        console.error("REACTOR Map Parse Error:", e);
        return { walls: [], zoneRects: [], labels: [] };
    }
});

// === ЛОГИКА БРОНИРОВАНИЯ ===
const occupiedIds = computed(() =>
    props.computersList
        .filter(pc => pc.status !== 'available')
        .map(pc => pc.id.toString())
)

const selectedIds = ref<string[]>([])
const seatError = ref(false)
let errorTimer: ReturnType<typeof setTimeout> | null = null

const handleSeatError = () => {
    seatError.value = true
    if (errorTimer) clearTimeout(errorTimer)
    errorTimer = setTimeout(() => { seatError.value = false }, 1500)
}

// Состояния модалок
const showOverlay = ref(false)
const showConfirmModal = ref(false)
const showSmsModal = ref(false)
const showSuccessModal = ref(false)
const showInfoModal = ref(false)
const showTariffsModal = ref(false)
const selectedZoneForInfo = ref('PRO')
const userPhone = ref('')

const selectedDate = ref(new Date().toDateString())
const bookingMode = ref('hourly')
const selectedPackage = ref<any>(null)

// Определение данных ПК
const getComputerData = (id: string | number) => {
    const pc = props.computersList.find(c => c.id.toString() === id.toString());
    if (!pc) return { zoneName: 'STANDARD', pcName: id, price: 250 };

    let zoneName = 'STANDARD';
    const pcX = Number(pc.x);
    const pcY = Number(pc.y);

    // Берем зоны из нашего очищенного конфига
    const rects = cleanMapConfig.value.zoneRects || [];
    for (const z of rects) {
        if (pcX >= z.x && pcX <= z.x + z.w && pcY >= z.y && pcY <= z.y + z.h) {
            if (z.c === '#fbbf24') zoneName = 'VIP';
            if (z.c === '#ef4444') zoneName = 'BOOTCAMP';
            if (z.c === '#3b82f6') zoneName = 'PRO';
            if (z.c === '#a855f7') zoneName = 'STREAM';
            break;
        }
    }

    return {
        zoneName,
        pcName: pc.name,
        price: pc.price || (zoneName === 'PRO' ? 400 : 250)
    };
}

const selectedPlacesText = computed(() =>
    selectedIds.value.length === 0
        ? 'не выбрано'
        : selectedIds.value.map(id => {
            const data = getComputerData(id);
            return `${data.zoneName} №${data.pcName}`;
        }).join(', ')
)

// Логика времени
const timeSteps = Array.from({ length: 96 }, (_, i) => i * 0.25)
const formatTimeLabel = (h: number) => {
    const hours = Math.floor(h).toString().padStart(2, '0')
    const mins = Math.round((h % 1) * 60).toString().padStart(2, '0')
    return `${hours}:${mins}`
}
const getIndexByTime = (time: number) => {
    const val = Math.round(time * 4) / 4
    const idx = timeSteps.indexOf(val)
    return idx === -1 ? 0 : idx
}
const getNextQuarter = () => {
    const d = new Date(), h = d.getHours(), m = d.getMinutes()
    if (m < 15) return h + 0.25; if (m < 30) return h + 0.5; if (m < 45) return h + 0.75;
    return (h + 1) % 24
}
const mod24 = (n: number) => ((n % 24) + 24) % 24

const startH = ref(getNextQuarter())
const endH = ref(mod24(startH.value + 1))

const duration = computed(() => {
    if (bookingMode.value === 'packages' && selectedPackage.value) return selectedPackage.value.hours
    const d = mod24(endH.value - startH.value)
    return d === 0 ? 24 : d
})

const isNextDay = computed(() => (startH.value + duration.value) >= 24)
const startDateLabel = computed(() => new Date(selectedDate.value).toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' }).toUpperCase())

const totalAmount = computed(() => {
    const base = selectedIds.value.reduce((acc, id) => acc + getComputerData(id).price, 0)
    return base * duration.value * (selectedPackage.value ? selectedPackage.value.discount : 1)
})

const closeAllModals = () => {
    showConfirmModal.value = false; showSmsModal.value = false; showSuccessModal.value = false;
    showInfoModal.value = false; showTariffsModal.value = false; showOverlay.value = false;
}

const handleConfirmBooking = (payload: any) => {
    userPhone.value = payload.phone || payload
    showConfirmModal.value = false
    setTimeout(() => { showSmsModal.value = true }, 200)
}

const handleFinalClose = () => { closeAllModals(); selectedIds.value = [] }

const bookingDataForModal = computed(() => ({
    pcNumber: selectedPlacesText.value,
    date: startDateLabel.value,
    startTime: formatTimeLabel(startH.value),
    endTime: formatTimeLabel(endH.value),
    price: totalAmount.value.toFixed(0)
}))

const handleWheel = (e: any, type: 'start' | 'end') => {
    if (bookingMode.value === 'packages' && type === 'end') return
    const delta = (e.deltaY || e) > 0 ? 0.25 : -0.25
    if (type === 'start') {
        const next = mod24(startH.value + delta)
        if (selectedDate.value === new Date().toDateString() && next < getNextQuarter()) return
        startH.value = next
        if (bookingMode.value === 'packages' && selectedPackage.value) endH.value = mod24(next + selectedPackage.value.hours)
        else if (mod24(endH.value - next) < 1) endH.value = mod24(next + 1)
    } else {
        if (delta < 0 && (mod24(endH.value - startH.value) || 24) <= 1) return
        endH.value = mod24(endH.value + delta)
    }
}

const days = computed(() => {
    const today = new Date();
    return Array.from({ length: 14 }, (_, i) => {
        const d = new Date(); d.setDate(today.getDate() + i)
        return {
            full: d.toDateString(),
            dayNum: d.getDate(),
            dayName: d.toLocaleDateString('ru-RU', { weekday: 'short' }).toUpperCase()
        }
    })
})

const vFitText = {
    mounted(el: HTMLElement) { adjustFont(el) },
    updated(el: HTMLElement) { adjustFont(el) }
}
const adjustFont = (el: HTMLElement) => {
    el.style.fontSize = '24px'
    setTimeout(() => {
        let size = 24
        while ((el.scrollHeight > el.clientHeight || el.scrollWidth > el.clientWidth) && size > 10) {
            size--; el.style.fontSize = size + 'px'
        }
    }, 0)
}

onUnmounted(() => closeAllModals())
</script>

<template>
    <component :is="layout">
        <div class="booking-frame flex bg-black rounded-[40px] border border-[#22c55e]/30 p-2 mx-auto w-fit h-[880px] relative shadow-[0_0_50px_rgba(34,197,94,0.1)] overflow-hidden select-none">

            <section class="p-12 border-r border-[#22c55e]/30 flex items-center justify-center bg-[#080808] rounded-l-[38px] min-w-[960px] relative">
                <ClubMap
                    :selectedIds="selectedIds"
                    :occupiedIds="occupiedIds"
                    :computers="props.computersList"
                    :zones="props.zonesList"
                    :zoneRects="props.zoneRectsList"
                    :mapConfig="cleanMapConfig"
                    :viewbox="props.clubData.viewbox"
                    @show-info="(id) => { selectedZoneForInfo = id; showOverlay = true; showInfoModal = true }"
                    @seat-error="handleSeatError"
                    @toggle-seat="(id) => {
                        const i = selectedIds.indexOf(id);
                        i === -1 ? selectedIds.push(id) : selectedIds.splice(i, 1);
                    }"
                />
            </section>

            <aside class="w-[460px] p-8 flex flex-col bg-[#050505] rounded-r-[38px] h-full">

                <div class="mb-6 flex justify-between items-end px-2 shrink-0">
                    <h3 class="text-[#22c55e] text-xl font-black uppercase italic tracking-widest leading-none">
                        {{ props.clubData.name }}
                    </h3>
                    <div class="font-mono text-[10px] flex items-center gap-1" :class="seatError ? 'text-red-500 animate-pulse' : 'text-[#22c55e]'">
                        ● {{ seatError ? 'ОТКАЗ: ЗАНЯТО' : 'СИСТЕМА АКТИВНА' }}
                    </div>
                </div>

                <div class="flex-1 overflow-y-auto no-scrollbar pr-1">
                    <div class="mb-6 h-[135px] shrink-0 bg-white/[0.02] border border-white/5 p-5 rounded-2xl relative shadow-inner flex flex-col">
                        <div class="flex justify-between text-[9px] text-slate-500 font-black uppercase italic tracking-widest mb-3 shrink-0">
                            <span>Ваш выбор</span>
                            <button v-if="selectedIds.length" @click="selectedIds = []" class="text-red-500 cursor-pointer uppercase">сброс ✕</button>
                        </div>
                        <div class="flex-1 min-h-0 w-full overflow-hidden relative">
                            <div v-fit-text class="absolute inset-0 font-black italic font-mono transition-colors leading-tight break-words flex items-start"
                                 :class="selectedIds.length ? 'text-white uppercase' : 'text-white/20'">
                                {{ selectedPlacesText }}
                            </div>
                        </div>
                    </div>

                    <div class="mb-6 shrink-0">
                        <div class="flex gap-2 overflow-x-auto pb-3 no-scrollbar flex-nowrap scroll-smooth">
                            <div v-for="d in days" :key="d.full" @click="selectedDate = d.full"
                                 :class="['min-w-[48px] h-[56px] flex flex-col items-center justify-center rounded-xl border transition-all cursor-pointer',
                                  selectedDate === d.full ? 'bg-[#22c55e] border-[#22c55e]' : 'bg-white/5 border-white/10']">
                                <span :class="['text-[8px] font-black uppercase', selectedDate === d.full ? 'text-black/70' : 'text-slate-400']">{{ d.dayName }}</span>
                                <span :class="['text-[16px] font-mono font-black', selectedDate === d.full ? 'text-black' : 'text-white']">{{ d.dayNum }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="mb-6 shrink-0">
                        <div class="grid grid-cols-2 gap-2 p-1 bg-white/5 rounded-2xl border border-white/5 mb-3">
                            <button @click="bookingMode='hourly'; selectedPackage=null" :class="['py-3 rounded-xl text-[10px] font-black uppercase', bookingMode==='hourly' ? 'bg-[#22c55e] text-black shadow-lg' : 'text-white/40']">ПОЧАСОВОЙ</button>
                            <button @click="bookingMode='packages'" :class="['py-3 rounded-xl text-[10px] font-black uppercase', bookingMode==='packages' ? 'bg-[#22c55e] text-black shadow-lg' : 'text-white/40']">ПАКЕТЫ</button>
                        </div>
                    </div>

                    <div class="bg-black border border-white/10 rounded-[40px] p-6 mb-6 relative overflow-hidden min-h-[240px] flex flex-col justify-center shrink-0">
                        <div class="flex justify-between items-center relative z-20 h-[60px] px-2 mb-6">
                            <div class="flex-1 h-full wheel-container touch-none" @wheel.prevent="handleWheel($event, 'start')">
                                <div class="wheel-strip" :style="{ transform: `translateY(-${getIndexByTime(startH) * 60}px)` }">
                                    <div v-for="s in timeSteps" :key="'s'+s" class="time-cell">{{ formatTimeLabel(s) }}</div>
                                </div>
                            </div>
                            <div class="text-[#22c55e] font-black text-xl px-2 opacity-50">/</div>
                            <div class="flex-1 h-full wheel-container touch-none" @wheel.prevent="handleWheel($event, 'end')">
                                <div class="wheel-strip" :style="{ transform: `translateY(-${getIndexByTime(endH) * 60}px)` }">
                                    <div v-for="e in timeSteps" :key="'e'+e" class="time-cell">{{ formatTimeLabel(e) }}</div>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-center items-baseline gap-2 relative z-40 font-black text-[#22c55e]">
                            <span class="text-white/40 uppercase tracking-widest text-[10px] mr-2 italic">Длительность:</span>
                            <span class="text-5xl font-mono leading-none">{{ Math.floor(duration) }}</span><span class="text-xl">ч</span>
                        </div>
                    </div>
                </div>

                <div class="pt-4 shrink-0">
                    <button @click="selectedIds.length && (showOverlay=true, showConfirmModal=true)"
                            :class="['group w-full p-1 bg-[#22c55e] rounded-[2.5rem] transition-all active:scale-95', !selectedIds.length ? 'opacity-30 grayscale cursor-not-allowed' : 'cursor-pointer shadow-[0_10px_30px_rgba(34,197,94,0.2)]']">
                        <div class="bg-[#0a0a0a] rounded-[2.3rem] p-7 flex justify-between items-center border border-white/10 group-hover:bg-transparent transition-all">
                            <span class="font-black uppercase text-sm text-white group-hover:text-black italic tracking-widest">
                                {{ isTerminal ? 'ОПЛАТИТЬ И ИГРАТЬ' : 'Подтвердить' }}
                            </span>
                            <div class="flex flex-col items-end text-[#22c55e] group-hover:text-black leading-none font-black italic">
                                <div class="text-5xl tracking-tighter leading-none">{{ totalAmount.toFixed(0) }}</div>
                                <span class="text-[8px] uppercase mt-1">РУБ</span>
                            </div>
                        </div>
                    </button>
                </div>
            </aside>

            <Teleport to="body">
                <div v-if="showOverlay" class="fixed inset-0 bg-black/95 backdrop-blur-xl z-[9999990]" @click="closeAllModals"></div>

                <ConfirmModal
                    v-if="showConfirmModal"
                    :isOpen="showConfirmModal"
                    :mode="isTerminal ? 'auth' : 'booking'"
                    :data="bookingDataForModal"
                    @close="closeAllModals"
                    @confirm="handleConfirmBooking"
                />

                <SmsModal
                    v-if="showSmsModal"
                    :is-open="showSmsModal"
                    :phone="userPhone"
                    :is-terminal="isTerminal"
                    @close="showSmsModal = false"
                    @verify="() => { showSmsModal = false; showSuccessModal = true }"
                />

                <PaymentModal v-if="showSuccessModal" :isOpen="showSuccessModal" mode="booking" :data="bookingDataForModal" @close="handleFinalClose" />
                <ZoneInfoModal v-if="showInfoModal" :isOpen="showInfoModal" :zoneId="selectedZoneForInfo" @close="closeAllModals" />
                <TariffsModal v-if="showTariffsModal" :isOpen="showTariffsModal" @close="closeAllModals" />
            </Teleport>
        </div>
    </component>
</template>

<style scoped>
.no-scrollbar::-webkit-scrollbar { display: none; }
.wheel-container { overflow: hidden; height: 60px; position: relative; display: flex; justify-content: center; z-index: 20; }
.wheel-strip { display: flex; flex-direction: column; align-items: center; transition: transform 0.5s cubic-bezier(0.23, 1, 0.32, 1); will-change: transform; width: 100%; }
.time-cell { height: 60px; min-height: 60px; display: flex; align-items: center; justify-content: center; font-size: 2.8rem; font-weight: 900; color: #22c55e; font-family: ui-monospace, monospace; text-shadow: 0 0 10px rgba(34, 197, 94, 0.4); }
</style>
