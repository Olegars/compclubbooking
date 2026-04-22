<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'

// Состояние компьютеров (подтягиваем из Gizmo + REACTOR DB)
const computers = ref([
    { id: 1, name: '01', x: 100, y: 100, status: 'occupied', user: 'Oleg_NV', timeLeft: '01:42', zone: 'VIP' },
    { id: 2, name: '02', x: 160, y: 100, status: 'free', user: null, timeLeft: null, zone: 'VIP' },
    // ... остальные ПК
])

const selectedPc = ref<any>(null)
const isGiftModalOpen = ref(false)
const giftMinutes = ref(15)

const openControl = (pc: any) => {
    selectedPc.value = pc
}

const sendGiftTime = async () => {
    try {
        await axios.post('/api/admin/gift-time', {
            user_id: selectedPc.value.userId,
            pc_id: selectedPc.value.id,
            minutes: giftMinutes.value,
            reason: 'Технический сбой'
        })
        alert('Время начислено. Лог создан.')
        isGiftModalOpen.value = false
    } catch (e: any) {
        alert(e.response.data.message || 'Ошибка лимита!')
    }
}
</script>

<template>
    <div class="relative w-full h-[600px] bg-[#050505] rounded-[40px] border border-white/5 overflow-hidden shadow-2xl">
        <div class="absolute inset-0 opacity-10 bg-[url('https://www.transparenttextures.com/patterns/carbon-fibre.png')]"></div>

        <div v-for="pc in computers" :key="pc.id"
             @click="openControl(pc)"
             class="absolute w-12 h-12 rounded-xl border-2 cursor-pointer transition-all duration-300 flex items-center justify-center font-black text-[10px] group"
             :style="{ left: pc.x + 'px', top: pc.y + 'px' }"
             :class="[
           pc.status === 'occupied' ? 'border-cyan-500 bg-cyan-500/20 text-cyan-500 shadow-[0_0_15px_rgba(6,182,212,0.3)]' :
           pc.status === 'error' ? 'border-red-500 bg-red-500/20 text-red-500' : 'border-white/10 bg-white/5 text-white/20'
         ]">
            {{ pc.name }}

            <div class="absolute bottom-full mb-2 hidden group-hover:block z-50 w-32 bg-black border border-white/10 p-2 rounded-lg shadow-xl text-[9px] pointer-events-none">
                <div v-if="pc.user" class="text-cyan-500 uppercase">{{ pc.user }}</div>
                <div v-if="pc.timeLeft" class="text-white">Осталось: {{ pc.timeLeft }}</div>
                <div class="text-white/40 uppercase">{{ pc.zone }}</div>
            </div>
        </div>

        <Transition name="slide-up">
            <div v-if="selectedPc" class="absolute bottom-0 left-0 right-0 bg-black/80 backdrop-blur-xl border-t border-cyan-500/30 p-6 flex justify-between items-center">
                <div>
                    <span class="text-[10px] text-cyan-500 font-black uppercase tracking-widest">Управление узлом</span>
                    <div class="text-2xl font-black text-white italic uppercase">Компьютер #{{ selectedPc.name }} — {{ selectedPc.user || 'СВОБОДЕН' }}</div>
                </div>

                <div class="flex gap-3">
                    <button v-if="selectedPc.status === 'occupied'"
                            @click="isGiftModalOpen = true"
                            class="px-6 py-3 bg-cyan-500 text-black font-black rounded-xl uppercase text-[10px] tracking-widest hover:bg-cyan-400 transition-all">
                        Добавить время
                    </button>
                    <button class="px-6 py-3 bg-white/5 border border-white/10 text-white font-black rounded-xl uppercase text-[10px] tracking-widest hover:bg-red-500/20 hover:border-red-500/50 transition-all">
                        Перезагрузить
                    </button>
                    <button @click="selectedPc = null" class="px-4 py-3 text-white/20 font-black">X</button>
                </div>
            </div>
        </Transition>
    </div>
</template>
