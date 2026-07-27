<script setup>
import { onMounted, onUnmounted } from 'vue'

const props = defineProps({
    isOpen: { type: Boolean, default: false },
    title: { type: String, default: 'Подтвердите действие' },
    message: { type: String, default: '' },
    confirmText: { type: String, default: 'Подтвердить' },
    cancelText: { type: String, default: 'Отмена' },
    tone: { type: String, default: 'danger' }, // danger | primary
    isProcessing: { type: Boolean, default: false },
})

const emit = defineEmits(['confirm', 'close'])

const handleKeydown = (e) => {
    if (!props.isOpen) return
    if (e.key === 'Escape') emit('close')
    if (e.key === 'Enter') emit('confirm')
}

onMounted(() => window.addEventListener('keydown', handleKeydown))
onUnmounted(() => window.removeEventListener('keydown', handleKeydown))
</script>

<template>
    <div v-if="isOpen" class="fixed inset-0 z-[99998] flex items-center justify-center p-4 font-mono">
        <div class="absolute inset-0 bg-black/80 backdrop-blur-sm" @click="emit('close')"></div>

        <div class="relative z-10 w-full max-w-md bg-[#0a0a0a] border rounded-[2.5rem] p-8 shadow-2xl animate-in"
             :class="tone === 'danger' ? 'border-red-500/30 shadow-[0_0_80px_rgba(239,68,68,0.12)]' : 'border-[#22c55e]/30 shadow-[0_0_80px_rgba(34,197,94,0.12)]'">

            <div class="flex items-center gap-4 mb-6">
                <span class="w-2 h-10 rounded-full" :class="tone === 'danger' ? 'bg-red-500' : 'bg-[#22c55e]'"></span>
                <h3 class="text-xl font-black uppercase italic tracking-tighter text-white">{{ title }}</h3>
            </div>

            <p v-if="message" class="text-xs text-white/50 leading-relaxed mb-8 uppercase tracking-wider font-bold">
                {{ message }}
            </p>

            <div class="flex gap-3">
                <button @click="emit('close')"
                        class="flex-1 py-4 bg-white/5 border border-white/10 text-white/50 hover:text-white rounded-xl text-[10px] font-black uppercase tracking-widest transition-all active:scale-95">
                    {{ cancelText }}
                </button>
                <button @click="emit('confirm')" :disabled="isProcessing"
                        class="flex-1 py-4 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all active:scale-95 disabled:opacity-40"
                        :class="tone === 'danger'
                            ? 'bg-red-500 hover:bg-red-400 text-black shadow-[0_0_25px_rgba(239,68,68,0.25)]'
                            : 'bg-[#22c55e] hover:bg-[#1ea34d] text-black shadow-[0_0_25px_rgba(34,197,94,0.25)]'">
                    {{ confirmText }}
                </button>
            </div>

            <div class="absolute top-6 left-6 w-4 h-4 border-t-2 border-l-2 border-white/10"></div>
            <div class="absolute bottom-6 right-6 w-4 h-4 border-b-2 border-r-2 border-white/10"></div>
        </div>
    </div>
</template>

<style scoped>
.animate-in { animation: zoom-in 0.25s cubic-bezier(0.16, 1, 0.3, 1); }
@keyframes zoom-in {
    from { opacity: 0; transform: translateY(12px) scale(0.96); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}
</style>
