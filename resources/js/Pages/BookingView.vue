<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { router } from '@inertiajs/vue3'

import MainLayout from '../Layouts/MainLayout.vue'
import TerminalLayout from '../Layouts/TerminalLayout.vue'

import ClubMap from './ClubMap.vue'
import ConfirmModal from '../Components/ConfirmModal.vue'
import SmsModal from '../Components/SmsModal.vue'
import PaymentModal from '../Components/PaymentModal.vue'
import ZoneInfoModal from './ZoneInfoModal.vue'
import TariffsModal from './TariffsModal.vue'

const props = defineProps<{
    clubData?: {
        id: number;
        name: string;
        map_config: any;
        viewbox: string;
    };
    computersList?: any[];
    zonesList?: any[];
    zoneRectsList?: any[];
    isTerminal?: boolean;
}>()

const layout = computed(() => props.isTerminal ? TerminalLayout : MainLayout)

// === ЛОГИКА ТАЙМЕРА ТЕРМИНАЛА ===
const idleTimeout = ref<ReturnType<typeof setTimeout> | null>(null);
const IDLE_TIME = 60000;

const resetIdleTimer = () => {
    if (!props.isTerminal) return;
    if (idleTimeout.value) clearTimeout(idleTimeout.value);

    idleTimeout.value = setTimeout(() => {
        closeAllModals(); // Зачищаем экраны при бездействии
        router.visit('/terminal');
    }, IDLE_TIME);
};

onMounted(() => {
    if (props.isTerminal) {
        window.addEventListener('mousemove', resetIdleTimer);
        window.addEventListener('touchstart', resetIdleTimer);
        window.addEventListener('keydown', resetIdleTimer);
        window.addEventListener('click', resetIdleTimer);
        resetIdleTimer();
    }
});

onUnmounted(() => {
    // КРИТИЧЕСКИ ВАЖНО: Убиваем оверлей при уходе со страницы в ЛК
    closeAllModals();

    if (props.isTerminal) {
        window.removeEventListener('mousemove', resetIdleTimer);
        window.removeEventListener('touchstart', resetIdleTimer);
        window.removeEventListener('keydown', resetIdleTimer);
        window.removeEventListener('click', resetIdleTimer);
        if (idleTimeout.value) clearTimeout(idleTimeout.value);
    }
});
// =========================================================

const selectedIds = ref<string[]>([])
const occupiedIds = ref<string[]>([])
const isChecking = ref(false)
const seatError = ref(false)
let checkDebounce: ReturnType<typeof setTimeout> | null = null
let errorTimer: ReturnType<typeof setTimeout> | null = null
const touchStartY = ref(0)

const checkAvailability = () => {
    isChecking.value = true
    setTimeout(() => {
        const mockData: string[] = []
        const count = Math.floor(Math.random() * 8) + 4
        while (mockData.length < count) {
            const id = (Math.floor(Math.random() * 42) + 1).toString()
            if (!mockData.includes(id)) mockData.push(id)
        }
        occupiedIds.value = mockData
        selectedIds.value = selectedIds.value.filter(id => !occupiedIds.value.includes(id))
        isChecking.value = false
    }, 400)
}

const handleSeatError = () => {
    seatError.value = true
    if (errorTimer) clearTimeout(errorTimer)
    errorTimer = setTimeout(() => { seatError.value = false }, 1500)
}

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

const getComputerData = (id: string | number) => {
    const pc = props.computersList?.find(c => c.id.toString() === id.toString());
    const pcName = pc?.name || id;

    let zoneName = pc?.zone_name || pc?.group_name || pc?.zone || null;

    if (!zoneName && pc && props.clubData?.map_config) {
        let config = props.clubData.map_config;
        if (typeof config === 'string') {
            try { config = JSON.parse(config); } catch (e) {}
        }

        const zones = config?.zoneRects || [];
        const pcX = Number(pc.x);
        const pcY = Number(pc.y);

        const centerX = pcX + 3;
        const centerY = pcY + 2.25;

        for (const z of zones) {
            if (centerX >= z.x && centerX <= z.x + z.w &&
                centerY >= z.y && centerY <= z.y + z.h) {
                if (z.c !== '#4d4d4d' && z.type) {
                    zoneName = z.type;
                }
                break;
            }
        }
    }

    if (!zoneName) {
        const n = Number(pcName);
        if (!isNaN(n)) {
            if (n <= 5) zoneName = 'PRO';
            else if (n <= 20) zoneName = 'BOOTCAMP';
            else if ([23,24,25,38,39,40].includes(n)) zoneName = 'TRIO';
            else if ([21,22,26,27,34,35,36,37,41,42].includes(n)) zoneName = 'DUO';
            else zoneName = 'STANDARD';
        } else {
            zoneName = 'STANDARD';
        }
    }

    const displayZoneName = String(zoneName).toUpperCase()
        .replace('DOU', 'DUO')
        .replace('STANDART', 'STANDARD');

    return { zoneName: displayZoneName, pcName, price: pc?.price };
}

