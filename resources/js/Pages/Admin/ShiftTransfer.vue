<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { Head, router, usePage } from '@inertiajs/vue3'
import axios from 'axios'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useAdminBarcodeScanner } from '@/Composables/useAdminBarcodeScanner'
import { useToast } from '@/Composables/useToast'

type ProductRow = {
    id: number
    name: string
    category: string | null
    barcode: string | null
    stock: number
    actual: number | null
    counted: boolean
    requires_marking: boolean
    cost_price: number
    price: number
}

const props = defineProps<{
    phase: 'presence' | 'counting'
    shift_id: number | null
    status: string | null
    outgoing_name: string | null
    incoming_name: string | null
    expected_cash: number
    products: ProductRow[]
    required_total: number
    counted_required: number
    all_required_counted: boolean
    can_complete: boolean
    discrepancies: ProductRow[]
    hardware: HardwareAudit | null
}>()

type HardwareBadge = { level: 'critical' | 'warning' | 'ok'; code: string; label: string }
type HardwareStation = {
    pc_id: number
    pc_name: string
    status: 'pending' | 'ok' | 'warning' | 'critical'
    responded: boolean
    booted?: boolean
    session_active?: boolean
    badges?: HardwareBadge[]
    missing_devices?: string[]
}
type HardwareAudit = {
    status: string | null
    polled: number
    total: number
    seconds_left: number
    timeout_seconds: number
    counts: { critical: number; warning: number; ok: number; pending: number }
    stations: HardwareStation[]
}

const page = usePage()
const { success, error, info } = useToast()
const { enableReceiveMode, disableReceiveMode } = useAdminBarcodeScanner()

const phase = ref(props.phase)
const products = ref<ProductRow[]>(props.products)
const requiredTotal = ref(props.required_total)
const countedRequired = ref(props.counted_required)
const allRequiredCounted = ref(props.all_required_counted)
const canComplete = ref(props.can_complete)
const outgoingName = ref(props.outgoing_name)
const cashCounted = ref<number | null>(props.expected_cash)
const busy = ref(false)
const cameraError = ref('')
const cameraReady = ref(false)
const looking = ref(false)
const faceDetected = ref(false)
const dialog = ref<ProductRow | null>(null)
const qtyInput = ref<number>(0)
const qtyField = ref<HTMLInputElement | null>(null)
const videoEl = ref<HTMLVideoElement | null>(null)
const hardware = ref<HardwareAudit | null>(props.hardware)
let mediaStream: MediaStream | null = null
let detectTimer: number | null = null
let hardwareTimer: number | null = null

const formError = computed(() => {
    const errs = (page.props as any)?.errors
    return errs?.message || errs?.cash_counted || null
})

const countedItems = computed(() => products.value.filter(p => p.counted))
const remaining = computed(() => products.value.filter(p => !p.counted && Number(p.stock) > 0))
const extraCounted = computed(() => products.value.filter(p => p.counted && Number(p.stock) === 0))
const mismatchIds = computed(() => new Set(
    products.value.filter(p => p.counted && Number(p.actual) !== Number(p.stock)).map(p => p.id)
))

const applyPayload = (data: any) => {
    if (!data) return
    phase.value = data.phase || phase.value
    products.value = data.products || products.value
    requiredTotal.value = data.required_total ?? requiredTotal.value
    countedRequired.value = data.counted_required ?? countedRequired.value
    allRequiredCounted.value = Boolean(data.all_required_counted)
    canComplete.value = Boolean(data.can_complete)
    outgoingName.value = data.outgoing_name ?? outgoingName.value
    if (data.hardware) hardware.value = data.hardware
}

const stopCamera = () => {
    if (detectTimer) {
        window.clearInterval(detectTimer)
        detectTimer = null
    }
    mediaStream?.getTracks().forEach(track => track.stop())
    mediaStream = null
    if (videoEl.value) videoEl.value.srcObject = null
}

