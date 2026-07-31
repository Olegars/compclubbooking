<script setup lang="ts">
import { ref, watch, onMounted, computed } from 'vue'

const props = defineProps<{
    isOpen: boolean;
    phone: string;
    isTerminal?: boolean;
}>()

const emit = defineEmits(['close', 'verify'])

// ЯДЕРНАЯ ПРОВЕРКА: Если пропсы потерялись, смотрим прямо в URL браузера!
const isActuallyTerminal = computed(() => {
    // 1. Если проп передали правильно - верим ему
    if (props.isTerminal === true) return true;

    // 2. Если мы на адресах киоска или терминала - 100% показываем кнопки
    if (typeof window !== 'undefined') {
        const path = window.location.pathname.toLowerCase();
        if (path.includes('kiosk') || path.includes('terminal')) {
            return true;
        }
    }

    return false; // Иначе это обычный сайт (Букинг)
})

const code = ref('')
const inputRef = ref<HTMLInputElement | null>(null)

// Логика ввода
const handleInput = (digit: string) => { if (code.value.length < 4) code.value += digit }
const handleDelete = () => { code.value = code.value.slice(0, -1) }
const handleClear = () => { code.value = '' }

// Фокус на невидимый инпут для обычного сайта
onMounted(() => {
    if (!isActuallyTerminal.value) inputRef.value?.focus()
})

watch(code, (newVal) => {
    if (newVal.length > 4) code.value = newVal.slice(0, 4)
    if (code.value.length === 4) {
        setTimeout(() => { emit('verify', code.value) }, 300)
    }
})

// Базовый класс для кнопок
const btnClass = "h-16 bg-[#0a0a0a] border border-[#22c55e]/20 rounded-2xl flex items-center justify-center text-3xl font-mono font-black text-[#22c55e] transition-all active:scale-95 active:bg-[#22c55e] active:text-black cursor-pointer select-none";
</script>

<template>
    <div v-if="isOpen" class="fixed inset-0 z-[9999999] flex items-center justify-center font-mono p-4">

        <div class="absolute inset-0 bg-black/90 backdrop-blur-md" @click="emit('close')"></div>

        <div class="relative w-full max-w-sm bg-[#050505] border border-[#22c55e]/40 rounded-[1rem] p-8 shadow-[0_0_80px_rgba(34,197,94,0.2)] flex flex-col items-center">

            <input
                v-if="!isActuallyTerminal"
                ref="inputRef"
                v-model="code"
                type="tel"
                maxlength="4"
                class="absolute opacity-0 pointer-events-none"
                @blur="!isActuallyTerminal && inputRef?.focus()"
            />

            <h2 class="text-[#22c55e] text-xl font-black mb-1 tracking-widest uppercase italic">Подтверждение</h2>
            <p class="text-white/30 text-[9px] mb-8 uppercase tracking-widest">Код для {{ phone }}</p>

            <div class="flex gap-3 mb-10" @click="!isActuallyTerminal && inputRef?.focus()">
                <div v-for="i in 4" :key="i" class="w-14 h-18 border-2 rounded-xl flex items-center justify-center text-3xl font-black transition-colors"
                     :class="code[i-1] ? 'border-[#22c55e] text-[#22c55e] shadow-[0_0_15px_rgba(34,197,94,0.3)]' : 'border-white/5 text-white/5'">
                    {{ code[i-1] || '•' }}
                </div>
            </div>

            <div v-if="isActuallyTerminal" class="grid grid-cols-3 gap-3 w-full animate-in fade-in zoom-in duration-300">
                <button v-for="key in ['1','2','3','4','5','6','7','8','9']" :key="key" @click="handleInput(key)" :class="btnClass">{{ key }}</button>
                <button @click="handleClear" :class="btnClass" class="text-red-500/50 !border-red-500/20">C</button>
                <button @click="handleInput('0')" :class="btnClass">0</button>
                <button @click="handleDelete" :class="btnClass" class="text-yellow-500/50 flex items-center justify-center !border-yellow-500/20">
                    <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2M3 12l6.414 6.414a2 2 0 001.414.586H19a2 2 0 002-2V7a2 2 0 00-2-2h-8.172a2 2 0 00-1.414.586L3 12z" /></svg>
                </button>
            </div>

            <button @click="emit('close')" class="mt-8 text-white/20 uppercase text-[9px] tracking-widest hover:text-red-500 transition-colors">[ Отмена ]</button>
        </div>
    </div>
</template>

<style scoped>
.animate-in { animation-fill-mode: forwards; }
@keyframes zoom-in {
    from { opacity: 0; transform: scale(0.95) translateY(10px); }
    to { opacity: 1; transform: scale(1) translateY(0); }
}
.zoom-in { animation: zoom-in 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
</style>
