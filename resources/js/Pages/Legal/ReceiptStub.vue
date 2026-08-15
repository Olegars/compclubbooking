<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import { useClubName } from '@/Composables/useClubName'

const clubName = useClubName()

const props = defineProps<{
    receipt: {
        id: number
        description: string
        amount: number
        amount_text: string
        mode: string | null
        mode_label: string
        status: string
        date: string | null
        is_stub: boolean
    }
}>()

const qrSrc = computed(() => {
    if (typeof window === 'undefined') return null
    return 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data='
        + encodeURIComponent(window.location.href)
})
</script>

<template>
    <Head title="Электронный чек (заглушка)" />
    <div class="min-h-screen bg-[#050505] text-white font-mono flex items-center justify-center p-6">
        <div class="w-full max-w-sm">
            <Link href="/account/dashboard" class="text-[10px] uppercase tracking-[0.35em] text-white/30 hover:text-[#22c55e] font-black italic">
                ← В кабинет
            </Link>

            <div class="mt-6 relative bg-[#f4f4f0] text-[#111] rounded-sm shadow-[0_0_80px_rgba(34,197,94,0.15)] overflow-hidden">
                <div class="absolute inset-0 flex items-center justify-center pointer-events-none opacity-[0.07] rotate-[-18deg]">
                    <span class="text-5xl font-black uppercase tracking-widest whitespace-nowrap">Черновик</span>
                </div>

                <div class="relative p-8 space-y-4">
                    <div class="text-center border-b border-dashed border-black/20 pb-4">
                        <div class="text-[10px] uppercase tracking-[0.35em] font-black text-black/45">{{ clubName }}</div>
                        <div class="mt-2 text-lg font-black uppercase italic tracking-tighter">Электронный чек</div>
                        <div class="mt-1 text-[10px] uppercase tracking-widest font-black text-amber-700/90">
                            Заглушка · касса не подключена
                        </div>
                    </div>

                    <div class="space-y-2 text-[12px]">
                        <div class="flex justify-between gap-4">
                            <span class="text-black/45 uppercase tracking-wider text-[10px] font-black">№</span>
                            <span class="font-mono font-black">{{ receipt.id }}</span>
                        </div>
                        <div class="flex justify-between gap-4">
                            <span class="text-black/45 uppercase tracking-wider text-[10px] font-black">Дата</span>
                            <span class="font-mono">{{ receipt.date || '—' }}</span>
                        </div>
                        <div class="flex justify-between gap-4">
                            <span class="text-black/45 uppercase tracking-wider text-[10px] font-black">Тип</span>
                            <span class="font-black uppercase italic">{{ receipt.mode_label }}</span>
                        </div>
                    </div>

                    <div class="border-y border-dashed border-black/20 py-4">
                        <div class="text-[11px] uppercase tracking-wide font-black leading-snug">
                            {{ receipt.description }}
                        </div>
                        <div class="mt-3 text-3xl font-black italic font-mono tracking-tighter text-right">
                            {{ receipt.amount_text }}
                        </div>
                    </div>

                    <div v-if="qrSrc" class="mx-auto w-[180px] h-[180px] bg-white p-2 border border-black/10">
                        <img :src="qrSrc" alt="QR заглушки" class="w-full h-full object-contain" />
                    </div>

                    <p class="text-center text-[10px] leading-relaxed text-black/50 uppercase tracking-wider font-black">
                        Это демонстрационный чек. Фискальный QR ОФД появится после подключения ККТ на сервере.
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
