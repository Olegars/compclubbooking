<script setup lang="ts">
import { ref, computed, watch, onUnmounted, nextTick } from 'vue'
import axios from 'axios'
// UMD vendor (без npm на этой машине)
// eslint-disable-next-line @typescript-eslint/ban-ts-comment
// @ts-ignore
import jsQR from '@/vendor/jsQR.js'
import { useClubName } from '@/Composables/useClubName'

const props = defineProps<{
    isOpen: boolean
}>()

const emit = defineEmits<{
    close: []
    activated: [payload: Record<string, unknown>]
    'request-topup': [suggestedAmount: number]
}>()
const clubName = useClubName()

type Phase = 'scan' | 'busy' | 'needs_booking' | 'success' | 'error'

const phase = ref<Phase>('scan')
const errorMessage = ref('')
const hint = ref('Наведите камеру на QR на экране ПК')
const videoRef = ref<HTMLVideoElement | null>(null)
const canvasRef = ref<HTMLCanvasElement | null>(null)

const token = ref('')
const computerName = ref('')
const balance = ref(0)
const durationMinutes = ref(60)
const minDuration = ref(60)
const durationStep = ref(15)
const canPay = ref(false)
const quotePrice = ref(0)
const busy = ref(false)
const quoteBusy = ref(false)
const redeemLock = ref(false)

let stream: MediaStream | null = null
let rafId = 0
let quoteTimer: ReturnType<typeof setTimeout> | null = null

const durationLabel = computed(() => {
    const h = Math.floor(durationMinutes.value / 60)
    const m = durationMinutes.value % 60
    if (m === 0) return `${h} ч`
    return `${h} ч ${m} мин`
})

const shortage = computed(() => Math.max(0, Math.ceil(quotePrice.value - balance.value)))

