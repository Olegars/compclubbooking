<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'

const props = defineProps<{
    isOpen: boolean
    mode: 'auth' | 'booking'
    isTerminal?: boolean // true = с кнопками (клуб), false = только клавиатура (сайт)
    data?: any
}>()

const emit = defineEmits(['close', 'confirm'])
const rawPhone = ref('')
const page = usePage()

// Проверяем, авторизован ли пользователь
const isLoggedIn = computed(() => !!(page.props.auth?.user || page.props.user))

// ЛОГИКА: Нужен ли телефон?
const requiresPhone = computed(() => {
    if (props.isTerminal) return true
    if (props.mode === 'auth') return true
    if (props.mode === 'booking' && isLoggedIn.value) return false
    return true
})

const formattedPhone = computed(() => {
    let val = rawPhone.value
    let res = '+7 ('
    for (let i = 0; i < 10; i++) {
        if (i === 3) res += ') '
        if (i === 6) res += '-'
        if (i === 8) res += '-'
        res += val[i] || '_'
    }
    return res
})

const addDigit = (digit: string | number) => {
    if (!requiresPhone.value) return
    if (rawPhone.value.length < 10) rawPhone.value += digit.toString()
}

const backspace = () => {
    if (!requiresPhone.value) return
    rawPhone.value = rawPhone.value.slice(0, -1)
}

const clearPhone = () => {
    rawPhone.value = ''
}

// --- РУЧНОЙ КОНТРОЛЬ ВМЕСТО АВТО-САБМИТА ---
const submitPhone = () => {
    if (rawPhone.value.length === 10) {
        emit('confirm', { phone: '7' + rawPhone.value })
    }
}

const confirmWithoutPhone = () => {
    emit('confirm', { status: 'confirmed' })
}

// Сброс при закрытии
watch(() => props.isOpen, (newVal) => {
    if (!newVal) clearPhone()
})

const handleKeydown = (e: KeyboardEvent) => {
    if (!props.isOpen || !requiresPhone.value) return
    if (/^\d$/.test(e.key)) addDigit(e.key)
    if (e.key === 'Backspace') backspace()
    if (e.key === 'Enter') submitPhone() // <-- Теперь можно подтвердить по Enter
    if (e.key === 'Escape') emit('close')
}

onMounted(() => window.addEventListener('keydown', handleKeydown))
onUnmounted(() => window.removeEventListener('keydown', handleKeydown))
</script>

<template>
    <div v-if="isOpen" class="fixed inset-0 flex items-center justify-center z-[9999999] p-4 pointer-events-none">
        <div class="absolute inset-0 bg-black/80 backdrop-blur-sm pointer-events-auto" @click="$emit('close')"></div>
        <div class="w-full max-w-md bg-[#050505] border-2 border-[#22c55e]/30 rounded-[3rem] p-8 relative shadow-[0_0_100px_rgba(34,197,94,0.3)] pointer-events-auto z-10">

            <div v-if="mode === 'booking' && data" class="mb-6 p-4 bg-[#22c55e]/5 border border-[#22c55e]/10 rounded-2xl text-center">
                <div class="text-[10px] text-white/40 uppercase mb-1">Сумма к оплате</div>
                <div class="text-2xl font-black text-[#22c55e]">{{ data.price }} ₽</div>
                <div v-if="data.date" class="text-[10px] text-white/40 uppercase mt-2">{{ data.date }} | {{ data.startTime }} - {{ data.endTime }}</div>
            </div>

            <div class="text-center mb-8">
                <h2 class="text-[#22c55e] text-2xl font-black uppercase italic tracking-tighter mb-1">
                    {{ mode === 'auth' ? 'Вход в систему' : 'Подтверждение' }}
                </h2>
                <p v-if="requiresPhone" class="text-white/20 text-[9px] uppercase tracking-widest font-bold">Введите номер телефона</p>
                <p v-else class="text-white/20 text-[9px] uppercase tracking-widest font-bold">Подтвердите оплату бронирования</p>
            </div>

            <template v-if="requiresPhone">
                <div class="bg-black border border-[#22c55e]/20 rounded-2xl py-6 px-2 sm:px-4 mb-6 shadow-inner overflow-hidden">
                    <div class="text-center font-mono text-2xl sm:text-[28px] font-black tracking-wider whitespace-nowrap transition-colors"
                         :class="rawPhone.length === 10 ? 'text-[#22c55e]' : 'text-white/80'">
                        {{ formattedPhone }}
                    </div>
                </div>

                <div v-if="!isTerminal" class="flex justify-center gap-1.5 mb-8">
                    <div v-for="i in 10" :key="i"
                         class="w-3 h-1.5 rounded-full transition-all duration-300"
                         :class="i <= rawPhone.length ? 'bg-[#22c55e] shadow-[0_0_8px_#22c55e]' : 'bg-white/5'">
                    </div>
                </div>

                <div v-if="isTerminal" class="grid grid-cols-3 gap-3 mb-8">
                    <button v-for="n in 9" :key="n" @click="addDigit(n)"
                            class="h-16 bg-white/5 border border-white/10 rounded-2xl text-2xl font-black text-[#22c55e] active:bg-[#22c55e] active:text-black transition-all">
                        {{ n }}
                    </button>
                    <button @click="clearPhone" class="h-14 text-red-500 text-[10px] font-black uppercase">Сброс</button>
                    <button @click="addDigit(0)" class="h-16 bg-white/5 border border-white/10 rounded-2xl text-2xl font-black text-[#22c55e] active:bg-[#22c55e] active:text-black transition-all">0</button>
                    <button @click="backspace" class="h-16 flex items-center justify-center text-orange-500">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2M3 12l6.41-6.41A2 2 0 0110.83 5H21a2 2 0 012 2v10a2 2 0 01-2 2H10.83a2 2 0 01-1.42-.59L3 12z"/></svg>
                    </button>
                </div>

                <button v-if="rawPhone.length === 10" @click="submitPhone"
                        class="w-full py-5 mb-4 bg-[#22c55e] hover:bg-[#2ae06d] rounded-2xl text-black font-black uppercase tracking-widest active:scale-95 transition-all shadow-[0_0_20px_rgba(34,197,94,0.3)] italic animate-in zoom-in duration-300">
                    Отправить SMS код
                </button>
            </template>

            <template v-else>
                <button @click="confirmWithoutPhone"
                        class="w-full py-5 mb-6 bg-[#22c55e] hover:bg-[#2ae06d] rounded-2xl text-black font-black uppercase tracking-widest active:scale-95 transition-all shadow-[0_0_20px_rgba(34,197,94,0.2)] italic">
                    Оплатить и Играть
                </button>
            </template>

            <button @click="$emit('close')"
                    class="w-full py-4 text-white/20 hover:text-white uppercase text-[10px] font-black tracking-[0.4em] transition-all">
                [ Отмена ]
            </button>

            <div class="absolute top-6 left-6 w-4 h-4 border-t-2 border-l-2 border-[#22c55e]/20"></div>
            <div class="absolute bottom-6 right-6 w-4 h-4 border-b-2 border-r-2 border-[#22c55e]/20"></div>
        </div>
    </div>
</template>

<style scoped>
@reference "../../css/app.css";
.font-mono { text-shadow: 0 0 10px rgba(34, 197, 94, 0); transition: text-shadow 0.3s; }
.text-[#22c55e] { text-shadow: 0 0 10px rgba(34, 197, 94, 0.4); }

/* Плавное появление кнопки */
.animate-in { animation: zoom-in 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
@keyframes zoom-in {
    from { opacity: 0; transform: translateY(10px) scale(0.95); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}
</style>
