<script setup lang="ts">
import { ref, watch } from 'vue'

const props = defineProps<{
    isOpen: boolean
    pcName?: string
    isProcessing?: boolean
}>()

const emit = defineEmits(['close', 'confirm'])

const giftMinutes = ref(15)
const giftReason = ref('Технический сбой ПО')

// Сбрасываем значения при каждом новом открытии модалки
watch(() => props.isOpen, (newVal) => {
    if (newVal) {
        giftMinutes.value = 15
        giftReason.value = 'Технический сбой ПО'
    }
})

const submit = () => {
    if (giftMinutes.value < 1 || giftMinutes.value > 20) {
        return alert('Лимит от 1 до 20 минут!')
    }
    emit('confirm', {
        minutes: giftMinutes.value,
        reason: giftReason.value
    })
}
</script>

<template>
    <Teleport to="body">
        <Transition name="fade">
            <div v-if="isOpen" class="fixed inset-0 flex items-center justify-center z-[99990] p-4">
                <div class="absolute inset-0 bg-black/90 backdrop-blur-md" @click="emit('close')"></div>

                <div class="relative bg-[#0a0a0a] border border-cyan-500/30 p-8 rounded-[14px] w-full max-w-sm shadow-[0_0_50px_rgba(6,182,212,0.15)] animate-in zoom-in-95 duration-200">
                    <div class="flex justify-between items-start mb-6">
                        <div>
                            <h3 class="text-cyan-500 font-black uppercase italic tracking-tighter text-xl">Коррекция сеанса</h3>
                            <p class="text-[10px] text-white/30 uppercase tracking-widest mt-1">Узел #{{ pcName || 'Не выбран' }}</p>
                        </div>
                        <button @click="emit('close')" class="text-white/20 hover:text-white transition-colors">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>

                    <div class="space-y-5">
                        <div>
                            <label class="text-[9px] text-white/40 uppercase font-black tracking-widest flex justify-between mb-2">
                                <span>Количество минут</span>
                                <span class="text-cyan-500">Max 20</span>
                            </label>
                            <input v-model="giftMinutes" type="number" min="1" max="20"
                                   class="w-full bg-black border border-white/10 rounded-xl py-3 px-4 text-white font-black text-lg focus:border-cyan-500 outline-none transition-all" />
                        </div>

                        <div>
                            <label class="text-[9px] text-white/40 uppercase font-black tracking-widest block mb-2">Причина начисления (Аудит)</label>
                            <select v-model="giftReason" class="w-full bg-black border border-white/10 rounded-xl py-3 px-4 text-white/80 text-sm focus:border-cyan-500 outline-none transition-all appearance-none cursor-pointer">
                                <option value="Технический сбой ПО">Технический сбой ПО</option>
                                <option value="Проблема с периферией">Проблема с периферией</option>
                                <option value="Пересадка по вине клуба">Пересадка по вине клуба</option>
                                <option value="Тестовый запуск (Служебное)">Тестовый запуск (Служебное)</option>
                            </select>
                        </div>

                        <button @click="submit" :disabled="isProcessing"
                                class="w-full mt-4 py-4 bg-cyan-500 text-black font-black rounded-xl uppercase text-xs tracking-widest hover:bg-cyan-400 active:scale-95 transition-all disabled:opacity-50 disabled:cursor-not-allowed">
                            {{ isProcessing ? 'Отправка...' : 'Подтвердить' }}
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