const beginTransfer = async (detected: boolean) => {
    if (busy.value || phase.value === 'counting') return
    busy.value = true
    looking.value = false
    try {
        const { data } = await axios.post('/admin/api/shifts/begin', {
            verified: true,
            camera: 'reception',
            face_detected: detected,
        })
        stopCamera()
        applyPayload(data)
        success('Присутствие подтверждено. Можно считать холодильники.')
    } catch (e: any) {
        error(e?.response?.data?.message || 'Не удалось начать передачу смены')
        looking.value = true
    } finally {
        busy.value = false
    }
}

const detectFaces = async () => {
    const video = videoEl.value
    if (!video || video.readyState < 2 || phase.value !== 'presence' || busy.value) return
    const Detector = (window as any).FaceDetector
    if (!Detector) return
    try {
        const detector = new Detector({ fastMode: true, maxDetectedFaces: 1 })
        const faces = await detector.detect(video)
        if (faces?.length) {
            faceDetected.value = true
            await beginTransfer(true)
        }
    } catch {
        /* FaceDetector недоступен или кадр ещё не готов */
    }
}

const startCamera = async () => {
    cameraError.value = ''
    cameraReady.value = false
    looking.value = true
    try {
        mediaStream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 720 } },
            audio: false,
        })
        await nextTick()
        if (videoEl.value) {
            videoEl.value.srcObject = mediaStream
            await videoEl.value.play()
        }
        cameraReady.value = true
        detectTimer = window.setInterval(detectFaces, 400)
    } catch {
        cameraError.value = 'Камера ресепшена недоступна. Подойдите к стойке и разрешите доступ к камере.'
        looking.value = false
    }
}

const openQty = (product: ProductRow) => {
    dialog.value = product
    qtyInput.value = product.counted ? Number(product.actual) : 0
    nextTick(() => qtyField.value?.focus())
}

const closeQty = () => {
    dialog.value = null
}

const saveQty = async () => {
    if (!dialog.value || busy.value) return
    const qty = Math.max(0, Math.floor(Number(qtyInput.value) || 0))
    busy.value = true
    try {
        const { data } = await axios.post('/admin/api/shifts/count', {
            product_id: dialog.value.id,
            qty,
        })
        applyPayload(data)
        closeQty()
    } catch (e: any) {
        error(e?.response?.data?.message || 'Не удалось сохранить количество')
    } finally {
        busy.value = false
    }
}

const handleScan = async (code: string) => {
    if (dialog.value || busy.value || phase.value !== 'counting') return
    busy.value = true
    try {
        const { data } = await axios.post('/admin/api/shifts/scan', { code })
        if (data?.product) {
            openQty(data.product)
            info(data.product.counted ? 'Уже считали — можно поправить количество' : data.product.name)
        }
    } catch (e: any) {
        error(e?.response?.data?.message || 'Скан не принят')
    } finally {
        busy.value = false
    }
}

const pollHardware = async () => {
    if (phase.value !== 'counting') return
    try {
        const { data } = await axios.get('/admin/api/shifts/transfer/hardware-status')
        if (data && typeof data.total === 'number') hardware.value = data
    } catch {
        /* следующий опрос через 3 с */
    }
}

const stopHardwarePoll = () => {
    if (hardwareTimer) {
        window.clearInterval(hardwareTimer)
        hardwareTimer = null
    }
}

const stationTone = (station: HardwareStation) => {
    if (station.status === 'critical') return 'border-red-500/50 bg-red-500/10 text-red-300'
    if (station.status === 'warning') return 'border-amber-400/40 bg-amber-500/10 text-amber-200'
    if (station.status === 'ok') return 'border-[#22c55e]/30 bg-[#22c55e]/10 text-[#22c55e]'
    return 'border-white/10 bg-black/40 text-white/50'
}

