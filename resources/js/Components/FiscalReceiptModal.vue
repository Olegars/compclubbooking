<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{
    isOpen: boolean
    receiptUrl: string | null
    amount?: number | null
    title?: string
    autoCloseSec?: number | null
}>()

const emit = defineEmits<{
    close: []
}>()

const qrSrc = computed(() => {
    if (!props.receiptUrl) return null
    return 'https://api.qrserver.com/v1/create-qr-code/?size=280x280&data='
        + encodeURIComponent(props.receiptUrl)
})

const amountText = computed(() => {
    if (props.amount == null || Number.isNaN(Number(props.amount))) return null
    const n = Number(props.amount)
    return `${n > 0 ? '+' : ''}${Math.round(n)} ₽`
})
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
                <p v-if="amountText" class="text-white text-4xl font-black italic font-mono mb-6">{{ amountText }}</p>
                <p class="text-white/35 text-[10px] uppercase tracking-[0.3em] font-black italic mb-6">
                    Электронный кассовый чек · QR ОФД
                </p>

                <div v-if="qrSrc" class="mx-auto w-[240px] h-[240px] bg-white rounded-2xl p-3 mb-6">
                    <img :src="qrSrc" alt="QR чека" class="w-full h-full object-contain" />
                </div>
                <div v-else class="mb-6 text-white/30 text-[11px] uppercase tracking-widest font-black italic py-10">
                    Чек формируется… откройте позже в логе транзакций
                </div>

                <a
                    v-if="receiptUrl"
                    :href="receiptUrl"
                    target="_blank"
                    rel="noopener"
                    class="block text-[10px] text-cyan-400/80 hover:text-cyan-400 uppercase font-black tracking-widest mb-8 break-all"
                >
                    Открыть чек на сайте ОФД
                </a>

                <button
                    type="button"
                    class="w-full py-5 bg-[#22c55e] text-black font-black uppercase rounded-[1rem] italic hover:bg-[#2ae06d] transition-colors"
                    @click="emit('close')"
                >
                    Готово
                </button>
                <p v-if="autoCloseSec" class="mt-4 text-[9px] text-white/20 uppercase tracking-widest">
                    Автозакрытие ~{{ autoCloseSec }} с
                </p>
            </div>
        </div>
    </Teleport>
</template>
