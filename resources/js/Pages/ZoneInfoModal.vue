<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{ isOpen: boolean; zoneId: string }>()
const emit = defineEmits(['close'])

const zoneData: Record<string, any> = {
  'PRO': {
    title: 'PRO ZONE',
    desc: 'Максимальный уровень производительности для киберспортсменов.',
    specs: [
      { label: 'Монитор', value: 'ZOWIE XL2566K 540Hz' },
      { label: 'Видеокарта', value: 'NVIDIA RTX 5090 (24GB)' },
      { label: 'Процессор', value: 'Intel Core i9-14900KF' },
      { label: 'Периферия', value: 'Wooting 60HE / Logitech G Pro X 2' }
    ],
    color: '#ff0000'
  },
  'BOOTCAMP': {
    title: 'BOOTCAMP',
    desc: 'Командная зона для интенсивных тренировок и праков.',
    specs: [
      { label: 'Монитор', value: 'ASUS ROG 360Hz' },
      { label: 'Видеокарта', value: 'NVIDIA RTX 5080 (16GB)' },
      { label: 'Процессор', value: 'Intel Core i7-14700KF' },
      { label: 'Периферия', value: 'Logitech G Pro Series' }
    ],
    color: '#00ff00'
  },
  'SINGLE': {
    title: 'SINGLE AREA',
    desc: 'Комфортные одиночные места для полного погружения в игру.',
    specs: [
      { label: 'Монитор', value: 'LG UltraGear 240Hz 2K' },
      { label: 'Видеокарта', value: 'NVIDIA RTX 4070 Ti SUPER' },
      { label: 'Процессор', value: 'Intel Core i5-13600KF' },
      { label: 'Периферия', value: 'HyperX / Razer Custom' }
    ],
    color: '#ffff00'
  },
  'DUO': {
    title: 'DUO BOOTH',
    desc: 'Изолированные парные места для игры с другом без лишнего шума.',
    specs: [
      { label: 'Особенности', value: 'Шумоизоляция / Парные столы' },
      { label: 'Железо', value: 'RTX 4070 Ti / i5-13600K' },
      { label: 'Мониторы', value: '27" 240Hz 2K' }
    ],
    color: '#3b82f6'
  },
  'TRIO': {
    title: 'TRIO ROOM',
    desc: 'Закрытая зона для троих. Идеально для рейтинга или турниров.',
    specs: [
      { label: 'Особенности', value: 'Private Space / Кондиционер' },
      { label: 'Железо', value: 'RTX 4070 Ti / i5-13600K' },
      { label: 'Мониторы', value: '27" 240Hz 2K' }
    ],
    color: '#ff9900'
  }
}

const current = computed(() => zoneData[props.zoneId] || zoneData['SINGLE'])
</script>

<template>
  <Transition name="modal">
    <div v-if="isOpen" class="fixed inset-0 flex items-center justify-center p-4" style="z-index: 9999999 !important;">
      <div class="absolute inset-0 bg-black/40" @click="emit('close')"></div>

      <div class="relative w-[400px] bg-[#0a0a0a] border border-white/10 rounded-[40px] shadow-[0_0_80px_rgba(0,0,0,1)] overflow-hidden z-[10000000]">
        <div class="h-1" :style="{ backgroundColor: current.color }"></div>

        <div class="p-8">
          <div class="flex justify-between items-start mb-6">
            <div>
              <h3 class="text-2xl font-black italic tracking-tighter uppercase" :style="{ color: current.color }">
                {{ current.title }}
              </h3>
              <p class="text-white/40 text-[10px] uppercase font-bold tracking-widest mt-1">Техническая спецификация</p>
            </div>
            <button @click="emit('close')" class="text-white/20 hover:text-white transition-all text-3xl leading-none">&times;</button>
          </div>

          <p class="text-white/70 text-sm italic mb-8 leading-relaxed">{{ current.desc }}</p>

          <div class="space-y-4">
            <div v-for="spec in current.specs" :key="spec.label" class="border-b border-white/5 pb-3">
              <span class="text-[9px] text-slate-500 font-black uppercase tracking-widest block mb-1">{{ spec.label }}</span>
              <span class="text-white font-mono text-sm italic font-bold">{{ spec.value }}</span>
            </div>
          </div>

          <button @click="emit('close')" class="w-full mt-10 p-4 border rounded-2xl text-[10px] font-black uppercase tracking-widest transition-all hover:bg-white hover:text-black" :style="{ borderColor: current.color + '44' }">
            Понятно
          </button>
        </div>
      </div>
    </div>
  </Transition>
</template>

<style scoped>
.modal-enter-active, .modal-leave-active { transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
.modal-enter-from, .modal-leave-to { opacity: 0; transform: scale(0.95) translateY(10px); }
</style>