const stationCaption = (station: HardwareStation) => {
    if (station.session_active) return 'Сессия гостя, без перезагрузки'
    if (!station.responded && station.booted) return 'На связи, самотест'
    if (!station.responded) return 'Ждём включение'
    if (station.badges && station.badges.length) return station.badges.map(b => b.label).join(' · ')
    return 'Норма'
}

const submitShift = () => {
    if (!canComplete.value) {
        alert('Сначала отсканируйте все товары с остатком.')
        return
    }
    if (typeof cashCounted.value !== 'number' || cashCounted.value < 0) {
        alert('Укажите сумму наличных в кассе')
        return
    }
    const mismatches = products.value.filter(p => p.counted && Number(p.actual) !== Number(p.stock))
    const critical = hardware.value?.counts.critical ?? 0
    const pending = hardware.value?.counts.pending ?? 0
    const parts = []
    if (mismatches.length) parts.push(`расхождения по складу: ${mismatches.length}`)
    if (critical) parts.push(`критичных ПК: ${critical}`)
    if (pending) parts.push(`ещё не ответили: ${pending}`)
    const msg = parts.length
        ? `${parts.join(', ')}. Недостача и пропажа периферии уйдут уходящему. Принять смену?`
        : 'Принять смену и стать активным админом?'
    if (!confirm(msg)) return
    router.post('/admin/api/shifts/complete', { cash_counted: cashCounted.value })
}

watch(phase, (next) => {
    if (next === 'counting') {
        stopCamera()
        enableReceiveMode(handleScan)
        void pollHardware()
        if (!hardwareTimer) hardwareTimer = window.setInterval(pollHardware, 3000)
    } else {
        stopHardwarePoll()
    }
}, { immediate: true })

onMounted(() => {
    if (phase.value === 'presence') startCamera()
})

onUnmounted(() => {
    stopCamera()
    stopHardwarePoll()
    disableReceiveMode()
})
</script>

