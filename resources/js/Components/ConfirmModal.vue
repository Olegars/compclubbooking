<template>
    <div v-if="isOpen" class="fixed inset-0 z-[9999999] flex items-center justify-center font-mono p-4 pointer-events-none">

        <div class="relative w-full max-w-sm bg-[#050505] border border-[#22c55e]/40 rounded-[2.5rem] p-8 shadow-[0_0_80px_rgba(34,197,94,0.2)] flex flex-col items-center pointer-events-auto animate-in zoom-in">

            <h2 class="text-[#22c55e] text-xl font-black mb-1 tracking-widest uppercase italic text-center">
                Вход в систему
            </h2>
            <p class="text-white/30 text-[9px] mb-8 uppercase tracking-widest text-center">
                Введите номер телефона
            </p>

            <div
                class="mb-8 flex h-16 w-full items-center justify-center rounded-2xl bg-[#0a0a0a] border transition-all duration-300 px-2 relative"
                :class="rawPhone.length === 10 ? 'border-[#22c55e] shadow-[0_0_15px_rgba(34,197,94,0.3)]' : 'border-[#22c55e]/20'"
            >
        <span
            class="text-2xl font-mono tracking-widest whitespace-nowrap transition-colors duration-300 font-black"
            :class="rawPhone.length === 10 ? 'text-[#22c55e] drop-shadow-[0_0_8px_rgba(34,197,94,0.8)]' : 'text-white/50'"
        >
          {{ formattedPhone || '+7 (___) ___-__-__' }}
        </span>

                <span v-if="mode === 'booking' && rawPhone.length < 10" class="w-2.5 h-6 bg-[#22c55e] animate-pulse ml-1 opacity-70 shadow-[0_0_8px_rgba(34,197,94,0.8)]"></span>
            </div>

            <div v-if="mode !== 'booking'" class="grid grid-cols-3 gap-3 w-full mb-6">
                <button
                    v-for="digit in 9"
                    :key="digit"
                    @click="appendDigit(digit.toString())"
                    class="h-16 bg-[#0a0a0a] border border-[#22c55e]/20 rounded-2xl flex items-center justify-center text-3xl font-mono font-black text-[#22c55e] transition-all active:scale-95 active:bg-[#22c55e] active:text-black hover:border-[#22c55e]/50 cursor-pointer select-none"
                >
                    {{ digit }}
                </button>

                <button
                    @click="clearPhone"
                    class="h-16 bg-[#0a0a0a] border border-red-500/20 rounded-2xl flex items-center justify-center text-sm font-black text-red-500/50 uppercase transition-all active:scale-95 active:bg-red-500 active:text-black hover:border-red-500/50 cursor-pointer select-none"
                >
                    Сброс
                </button>

                <button
                    @click="appendDigit('0')"
                    class="h-16 bg-[#0a0a0a] border border-[#22c55e]/20 rounded-2xl flex items-center justify-center text-3xl font-mono font-black text-[#22c55e] transition-all active:scale-95 active:bg-[#22c55e] active:text-black hover:border-[#22c55e]/50 cursor-pointer select-none"
                >
                    0
                </button>

                <button
                    @click="removeDigit"
                    class="h-16 bg-[#0a0a0a] border border-yellow-500/20 rounded-2xl flex items-center justify-center text-yellow-500/50 transition-all active:scale-95 active:bg-yellow-500 active:text-black hover:border-yellow-500/50 cursor-pointer select-none"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2M3 12l6.414 6.414a2 2 0 001.414.586H19a2 2 0 002-2V7a2 2 0 00-2-2h-8.172a2 2 0 00-1.414.586L3 12z" />
                    </svg>
                </button>
            </div>

            <div v-if="mode !== 'booking'" class="w-full">
                <button
                    @click="confirmPhone"
                    :disabled="rawPhone.length !== 10"
                    class="w-full h-14 rounded-2xl font-black uppercase tracking-widest text-sm transition-all duration-300 border"
                    :class="rawPhone.length === 10
            ? 'bg-[#22c55e] text-black border-[#22c55e] shadow-[0_0_20px_rgba(34,197,94,0.4)] hover:bg-[#1ea34d] active:scale-95'
            : 'bg-[#0a0a0a] text-white/20 border-white/10 cursor-not-allowed'"
                >
                    Подтвердить
                </button>
            </div>

            <button
                @click="$emit('close')"
                class="mt-6 text-white/20 uppercase text-[9px] tracking-widest hover:text-red-500 transition-colors"
            >
                [ Отмена ]
            </button>

        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';

const props = defineProps({
    isOpen: Boolean,
    mode: String,
    data: Object
});

const emit = defineEmits(['close', 'confirm']);

const rawPhone = ref('');

const appendDigit = (digit) => { if (rawPhone.value.length < 10) rawPhone.value += digit; };
const removeDigit = () => { rawPhone.value = rawPhone.value.slice(0, -1); };
const clearPhone = () => { rawPhone.value = ''; };

const formattedPhone = computed(() => {
    let p = rawPhone.value;
    if (!p) return '';
    let res = '+7 ';
    if (p.length > 0) res += `(${p.slice(0, 3)}`;
    if (p.length > 3) res += `) ${p.slice(3, 6)}`;
    if (p.length > 6) res += `-${p.slice(6, 8)}`;
    if (p.length > 8) res += `-${p.slice(8, 10)}`;
    return res;
});

const confirmPhone = () => {
    if (rawPhone.value.length === 10) {
        emit('confirm', '7' + rawPhone.value);
    }
};

// --- СЛУШАТЕЛЬ ФИЗИЧЕСКОЙ КЛАВИАТУРЫ ---
const handleKeydown = (e) => {
    if (!props.isOpen || props.mode !== 'booking') return;

    if (/^[0-9]$/.test(e.key)) {
        appendDigit(e.key);
    }
    else if (e.key === 'Backspace') {
        removeDigit();
    }
    else if (e.key === 'Escape') {
        emit('close');
    }
    else if (e.key === 'Enter') {
        confirmPhone();
    }
};

onMounted(() => {
    window.addEventListener('keydown', handleKeydown);
});

onUnmounted(() => {
    window.removeEventListener('keydown', handleKeydown);
});

// Авто-сабмит
watch(rawPhone, (newVal) => {
    if (newVal.length === 10 && props.mode === 'booking') {
        setTimeout(() => {
            confirmPhone();
        }, 150);
    }
});

// Сброс номера при закрытии окна
watch(() => props.isOpen, (newVal) => {
    if (!newVal) clearPhone();
});
</script>

<style scoped>
.animate-in { animation-fill-mode: forwards; }
@keyframes zoom-in {
    from { opacity: 0; transform: scale(0.95) translateY(10px); }
    to { opacity: 1; transform: scale(1) translateY(0); }
}
.zoom-in { animation: zoom-in 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
</style>