const selectedPlacesText = computed(() =>
    selectedIds.value.length === 0
        ? 'не выбрано'
        : selectedIds.value.map(id => {
            const data = getComputerData(id);
            return `${data.zoneName} №${data.pcName}`;
        }).join(', ')
)

const vFitText = {
    mounted(el: HTMLElement) { adjustFont(el) },
    updated(el: HTMLElement) { adjustFont(el) }
}

const adjustFont = (el: HTMLElement) => {
    el.style.fontSize = '24px'
    setTimeout(() => {
        let size = 24
        while ((el.scrollHeight > el.clientHeight || el.scrollWidth > el.clientWidth) && size > 10) {
            size--
            el.style.fontSize = size + 'px'
        }
    }, 0)
}

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
    if (m < 15) return h + 0.25;
    if (m < 30) return h + 0.5;
    if (m < 45) return h + 0.75
    return (h + 1) % 24
}
const mod24 = (n: number) => ((n % 24) + 24) % 24

const startH = ref(getNextQuarter())
const endH = ref(mod24(startH.value + 1))

watch([startH, endH, selectedDate], () => {
    if (checkDebounce) clearTimeout(checkDebounce)
    checkDebounce = setTimeout(checkAvailability, 300)
}, { immediate: true })

const duration = computed(() => {
    if (bookingMode.value === 'packages' && selectedPackage.value) return selectedPackage.value.hours
    const d = mod24(endH.value - startH.value)
    return d === 0 ? 24 : d
})

const isNextDay = computed(() => (startH.value + duration.value) >= 24)

const startDateLabel = computed(() =>
    new Date(selectedDate.value).toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' }).replace('.', '').toUpperCase()
)

const endDateLabel = computed(() => {
    const d = new Date(selectedDate.value)
    if (isNextDay.value) d.setDate(d.getDate() + 1)
    return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' }).replace('.', '').toUpperCase()
})

const totalAmount = computed(() => {
    const b = selectedIds.value.reduce((acc, id) => {
        const data = getComputerData(id);
        if (data.price) return acc + Number(data.price);

        let r = 250
        const z = data.zoneName;

        if (z === 'PROFI' || z === 'PRO') r = 400;
        else if (z === 'BOOTCAMP') r = 300;
        else if (z === 'SINGLE') r = 200;
        else if (z === 'DUO') r = 180;
        else if (z === 'TRIO') r = 150;
        else if (z === 'STANDARD') r = 150;

        return acc + r
    }, 0)
    return b * duration.value * (selectedPackage.value ? selectedPackage.value.discount : 1)
})

const closeAllModals = () => {
    showConfirmModal.value = false;
    showSmsModal.value = false;
    showSuccessModal.value = false;
    showInfoModal.value = false;
    showTariffsModal.value = false;
    showOverlay.value = false;
}

const handleConfirmBooking = (payload: any) => {
    // Поддержка и старого модального окна (объект), и нового (строка)
    userPhone.value = payload.phone || payload;

    showConfirmModal.value = false;
    setTimeout(() => { showSmsModal.value = true }, 200);
}

const handleSmsVerify = () => {
    showSmsModal.value = false;
    setTimeout(() => { showSuccessModal.value = true }, 200);
}

const handleFinalClose = () => {
    closeAllModals()
    selectedIds.value = []
}

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
        if (bookingMode.value === 'packages' && selectedPackage.value) {
            endH.value = mod24(next + selectedPackage.value.hours)
        }
        else if (mod24(endH.value - next) < 1) {
            endH.value = mod24(next + 1)
        }
    } else {
        if (delta < 0 && (mod24(endH.value - startH.value) || 24) <= 1) return
        endH.value = mod24(endH.value + delta)
    }
}

const handleTouchStart = (e: TouchEvent) => {
    if (e.touches && e.touches.length > 0) {
        touchStartY.value = e.touches[0]!.pageY
    }
}

