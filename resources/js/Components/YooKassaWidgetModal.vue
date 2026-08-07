<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, watch } from 'vue'
import axios from 'axios'

declare global {
    interface Window {
        YooMoneyCheckoutWidget?: new (options: Record<string, unknown>) => any
    }
}

const props = defineProps<{
    isOpen: boolean
    confirmationToken: string
    paymentId: string
    amount: number
}>()

const emit = defineEmits<{
    close: []
    paid: [payload: { paymentId: string; amount: number; fiscal_receipt_url?: string | null }]
}>()

const WIDGET_SCRIPT = 'https://yookassa.ru/checkout-widget/v1/checkout-widget.js'
const containerId = `yookassa-form-${Math.random().toString(36).slice(2)}`

const status = ref<'loading' | 'ready' | 'syncing' | 'error'>('loading')
const errorMessage = ref('')
let checkout: any = null
let runId = 0

const loadWidgetScript = (): Promise<void> => {
    if (window.YooMoneyCheckoutWidget) return Promise.resolve()

    const existing = document.querySelector<HTMLScriptElement>(`script[src="${WIDGET_SCRIPT}"]`)
    if (existing) {
        return new Promise((resolve, reject) => {
            existing.addEventListener('load', () => resolve(), { once: true })
            existing.addEventListener('error', () => reject(new Error('Не удалось загрузить виджет ЮKassa')), { once: true })
        })
    }

    return new Promise((resolve, reject) => {
        const script = document.createElement('script')
        script.src = WIDGET_SCRIPT
        script.async = true
        script.onload = () => resolve()
        script.onerror = () => reject(new Error('Не удалось загрузить виджет ЮKassa'))
        document.head.appendChild(script)
    })
}

const describeError = (error: unknown): string => {
    if (typeof error === 'string') return error
    if (error instanceof Error) return error.message
    try {
        return JSON.stringify(error)
    } catch {
        return 'Неизвестная ошибка'
    }
}

const destroyWidget = () => {
    runId++
    if (checkout && typeof checkout.destroy === 'function') {
        checkout.destroy()
    }
    checkout = null
}

const syncUntilPaid = async () => {
    if (status.value === 'syncing') return

    status.value = 'syncing'
    const currentRun = runId

    for (let attempt = 0; attempt < 12; attempt++) {
        if (currentRun !== runId || !props.isOpen) return

        try {
            const { data } = await axios.post(`/api/billing/yookassa/sync/${props.paymentId}`)
            if (data.paid) {
                let receiptUrl = data.fiscal_receipt_url || null
                // Фискализация идёт очередью — чуть подождём URL чека.
                for (let wait = 0; wait < 8 && !receiptUrl; wait++) {
                    await new Promise(resolve => setTimeout(resolve, 700))
                    if (currentRun !== runId || !props.isOpen) return
                    try {
                        const again = await axios.post(`/api/billing/yookassa/sync/${props.paymentId}`)
                        receiptUrl = again.data?.fiscal_receipt_url || null
                    } catch {
                        // ignore
                    }
                }
                destroyWidget()
                emit('paid', {
                    paymentId: props.paymentId,
                    amount: props.amount,
                    fiscal_receipt_url: receiptUrl,
                })
                return
            }
            if (data.payment_status === 'canceled') {
                status.value = 'error'
                errorMessage.value = 'Платёж отменён. Закройте форму и попробуйте снова.'
                return
            }
        } catch {
            // Повторяем: статус ЮKassa может обновиться с небольшой задержкой.
        }

        await new Promise(resolve => setTimeout(resolve, 1000))
    }

    status.value = 'ready'
}

const renderWidget = async () => {
    destroyWidget()
    errorMessage.value = ''
    status.value = 'loading'
    const currentRun = runId

    try {
        await loadWidgetScript()
        await nextTick()

        if (currentRun !== runId || !props.isOpen || !window.YooMoneyCheckoutWidget) return

        checkout = new window.YooMoneyCheckoutWidget({
            confirmation_token: props.confirmationToken,
            customization: {
                colors: {
                    background: '#0A0A0A',
                    control_primary: '#22C55E',
                    control_primary_content: '#020202',
                    control_secondary: '#64748B',
                    border: '#24332A',
                    text: '#F5F5F5',
                },
            },
            error_callback: (error: unknown) => {
                status.value = 'error'
                errorMessage.value = `Ошибка ЮKassa: ${describeError(error)}`
            },
        })

        checkout.on?.('success', syncUntilPaid)
        checkout.on?.('complete', syncUntilPaid)
        checkout.on?.('fail', () => {
            status.value = 'error'
            errorMessage.value = 'Оплата не прошла. Проверьте данные карты и попробуйте снова.'
        })

        await checkout.render(containerId)
        status.value = 'ready'
    } catch (error) {
        status.value = 'error'
        errorMessage.value = describeError(error)
    }
}

