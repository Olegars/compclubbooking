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
}>()

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
let mediaStream: MediaStream | null = null
let detectTimer: number | null = null

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
    const msg = mismatches.length
        ? `Есть расхождения (${mismatches.length}). Недостача уйдёт уходящему админу. Принять смену?`
        : 'Принять смену и стать активным админом?'
    if (!confirm(msg)) return
    router.post('/admin/api/shifts/complete', { cash_counted: cashCounted.value })
}

watch(phase, (next) => {
    if (next === 'counting') {
        stopCamera()
        enableReceiveMode(handleScan)
    }
}, { immediate: true })

onMounted(() => {
    if (phase.value === 'presence') startCamera()
})

onUnmounted(() => {
    stopCamera()
    disableReceiveMode()
})
</script>

<template>
    <Head title="Приём смены" />
    <AdminLayout>
        <div class="max-w-3xl mx-auto space-y-6 font-mono pb-24 px-4">
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
                        Сканируйте товар, введите количество, затем следующий.
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
