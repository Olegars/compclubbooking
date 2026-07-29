<script setup lang="ts">
import { computed } from 'vue'

export type RoomInfoPayload = {
  title?: string
  color?: string
  kind?: 'pc' | 'tv'
  info?: {
    cpu?: string | null
    gpu?: string | null
    monitor?: string | null
    screen_diagonal?: string | null
    ps_model?: string | null
  } | null
}

const props = withDefaults(defineProps<{
  isOpen: boolean
  room?: RoomInfoPayload | null
}>(), {
  room: null,
})

const emit = defineEmits<{ (e: 'close'): void }>()

const title = computed(() => String(props.room?.title || 'Комната').toUpperCase())
const color = computed(() => props.room?.color || '#22c55e')
const kind = computed(() => props.room?.kind === 'tv' ? 'tv' : 'pc')

const rows = computed(() => {
  const info = props.room?.info || {}
  if (kind.value === 'tv') {
    return [
      { label: 'Диагональ экрана', value: info.screen_diagonal },
      { label: 'Модель PS', value: info.ps_model },
    ].filter(r => String(r.value || '').trim() !== '')
  }
  return [
    { label: 'Процессор', value: info.cpu },
    { label: 'Видеокарта', value: info.gpu },
    { label: 'Монитор', value: info.monitor },
  ].filter(r => String(r.value || '').trim() !== '')
})
</script>

<template>
  <Transition name="modal">
    <div v-if="isOpen" class="fixed inset-0 flex items-center justify-center p-4" style="z-index: 9999999 !important;">
      <div class="absolute inset-0 bg-black/40" @click="emit('close')"></div>

      <div class="relative w-[400px] bg-[#0a0a0a] border border-white/10 rounded-[40px] shadow-[0_0_80px_rgba(0,0,0,1)] overflow-hidden z-[10000000]">
        <div class="h-1" :style="{ backgroundColor: color }"></div>

        <div class="p-8">
          <div class="flex justify-between items-start mb-6">
            <div>
              <h3 class="text-2xl font-black italic tracking-tighter uppercase" :style="{ color }">
                {{ title }}
              </h3>
              <p class="text-white/40 text-[10px] uppercase font-bold tracking-widest mt-1">
                {{ kind === 'tv' ? 'Конфигурация ТВ' : 'Техническая спецификация' }}
              </p>
            </div>
            <button type="button" @click="emit('close')" class="text-white/20 hover:text-white transition-all text-3xl leading-none">&times;</button>
          </div>

          <div v-if="rows.length" class="space-y-4">
            <div v-for="spec in rows" :key="spec.label" class="border-b border-white/5 pb-3">
              <span class="text-[9px] text-slate-500 font-black uppercase tracking-widest block mb-1">{{ spec.label }}</span>
              <span class="text-white font-mono text-sm italic font-bold">{{ spec.value }}</span>
            </div>
          </div>
          <p v-else class="text-white/40 text-sm italic">Конфигурация пока не задана.</p>

          <button type="button" @click="emit('close')" class="w-full mt-10 p-4 border rounded-2xl text-[10px] font-black uppercase tracking-widest transition-all hover:bg-white hover:text-black" :style="{ borderColor: color + '44' }">
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
