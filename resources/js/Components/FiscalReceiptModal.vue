<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import axios from 'axios'

const props = defineProps<{
    isOpen: boolean
    receiptUrl: string | null
    amount?: number | null
    title?: string
    /** payment uuid — дотягиваем URL чека, если касса ответила позже */
    paymentId?: string | null
    fiscalStatus?: string | null
    isStub?: boolean
}>()

const emit = defineEmits<{
    close: []
}>()

const liveUrl = ref<string | null>(null)
const liveStatus = ref<string | null>(null)
const liveStub = ref(false)
let pollTimer: ReturnType<typeof setInterval> | null = null

const effectiveUrl = computed(() => liveUrl.value || props.receiptUrl || null)
const effectiveStatus = computed(() => liveStatus.value || props.fiscalStatus || null)

const isStubReceipt = computed(() => {
    if (liveStub.value || props.isStub) return true
    if (effectiveStatus.value === 'skipped') return true
    const url = effectiveUrl.value || ''
    return url.includes('/receipt/stub/')
})

const qrSrc = computed(() => {
    if (!effectiveUrl.value) return null
    return 'https://api.qrserver.com/v1/create-qr-code/?size=280x280&data='
        + encodeURIComponent(effectiveUrl.value)
})

const amountText = computed(() => {
    if (props.amount == null || Number.isNaN(Number(props.amount))) return null
    const n = Number(props.amount)
    return `${n > 0 ? '+' : ''}${Math.round(n)} ₽`
})

const statusHint = computed(() => {
    if (qrSrc.value) return null
    const s = effectiveStatus.value
    if (s === 'skipped') {
        return 'Оплата прошла. Готовим заглушку чека…'
    }
    if (s === 'deferred') {
        return 'Списание прошло. Кассовый чек появится после входа на забронированный ПК.'
    }
    if (s === 'void') {
        return 'Чек не формировался: бронь отменена до начала сессии.'
    }
    if (s === 'error') {
        return 'Оплата прошла, но касса вернула ошибку. Чек появится в логе транзакций после исправления.'
    }
    if (s === 'pending' || props.paymentId) {
        return 'Оплата прошла. Формируем электронный чек…'
    }
    return 'Оплата прошла. Чек можно открыть позже в логе транзакций.'
})

const stopPoll = () => {
    if (pollTimer) {
        clearInterval(pollTimer)
        pollTimer = null
    }
}

const pollOnce = async () => {
    if (!props.paymentId) return
    try {
        const { data } = await axios.get(`/api/billing/yookassa/receipt/${props.paymentId}`)
        if (data.fiscal_status) liveStatus.value = data.fiscal_status
        if (data.is_stub_receipt) liveStub.value = true
        if (data.fiscal_receipt_url) {
            liveUrl.value = data.fiscal_receipt_url
            stopPoll()
        }
        if (data.fiscal_status === 'skipped' || data.fiscal_status === 'error' || data.fiscal_status === 'success') {
            if (data.fiscal_status !== 'success' || data.fiscal_receipt_url) stopPoll()
        }
    } catch {
        // ignore
    }
}

const startPoll = () => {
    stopPoll()
    liveUrl.value = props.receiptUrl
    liveStatus.value = props.fiscalStatus
    liveStub.value = !!props.isStub
    if (!props.isOpen || !props.paymentId) return
    if (props.receiptUrl) return
    void pollOnce()
    pollTimer = setInterval(() => { void pollOnce() }, 1500)
    setTimeout(() => stopPoll(), 45000)
}

watch(() => [props.isOpen, props.paymentId, props.receiptUrl], () => {
    if (props.isOpen) startPoll()
    else stopPoll()
}, { immediate: true })

onBeforeUnmount(stopPoll)
</script>

<template>
    <Teleport to="body">
        <div
            v-if="isOpen"
            class="fixed inset-0 z-[10000] flex items-center justify-center p-6 animate-in fade-in duration-300"
        >
            <div class="absolute inset-0 bg-black/95 backdrop-blur-xl" @click="emit('close')" />
            <div class="relative max-w-md w-full bg-[#0a0a0a] border border-[#22c55e]/30 rounded-[1.25rem] p-10 text-center shadow-[0_0_120px_rgba(34,197,94,0.2)]">
                <h2 class="text-[#22c55e] text-3xl font-black uppercase italic tracking-tighter mb-2">
                    {{ title || 'Оплата прошла' }}
                </h2>
                <p v-if="amountText" class="text-white text-4xl font-black italic font-mono mb-4">{{ amountText }}</p>

                <div
                    v-if="isStubReceipt && qrSrc"
                    class="mb-4 inline-flex items-center gap-2 px-3 py-1.5 rounded-full border border-amber-500/40 bg-amber-500/10 text-amber-300 text-[9px] font-black uppercase tracking-[0.25em]"
                >
                    Заглушка · касса выключена
                </div>
                <p
                    v-else
                    class="text-white/35 text-[10px] uppercase tracking-[0.3em] font-black italic mb-6"
                >
                    Электронный кассовый чек · QR ОФД
                </p>

                <div v-if="qrSrc" class="mx-auto w-[240px] h-[240px] bg-white rounded-2xl p-3 mb-4 relative">
                    <img :src="qrSrc" alt="QR чека" class="w-full h-full object-contain" />
                    <div
                        v-if="isStubReceipt"
                        class="pointer-events-none absolute inset-3 flex items-center justify-center"
                    >
                        <span class="rotate-[-22deg] text-black/15 text-2xl font-black uppercase tracking-widest">
                            Demo
                        </span>
                    </div>
                </div>
                <div v-else class="mb-6 text-white/45 text-[11px] uppercase tracking-widest font-black italic py-8 px-2 leading-relaxed">
                    {{ statusHint }}
                </div>

                <p
                    v-if="isStubReceipt && qrSrc"
                    class="text-white/35 text-[10px] uppercase tracking-widest font-black italic mb-6 leading-relaxed"
                >
                    Демо-чек для интерфейса. Фискальный QR ОФД появится после подключения ККТ.
                </p>

                <a
                    v-if="effectiveUrl"
                    :href="effectiveUrl"
                    target="_blank"
                    rel="noopener"
                    class="block text-[10px] text-cyan-400/80 hover:text-cyan-400 uppercase font-black tracking-widest mb-8 break-all"
                >
                    {{ isStubReceipt ? 'Открыть заглушку чека' : 'Открыть чек на сайте ОФД' }}
                </a>

                <button
                    type="button"
                    class="w-full py-5 bg-[#22c55e] text-black font-black uppercase rounded-[1rem] italic hover:bg-[#2ae06d] transition-colors"
                    @click="emit('close')"
                >
                    Готово
                </button>
            </div>
        </div>
    </Teleport>
</template>