const handleTouchMove = (e: TouchEvent, type: 'start' | 'end') => {
    if (isChecking.value || !e.touches || e.touches.length === 0) return
    const currentY = e.touches[0]!.pageY
    const delta = touchStartY.value - currentY
    if (Math.abs(delta) > 10) {
        handleWheel(delta, type)
        touchStartY.value = currentY
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
</script>

<template>
    <component :is="layout">
        <div class="booking-frame flex bg-black rounded-[40px] border border-[#22c55e]/30 p-2 mx-auto w-fit h-[880px] relative shadow-[0_0_50px_rgba(34,197,94,0.1)] overflow-hidden select-none">

            <section class="p-12 border-r border-[#22c55e]/30 flex items-center justify-center bg-[#080808] rounded-l-[38px] min-w-[960px] relative">
                <ClubMap
                    :selectedIds="selectedIds"
                    :occupiedIds="occupiedIds"
                    :computers="props.computersList || []"
                    :zones="props.zonesList || []"
                    :zoneRects="props.zoneRectsList || []"
                    :mapConfig="props.clubData?.map_config"
                    :viewbox="props.clubData?.viewbox"
                    @show-info="(id) => { selectedZoneForInfo = id; showOverlay = true; showInfoModal = true }"
                    @seat-error="handleSeatError"
                    @toggle-seat="(id) => {
                        const i = selectedIds.indexOf(id);
                        i === -1 ? selectedIds.push(id) : selectedIds.splice(i, 1);
                        selectedIds.sort((a,b) => Number(a)-Number(b));
                    }"
                />
            </section>

            <aside class="w-[460px] p-8 flex flex-col bg-[#050505] rounded-r-[38px] h-full">

                <div class="mb-6 flex justify-between items-end px-2 shrink-0">
                    <h3 class="text-[#22c55e] text-xl font-black uppercase italic tracking-widest leading-none">
                        {{ props.clubData?.name || 'Бронирование' }}
                    </h3>
                    <div class="font-mono text-[10px] flex items-center gap-1" :class="seatError ? 'text-red-500 animate-pulse' : (isChecking ? 'text-yellow-400 animate-pulse' : 'text-[#22c55e]')">
                        ● {{ seatError ? 'ОТКАЗ: ЗАНЯТО' : (isChecking ? 'ПРОВЕРКА...' : 'ГОТОВО') }}
                    </div>
                </div>

                <div class="flex-1 overflow-y-auto no-scrollbar pr-1">

                    <div class="mb-6 h-[135px] shrink-0 bg-white/[0.02] border border-white/5 p-5 rounded-2xl relative shadow-inner flex flex-col">
                        <div class="flex justify-between text-[9px] text-slate-500 font-black uppercase italic tracking-widest mb-3 shrink-0">
                            <span>вы выбрали место</span>
                            <button v-if="selectedIds.length" @click="selectedIds = []" class="text-red-500 cursor-pointer hover:text-red-400 transition-colors uppercase">сброс ✕</button>
                        </div>
                        <div class="flex-1 min-h-0 w-full overflow-hidden relative">
                            <div v-fit-text class="absolute inset-0 font-black italic font-mono transition-colors leading-tight break-words flex items-start"
                                 :class="selectedIds.length ? 'text-white uppercase' : 'text-white/20'">
                                {{ selectedPlacesText }}
                            </div>
                        </div>
                    </div>

                    <div class="mb-6 shrink-0">
                        <div class="flex gap-2 overflow-x-auto pb-3 no-scrollbar flex-nowrap scroll-smooth pr-4">
                            <div v-for="d in days" :key="d.full" @click="selectedDate = d.full"
                                 :class="['min-w-[48px] h-[56px] flex flex-col items-center justify-center rounded-xl border transition-all cursor-pointer shadow-md flex-shrink-0',
                                  selectedDate === d.full ? 'bg-[#22c55e] border-[#22c55e]' : 'bg-white/5 border-white/10']">
                                <span :class="['text-[8px] font-black leading-none mb-1 uppercase', selectedDate === d.full ? 'text-black/70' : 'text-slate-400']">{{ d.dayName }}</span>
                                <span :class="['text-[16px] font-mono font-black leading-none', selectedDate === d.full ? 'text-black' : 'text-white']">{{ d.dayNum }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="mb-6 shrink-0">
                        <div class="flex justify-between items-center mb-2 font-black uppercase italic tracking-widest">
                            <span class="text-slate-500 text-[10px]">Режим оплаты</span>
                            <button @click="showOverlay=true; showTariffsModal=true" class="cursor-pointer text-xs text-[#22c55e] hover:text-white transition-colors flex items-center gap-1.5 group">
                                Тарифы <span class="border border-[#22c55e] group-hover:border-white rounded-full w-4 h-4 flex items-center justify-center text-[10px] leading-none">i</span>
                            </button>
                        </div>
                        <div class="grid grid-cols-2 gap-2 p-1 bg-white/5 rounded-2xl border border-white/5 mb-3">
                            <button @click="bookingMode='hourly'; selectedPackage=null" :class="['py-3 rounded-xl text-[10px] font-black cursor-pointer uppercase', bookingMode==='hourly' ? 'bg-[#22c55e] text-black shadow-lg' : 'text-white/40']">ПОЧАСОВОЙ</button>
                            <button @click="bookingMode='packages'" :class="['py-3 rounded-xl text-[10px] font-black cursor-pointer uppercase', bookingMode==='packages' ? 'bg-[#22c55e] text-black shadow-lg' : 'text-white/40']">ПАКЕТЫ</button>
                        </div>
                        <div v-if="bookingMode==='packages'" class="flex gap-2">
                            <button v-for="p in [{id:1,name:'3ч',h:3,d:0.9},{id:2,name:'5ч',h:5,d:0.8},{id:3,name:'12ч',h:12,d:0.6}]" :key="p.id" @click="selectedPackage=p; endH=mod24(startH+p.h)" :class="['flex-1 py-3 border rounded-xl text-[9px] font-black cursor-pointer', selectedPackage?.id===p.id ? 'border-[#22c55e] text-[#22c55e] bg-[#22c55e]/10' : 'border-white/10 text-white/40']">{{ p.name }}</button>
                        </div>
                    </div>

                    <div class="bg-black border border-white/10 rounded-[40px] p-6 mb-6 relative overflow-hidden min-h-[240px] flex flex-col justify-center shrink-0">
                        <div class="absolute inset-x-0 top-0 h-16 bg-gradient-to-b from-black via-black/80 to-transparent z-30 pointer-events-none"></div>
                        <div class="absolute inset-x-0 bottom-14 h-16 bg-gradient-to-t from-black via-black/80 to-transparent z-30 pointer-events-none"></div>

                        <div class="flex justify-between w-full px-2 mb-4 relative z-40 font-black text-[11px] uppercase tracking-widest text-center">
                            <div class="flex-1 bg-[#111] text-white py-2 rounded-full border border-white/20 shadow-md">{{ startDateLabel }}</div>
                            <div class="w-8 flex items-center justify-center text-[#22c55e] animate-pulse">»</div>
                            <div class="flex-1 bg-[#111] py-2 rounded-full border" :class="isNextDay ? 'text-[#22c55e] border-[#22c55e]/50 shadow-[0_0_10px_#22c55e33]' : 'text-white border-white/20'">{{ endDateLabel }}</div>
                        </div>

                        <div class="flex justify-between items-center relative z-20 h-[60px] px-2 mb-6">
                            <div class="flex-1 h-full wheel-container touch-none" @wheel.prevent="!isChecking && handleWheel($event, 'start')" @touchstart="handleTouchStart" @touchmove="handleTouchMove($event, 'start')">
                                <div class="wheel-strip" :style="{ transform: `translateY(-${getIndexByTime(startH) * 60}px)` }"><div v-for="s in timeSteps" :key="'s'+s" class="time-cell">{{ formatTimeLabel(s) }}</div></div>
                            </div>
                            <div class="text-[#22c55e] font-black text-xl px-2 opacity-50">/</div>
                            <div class="flex-1 h-full wheel-container touch-none" @wheel.prevent="!isChecking && handleWheel($event, 'end')" @touchstart="handleTouchStart" @touchmove="handleTouchMove($event, 'end')">
                                <div class="wheel-strip" :style="{ transform: `translateY(-${getIndexByTime(endH) * 60}px)` }"><div v-for="e in timeSteps" :key="'e'+e" class="time-cell">{{ formatTimeLabel(e) }}</div></div>
                            </div>
                        </div>

                        <div class="flex justify-center items-baseline gap-2 relative z-40 font-black text-[#22c55e] drop-shadow-md">
                            <span class="text-white/40 uppercase tracking-widest text-[10px] mr-2 italic font-black">Длительность:</span>
                            <span class="text-5xl font-mono leading-none">{{ Math.floor(duration) }}</span><span class="text-xl mr-2">ч</span>
                            <template v-if="Math.round((duration%1)*60)>0">
                                <span class="text-5xl font-mono leading-none">{{ Math.round((duration%1)*60) }}</span><span class="text-xl">м</span>
                            </template>
                        </div>
                    </div>
                </div>

                <div class="pt-4 shrink-0">
                    <button @click="!isChecking && selectedIds.length && (showOverlay=true, showConfirmModal=true)"
                            :class="['group w-full p-1 bg-[#22c55e] rounded-[2.5rem] transition-all active:scale-95 shrink-0', (!selectedIds.length || isChecking) ? 'opacity-30 grayscale cursor-not-allowed' : 'cursor-pointer shadow-[0_10px_30px_rgba(34,197,94,0.2)]']">
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
                <ConfirmModal v-if="showConfirmModal" :isOpen="showConfirmModal" :mode="isTerminal ? 'auth' : 'booking'" :data="bookingDataForModal" @close="closeAllModals" @confirm="handleConfirmBooking" />
                <SmsModal
                    :is-open="showSmsModal"
                    :phone="userPhone"
                    :is-terminal="isTerminal"
                    @close="showSmsModal = false"
                    @verify="handleSmsVerify"
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