const close = () => {
    destroyWidget()
    emit('close')
}

watch(
    () => [props.isOpen, props.confirmationToken] as const,
    ([isOpen, token]) => {
        if (isOpen && token) void renderWidget()
        else destroyWidget()
    },
    { immediate: true },
)

onBeforeUnmount(destroyWidget)
</script>

<template>
    <Teleport to="body">
        <Transition name="widget-modal">
            <div
                v-if="isOpen"
                class="fixed inset-0 z-[9999998] flex items-center justify-center p-3 sm:p-6"
                role="dialog"
                aria-modal="true"
                aria-label="Оплата через ЮKassa"
            >
                <div class="absolute inset-0 bg-black/90 backdrop-blur-xl"></div>

                <div class="relative flex max-h-[calc(100vh-24px)] w-full max-w-[520px] flex-col overflow-hidden rounded-2xl border border-[#22c55e]/30 bg-[#080808] shadow-[0_0_80px_rgba(34,197,94,0.18)]">
                    <div class="flex shrink-0 items-center justify-between border-b border-white/10 px-5 py-4 sm:px-6">
                        <div>
                            <div class="text-[10px] font-black uppercase italic tracking-[0.22em] text-[#22c55e]">Reactor Pay</div>
                            <div class="mt-1 text-xl font-black italic text-white">{{ amount.toLocaleString('ru-RU') }} ₽</div>
                        </div>
                        <button
                            type="button"
                            class="flex h-9 w-9 items-center justify-center rounded-lg border border-white/10 text-white/40 transition-colors hover:border-white/30 hover:text-white"
                            aria-label="Закрыть оплату"
                            @click="close"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <path stroke-linecap="round" d="M6 6l12 12M18 6L6 18" />
                            </svg>
                        </button>
                    </div>

                    <div class="min-h-[360px] overflow-y-auto p-3 sm:p-5">
                        <div v-if="status === 'loading'" class="flex min-h-[340px] flex-col items-center justify-center">
                            <div class="h-10 w-10 animate-spin rounded-full border-2 border-[#22c55e]/20 border-t-[#22c55e]"></div>
                            <div class="mt-5 text-[10px] font-black uppercase italic tracking-[0.2em] text-[#22c55e]">Загрузка формы</div>
                        </div>

                        <div v-show="status !== 'loading'" :id="containerId" class="min-h-[320px]"></div>

                        <div v-if="status === 'syncing'" class="mt-3 rounded-xl border border-[#22c55e]/30 bg-[#22c55e]/10 p-4 text-center text-[10px] font-black uppercase italic tracking-widest text-[#22c55e]">
                            Платёж принят · обновляем баланс
                        </div>

                        <div v-if="status === 'error'" class="mt-3 rounded-xl border border-red-500/30 bg-red-500/10 p-4 text-center text-xs font-bold text-red-300">
                            {{ errorMessage }}
                        </div>
                    </div>

                    <div class="shrink-0 border-t border-white/5 px-5 py-3 text-center text-[8px] font-bold uppercase tracking-[0.18em] text-white/25">
                        Защищённая форма ЮKassa · данные карты не передаются REACTOR
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.widget-modal-enter-active,
.widget-modal-leave-active {
    transition: opacity 0.2s ease;
}
.widget-modal-enter-active > div:last-child,
.widget-modal-leave-active > div:last-child {
    transition: transform 0.25s ease, opacity 0.2s ease;
}
.widget-modal-enter-from,
.widget-modal-leave-to {
    opacity: 0;
}
.widget-modal-enter-from > div:last-child,
.widget-modal-leave-to > div:last-child {
    opacity: 0;
    transform: translateY(12px) scale(0.98);
}
</style>