const parseToken = (raw: string): string | null => {
    const text = (raw || '').trim()
    if (!text) return null
    const uuidRe = /[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i
    try {
        if (text.includes('qr=')) {
            const u = new URL(text, window.location.origin)
            const q = u.searchParams.get('qr')
            if (q && uuidRe.test(q)) return q
        }
    } catch { /* plain token */ }
    const m = text.match(uuidRe)
    return m ? m[0] : null
}

const stopCamera = () => {
    if (rafId) {
        cancelAnimationFrame(rafId)
        rafId = 0
    }
    if (stream) {
        stream.getTracks().forEach((t) => t.stop())
        stream = null
    }
    if (videoRef.value) {
        videoRef.value.srcObject = null
    }
}

const tick = () => {
    if (!props.isOpen || phase.value !== 'scan' || redeemLock.value) return
    const video = videoRef.value
    const canvas = canvasRef.value
    if (!video || !canvas || video.readyState < 2) {
        rafId = requestAnimationFrame(tick)
        return
    }
    const w = video.videoWidth
    const h = video.videoHeight
    if (w && h) {
        canvas.width = w
        canvas.height = h
        const ctx = canvas.getContext('2d', { willReadFrequently: true })
        if (ctx) {
            ctx.drawImage(video, 0, 0, w, h)
            const imageData = ctx.getImageData(0, 0, w, h)
            const code = jsQR(imageData.data, w, h, { inversionAttempts: 'dontInvert' })
            if (code?.data) {
                const t = parseToken(code.data)
                if (t) {
                    void handleRedeem(t)
                    return
                }
            }
        }
    }
    rafId = requestAnimationFrame(tick)
}

const startCamera = async () => {
    stopCamera()
    hint.value = 'Запрос доступа к камере…'
    try {
        stream = await navigator.mediaDevices.getUserMedia({
            audio: false,
            video: {
                facingMode: { ideal: 'environment' },
                width: { ideal: 1280 },
                height: { ideal: 720 },
            },
        })
        await nextTick()
        const video = videoRef.value
        if (!video) return
        video.srcObject = stream
        await video.play()
        hint.value = 'Наведите камеру на QR на экране ПК'
        rafId = requestAnimationFrame(tick)
    } catch {
        phase.value = 'error'
        errorMessage.value = 'Нет доступа к камере. Разрешите камеру в браузере.'
    }
}

const resetState = () => {
    phase.value = 'scan'
    errorMessage.value = ''
    token.value = ''
    computerName.value = ''
    balance.value = 0
    durationMinutes.value = 60
    minDuration.value = 60
    durationStep.value = 15
    canPay.value = false
    quotePrice.value = 0
    busy.value = false
    quoteBusy.value = false
    redeemLock.value = false
    if (quoteTimer) {
        clearTimeout(quoteTimer)
        quoteTimer = null
    }
}

const applyNeedsBooking = (data: any) => {
    token.value = String(data.token || token.value)
    computerName.value = String(data.computer?.name || '')
    balance.value = Number(data.balance ?? 0)
    durationMinutes.value = Number(data.duration_minutes ?? data.min_duration_minutes ?? 60)
    minDuration.value = Number(data.min_duration_minutes ?? 60)
    durationStep.value = Number(data.duration_step_minutes ?? 15)
    quotePrice.value = Number(data.quote?.total_price ?? 0)
    canPay.value = !!data.can_pay
    phase.value = 'needs_booking'
    stopCamera()
}

const handleRedeem = async (scanned: string) => {
    if (redeemLock.value) return
    redeemLock.value = true
    stopCamera()
    phase.value = 'busy'
    token.value = scanned
    try {
        const { data } = await axios.post('/account/qr/redeem', { token: scanned })
        if (data.status === 'activated') {
            phase.value = 'success'
            emit('activated', data)
            return
        }
        if (data.status === 'needs_booking') {
            applyNeedsBooking(data)
            return
        }
        phase.value = 'error'
        errorMessage.value = data.message || 'Не удалось активировать сессию'
    } catch (e: any) {
        phase.value = 'error'
        errorMessage.value = e.response?.data?.message || e.message || 'Ошибка сети'
    } finally {
        redeemLock.value = false
    }
}

const fetchQuote = async () => {
    if (!token.value || phase.value !== 'needs_booking') return
    quoteBusy.value = true
    try {
        const { data } = await axios.post('/account/qr/quote', {
            token: token.value,
            duration_minutes: durationMinutes.value,
        })
        quotePrice.value = Number(data.quote?.total_price ?? 0)
        balance.value = Number(data.balance ?? balance.value)
        canPay.value = !!data.can_pay
        if (data.computer?.name) computerName.value = String(data.computer.name)
    } catch (e: any) {
        errorMessage.value = e.response?.data?.message || 'Не удалось рассчитать цену'
    } finally {
        quoteBusy.value = false
    }
}

const scheduleQuote = () => {
    if (quoteTimer) clearTimeout(quoteTimer)
    quoteTimer = setTimeout(() => { void fetchQuote() }, 250)
}

const bumpDuration = (delta: number) => {
    const next = durationMinutes.value + delta
    if (next < minDuration.value) return
    durationMinutes.value = next
    scheduleQuote()
}

const openSession = async () => {
    if (!token.value || busy.value) return
    if (!canPay.value) {
        emit('request-topup', Math.max(100, shortage.value || 500))
        return
    }
    busy.value = true
    errorMessage.value = ''
    try {
        const { data } = await axios.post('/account/qr/book', {
            token: token.value,
            duration_minutes: durationMinutes.value,
        })
        if (data.status === 'activated') {
            phase.value = 'success'
            emit('activated', data)
            return
        }
        if (data.status === 'needs_topup') {
            balance.value = Number(data.balance ?? balance.value)
            quotePrice.value = Number(data.quote?.total_price ?? quotePrice.value)
            canPay.value = false
            emit('request-topup', Math.max(100, Math.ceil(Number(data.shortage ?? shortage.value) || 500)))
            return
        }
        if (data.status === 'needs_booking') {
            applyNeedsBooking(data)
            return
        }
        errorMessage.value = data.message || 'Не удалось открыть сессию'
    } catch (e: any) {
        errorMessage.value = e.response?.data?.message || e.message || 'Ошибка сети'
    } finally {
        busy.value = false
    }
}

const retryScan = () => {
    resetState()
    void startCamera()
}

const close = () => {
    stopCamera()
    resetState()
    emit('close')
}

watch(() => props.isOpen, (open) => {
    if (open) {
        resetState()
        try {
            const params = new URLSearchParams(window.location.search)
            const fromUrl = parseToken(params.get('qr') || '')
            if (fromUrl) {
                void handleRedeem(fromUrl)
                return
            }
        } catch { /* ignore */ }
        void startCamera()
    } else {
        stopCamera()
        resetState()
    }
})

onUnmounted(() => {
    stopCamera()
    if (quoteTimer) clearTimeout(quoteTimer)
})

defineExpose({
    refreshAfterTopUp: async () => {
        if (phase.value === 'needs_booking' && token.value) {
            await fetchQuote()
        }
    },
})
</script>

<template>
    <Teleport to="body">
        <div
            v-if="isOpen"
            class="fixed inset-0 flex items-center justify-center z-[9997] p-4 sm:p-6"
        >
            <div class="absolute inset-0 bg-black/95 backdrop-blur-xl" @click="close"></div>
            <div class="relative w-full max-w-md bg-[#0a0a0a] border border-[#22c55e]/30 rounded-[1.25rem] p-6 sm:p-8 shadow-[0_0_120px_rgba(34,197,94,0.18)]">
                <button
                    type="button"
                    class="absolute top-4 right-4 text-white/40 hover:text-white text-xl leading-none"
                    @click="close"
                >×</button>

                <h2 class="text-[#22c55e] text-2xl sm:text-3xl font-black uppercase italic tracking-tighter mb-2">
                    Сканер QR
                </h2>
                <p class="text-white/40 text-[10px] uppercase tracking-widest mb-6">
                    Вход на терминал {{ clubName }}
                </p>

                <div v-if="phase === 'scan' || phase === 'busy'" class="space-y-4">
                    <div class="relative aspect-[4/3] rounded-xl overflow-hidden border border-white/10 bg-black">
                        <video
                            ref="videoRef"
                            class="absolute inset-0 w-full h-full object-cover"
                            playsinline
                            muted
                            autoplay
                        />
                        <canvas ref="canvasRef" class="hidden" />
                        <div class="absolute inset-6 border-2 border-[#22c55e]/50 rounded-lg pointer-events-none" />
                        <div
                            v-if="phase === 'busy'"
                            class="absolute inset-0 bg-black/70 flex items-center justify-center text-[#22c55e] font-black uppercase tracking-widest text-xs"
                        >
                            Активация…
                        </div>
                    </div>
                    <p class="text-center text-white/50 text-xs">{{ hint }}</p>
                    <p class="text-center text-white/25 text-[9px] uppercase tracking-widest leading-relaxed">
                        PIN — на клавиатуре ПК · здесь только камера на QR
                    </p>
                </div>

                <div v-else-if="phase === 'needs_booking'" class="space-y-5 text-center">
                    <p class="text-white/80 text-sm leading-relaxed">
                        {{ computerName ? `ПК «${computerName}»` : 'Этот компьютер' }} не забронирован.
                        Можно открыть сессию сейчас.
                    </p>

                    <div class="flex items-center justify-center gap-4">
                        <button
                            type="button"
                            class="w-12 h-12 rounded-xl border border-white/15 bg-white/5 text-2xl text-white hover:border-[#22c55e]/50"
                            :disabled="durationMinutes <= minDuration || quoteBusy"
                            @click="bumpDuration(-durationStep)"
                        >−</button>
                        <div>
                            <div class="text-3xl font-black text-white italic">{{ durationLabel }}</div>
                            <div class="text-[10px] text-white/35 uppercase tracking-widest mt-1">от 1 ч · шаг 15 мин</div>
                        </div>
                        <button
                            type="button"
                            class="w-12 h-12 rounded-xl border border-white/15 bg-white/5 text-2xl text-white hover:border-[#22c55e]/50"
                            :disabled="quoteBusy"
                            @click="bumpDuration(durationStep)"
                        >+</button>
                    </div>

                    <div class="rounded-xl border border-white/10 bg-white/[0.03] px-4 py-4 space-y-2">
                        <div class="flex justify-between text-sm">
                            <span class="text-white/45">К оплате</span>
                            <span class="text-white font-black">{{ Math.round(quotePrice) }} ₽</span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-white/45">Баланс</span>
                            <span class="font-black" :class="canPay ? 'text-[#22c55e]' : 'text-amber-400'">
                                {{ Math.floor(balance) }} ₽
                            </span>
                        </div>
                    </div>

                    <p v-if="errorMessage" class="text-red-400 text-xs">{{ errorMessage }}</p>

                    <button
                        type="button"
                        class="w-full py-5 rounded-[1rem] font-black uppercase italic tracking-wide transition-colors disabled:opacity-60"
                        :class="canPay
                            ? 'bg-[#22c55e] text-black hover:bg-[#2ae06d]'
                            : 'bg-amber-500 text-black hover:bg-amber-400'"
                        :disabled="busy || quoteBusy"
                        @click="openSession"
                    >
                        <template v-if="busy">Открываем…</template>
                        <template v-else-if="canPay">Открыть сессию</template>
                        <template v-else>Пополнить {{ shortage }} ₽</template>
                    </button>
                    <button
                        type="button"
                        class="w-full py-3 text-white/40 text-[10px] uppercase tracking-widest hover:text-white"
                        @click="retryScan"
                    >Сканировать снова</button>
                </div>

                <div v-else-if="phase === 'success'" class="text-center space-y-4 py-6">
                    <div class="text-[#22c55e] text-4xl font-black uppercase italic">Сессия открыта</div>
                    <p class="text-white/50 text-sm">Можете пользоваться терминалом — шелл подхватит вход сам.</p>
                    <button
                        type="button"
                        class="w-full py-4 bg-[#22c55e] text-black font-black uppercase rounded-[1rem] italic"
                        @click="close"
                    >Готово</button>
                </div>

                <div v-else class="text-center space-y-4 py-4">
                    <p class="text-red-400 text-sm">{{ errorMessage || 'Ошибка' }}</p>
                    <button
                        type="button"
                        class="w-full py-4 border border-[#22c55e]/40 text-[#22c55e] font-black uppercase rounded-[1rem] italic"
                        @click="retryScan"
                    >Повторить</button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
