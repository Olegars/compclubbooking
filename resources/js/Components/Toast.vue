<script setup>
import { useToast } from '@/Composables/useToast'

const { toasts, dismiss } = useToast()

const accents = {
    success: {
        border: 'border-[#22c55e]/40',
        bar: 'bg-[#22c55e]',
        text: 'text-[#22c55e]',
        glow: 'shadow-[0_0_40px_rgba(34,197,94,0.15)]',
        icon: '✓',
    },
    error: {
        border: 'border-red-500/40',
        bar: 'bg-red-500',
        text: 'text-red-500',
        glow: 'shadow-[0_0_40px_rgba(239,68,68,0.15)]',
        icon: '✕',
    },
    warning: {
        border: 'border-amber-500/40',
        bar: 'bg-amber-500',
        text: 'text-amber-500',
        glow: 'shadow-[0_0_40px_rgba(245,158,11,0.15)]',
        icon: '!',
    },
    info: {
        border: 'border-cyan-500/40',
        bar: 'bg-cyan-500',
        text: 'text-cyan-500',
        glow: 'shadow-[0_0_40px_rgba(6,182,212,0.15)]',
        icon: 'i',
    },
}

const accent = (type) => accents[type] || accents.info
</script>

<template>
    <div class="fixed bottom-8 right-8 z-[99999] flex flex-col gap-3 w-[340px] max-w-[calc(100vw-2rem)] pointer-events-none font-mono">
        <TransitionGroup name="toast">
            <div v-for="item in toasts" :key="item.id"
                 class="pointer-events-auto relative flex items-center gap-4 bg-[#0a0a0a] border rounded-2xl px-5 py-4 overflow-hidden backdrop-blur-md"
                 :class="[accent(item.type).border, accent(item.type).glow]">

                <div class="absolute left-0 top-0 bottom-0 w-1" :class="accent(item.type).bar"></div>

                <span class="shrink-0 w-7 h-7 rounded-lg bg-white/5 flex items-center justify-center text-[11px] font-black"
                      :class="accent(item.type).text">
                    {{ accent(item.type).icon }}
                </span>

                <p class="flex-1 text-[11px] font-bold uppercase tracking-wider text-white/80 leading-snug">
                    {{ item.message }}
                </p>

                <button @click="dismiss(item.id)"
                        class="shrink-0 text-white/20 hover:text-white text-xs font-black transition-colors">
                    ✕
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>

<style scoped>
.toast-enter-active,
.toast-leave-active { transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
.toast-enter-from { opacity: 0; transform: translateX(30px) scale(0.95); }
.toast-leave-to { opacity: 0; transform: translateX(30px) scale(0.95); }
.toast-move { transition: transform 0.3s ease; }
</style>