<template>
    <Head title="Приём смены" />
    <AdminLayout>
        <div class="max-w-5xl mx-auto space-y-6 font-mono pb-24 px-4">
            <div class="bg-[#0a0a0a] border border-white/5 p-8 rounded-[1rem]">
                <div class="text-[10px] uppercase tracking-[0.3em] font-black text-white/30">Приём смены</div>
                <h1 class="text-3xl font-black text-white uppercase italic tracking-tighter mt-2">
                    {{ phase === 'presence' ? 'Камера ресепшена' : 'Скан холодильников' }}
                </h1>
                <p class="text-white/40 text-sm font-bold mt-3">
                    <template v-if="phase === 'presence'">
                        Посмотрите в камеру. Когда система увидит, что вы на месте, начнётся передача смены.
                    </template>
                    <template v-else>
                        Сканируйте товар и смотрите аудит зала. Неответившие ПК к моменту приёма станут критическими.
                        <span v-if="outgoingName"> Сдаёт: {{ outgoingName }}.</span>
                    </template>
                </p>
            </div>

            <div v-if="formError" class="bg-red-500/15 border border-red-500/40 text-red-300 px-6 py-4 rounded-2xl text-sm font-bold">
                {{ formError }}
            </div>

            <div v-if="phase === 'presence'" class="bg-black border border-white/10 rounded-[1.25rem] overflow-hidden">
                <div class="relative aspect-video bg-[#050505]">
                    <video ref="videoEl" class="w-full h-full object-cover" autoplay muted playsinline />
                    <div class="pointer-events-none absolute inset-8 border-2 border-cyan-400/40 rounded-[2rem]"></div>
                    <div class="absolute left-6 top-6 text-[10px] uppercase tracking-widest font-black"
                         :class="faceDetected ? 'text-[#22c55e]' : 'text-white/40'">
                        {{ busy ? 'Проверяем…' : (faceDetected ? 'Лицо найдено' : 'Смотрите в камеру') }}
                    </div>
                </div>
                <div class="p-6 flex flex-col sm:flex-row sm:items-center gap-4">
                    <p class="flex-1 text-sm text-white/50 font-bold">
                        {{ cameraError || (cameraReady ? 'Держитесь в кадре у стойки.' : 'Подключаем камеру…') }}
                    </p>
                    <button type="button" :disabled="busy || !cameraReady" @click="beginTransfer(faceDetected)"
                            class="px-6 py-3 bg-[#22c55e] text-black rounded-xl text-xs font-black uppercase tracking-widest disabled:opacity-40">
                        Я на месте
                    </button>
                </div>
            </div>

            <template v-else>
                <div v-if="hardware" class="bg-[#0a0a0a] border border-white/5 rounded-[1rem] p-6 space-y-4">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="text-[10px] uppercase tracking-widest font-black text-white/30">Аудит зала</div>
                            <div class="text-2xl font-black text-white mt-1">Опрошено {{ hardware.polled }} / {{ hardware.total }}</div>
                        </div>
                        <div class="text-right">
                            <div class="text-[10px] uppercase tracking-widest font-black text-white/30">Холодная загрузка</div>
                            <div class="text-2xl font-black text-white mt-1">{{ hardware.seconds_left }} с</div>
                        </div>
                    </div>
                    <div class="h-2 rounded-full bg-white/5 overflow-hidden">
                        <div class="h-full bg-[#22c55e] transition-all"
                             :style="{ width: hardware.total ? `${Math.round(hardware.polled / hardware.total * 100)}%` : '0%' }"></div>
                    </div>
                    <div class="flex flex-wrap gap-2 text-[10px] uppercase font-black tracking-widest">
                        <span class="px-3 py-1 rounded-full bg-red-500/15 text-red-300">Критично {{ hardware.counts.critical }}</span>
                        <span class="px-3 py-1 rounded-full bg-amber-500/15 text-amber-200">Внимание {{ hardware.counts.warning }}</span>
                        <span class="px-3 py-1 rounded-full bg-[#22c55e]/15 text-[#22c55e]">Норма {{ hardware.counts.ok }}</span>
                        <span class="px-3 py-1 rounded-full bg-white/5 text-white/40">Ждём {{ hardware.counts.pending }}</span>
                    </div>
                    <div v-if="hardware.total === 0" class="text-sm text-white/40 font-bold">Нет зарегистрированных ПК.</div>
                    <div v-else class="grid sm:grid-cols-2 gap-2">
                        <div v-for="station in hardware.stations" :key="station.pc_id"
                             class="px-4 py-3 rounded-2xl border"
                             :class="stationTone(station)">
                            <div class="font-black uppercase italic text-sm text-white">{{ station.pc_name }}</div>
                            <div class="text-[10px] uppercase font-black mt-1">{{ stationCaption(station) }}</div>
                        </div>
                    </div>
                </div>

                <div class="bg-[#0a0a0a] border border-white/5 rounded-[1rem] p-6 flex items-center justify-between gap-4">
                    <div>
                        <div class="text-[10px] uppercase tracking-widest font-black text-white/30">Посчитано</div>
                        <div class="text-2xl font-black text-white mt-1">{{ countedRequired }} / {{ requiredTotal }}</div>
                    </div>
                    <div class="text-right text-xs text-white/40 font-bold uppercase tracking-widest">
                        Сканер HID · Enter = количество
                    </div>
                </div>

                <div v-if="dialog" class="bg-[#0a0a0a] border-2 border-cyan-400/40 rounded-[1.25rem] p-8 space-y-5">
                    <div class="text-[10px] uppercase tracking-widest font-black text-cyan-300">Количество</div>
                    <div class="text-2xl font-black text-white uppercase italic">{{ dialog.name }}</div>
                    <div class="text-white/30 text-[10px] uppercase font-black">{{ dialog.category }}</div>
                    <input ref="qtyField" v-model.number="qtyInput" type="number" min="0" step="1"
                           class="w-full bg-black border-2 border-white/10 rounded-xl py-4 px-5 text-center text-4xl font-black text-white outline-none focus:border-cyan-400"
                           @keyup.enter="saveQty">
                    <div class="flex gap-3">
                        <button type="button" @click="closeQty"
                                class="flex-1 px-5 py-4 border border-white/15 rounded-xl text-xs font-black uppercase tracking-widest text-white/60">
                            Отмена
                        </button>
                        <button type="button" :disabled="busy" @click="saveQty"
                                class="flex-1 px-5 py-4 bg-[#22c55e] text-black rounded-xl text-xs font-black uppercase tracking-widest disabled:opacity-40">
                            Ок
                        </button>
                    </div>
                </div>

                <div v-if="countedItems.length" class="space-y-2">
                    <div class="text-[10px] uppercase tracking-widest font-black text-white/30 px-1">Отсканировано</div>
                    <button v-for="item in countedItems" :key="item.id" type="button" @click="openQty(item)"
                            class="w-full text-left px-5 py-4 rounded-2xl border flex items-center justify-between gap-4"
                            :class="allRequiredCounted && mismatchIds.has(item.id)
                                ? 'bg-red-500/15 border-red-500/50'
                                : 'bg-[#0a0a0a] border-white/5'">
                        <div>
                            <div class="text-white font-black uppercase italic text-sm">{{ item.name }}</div>
                            <div class="text-[10px] text-white/30 uppercase font-black mt-1">{{ item.category }}</div>
                        </div>
                        <div class="text-right">
                            <div class="text-2xl font-black" :class="allRequiredCounted && mismatchIds.has(item.id) ? 'text-red-400' : 'text-white'">
                                {{ item.actual }}
                            </div>
                            <div v-if="allRequiredCounted" class="text-[10px] uppercase font-black"
                                 :class="mismatchIds.has(item.id) ? 'text-red-400' : 'text-[#22c55e]'">
                                план {{ item.stock }}
                            </div>
                        </div>
                    </button>
                </div>

                <div v-if="remaining.length" class="space-y-2">
                    <div class="text-[10px] uppercase tracking-widest font-black text-white/30 px-1">Ещё не считали</div>
                    <button v-for="item in remaining" :key="item.id" type="button" @click="openQty(item)"
                            class="w-full text-left px-5 py-4 rounded-2xl bg-black/40 border border-dashed border-white/10 text-white/70">
                        <div class="font-black uppercase italic text-sm">{{ item.name }}</div>
                        <div class="text-[10px] uppercase font-black text-white/30 mt-1">
                            {{ item.barcode ? 'Сканируйте или нажмите' : 'Нет штрихкода — нажмите' }}
                        </div>
                    </button>
                </div>

                <div v-if="extraCounted.length && allRequiredCounted" class="text-[10px] text-white/30 uppercase font-black">
                    Лишние позиции без остатка по учёту тоже попали в пересчёт.
                </div>

                <div v-if="canComplete" class="bg-[#0a0a0a] border border-white/5 rounded-[1rem] p-6 space-y-5">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <div class="text-sm font-black text-white uppercase italic">Касса</div>
                            <div class="text-[10px] text-white/30 uppercase font-black mt-1">По документам {{ expected_cash }} ₽</div>
                        </div>
                        <div class="flex items-center gap-2">
                            <input v-model.number="cashCounted" type="number" min="0" step="0.01"
                                   class="w-36 bg-black border-2 border-white/10 rounded-lg py-2 px-4 text-right text-xl font-black text-white outline-none focus:border-cyan-500">
                            <span class="text-white/30 text-xl font-black">₽</span>
                        </div>
                    </div>
                    <button type="button" @click="submitShift"
                            :class="mismatchIds.size ? 'bg-red-600 text-white' : 'bg-[#22c55e] text-black'"
                            class="w-full px-8 py-5 rounded-2xl text-sm font-black uppercase tracking-widest">
                        Принять смену
                    </button>
                </div>
            </template>
        </div>
    </AdminLayout>
</template>
