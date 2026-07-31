<script setup lang="ts">
const props = defineProps<{ isOpen: boolean; data: any }>()
const emit = defineEmits(['close'])
</script>

<template>
  <Transition name="modal">
    <div v-if="isOpen" class="fixed inset-0 flex items-center justify-center p-4" style="z-index: 9999999 !important;">

      <div class="absolute inset-0" @click="emit('close')"></div>

      <div class="relative w-[440px] max-w-full bg-[#0a0a0a] border border-white/10 rounded-[16px] shadow-[0_0_150px_rgba(34,197,94,0.15)] overflow-hidden flex flex-col z-[10000000]">
        <div class="shrink-0 h-1 bg-gradient-to-r from-transparent via-[#22c55e] to-transparent"></div>

        <div class="p-10 flex flex-col items-center text-center">
          <div class="w-24 h-24 rounded-full border border-[#22c55e]/30 bg-[#22c55e]/10 flex items-center justify-center mb-6 relative">
            <div class="absolute inset-0 rounded-full border border-[#22c55e] animate-ping opacity-20"></div>
            <span class="text-[#22c55e] text-4xl font-light">✓</span>
          </div>

          <h3 class="text-[#22c55e] text-2xl font-black uppercase italic tracking-widest mb-2">Access Granted</h3>
          <p class="text-[10px] text-white/40 uppercase tracking-[0.3em] font-black mb-8">Платеж успешно обработан</p>

          <div class="w-full bg-white/[0.03] border border-white/5 rounded-3xl p-6 mb-8 font-mono italic text-sm text-left shadow-inner">
            <div class="flex justify-between items-center mb-3">
              <span class="text-slate-500 font-black uppercase text-[10px]">Node</span>
              <span class="text-white font-black">{{ data?.pcNumber }}</span>
            </div>
            <div class="flex justify-between items-center mb-3">
              <span class="text-slate-500 font-black uppercase text-[10px]">Дата</span>
              <span class="text-white font-black">{{ data?.date }}</span>
            </div>
            <div class="flex justify-between items-center border-t border-white/5 pt-3">
              <span class="text-slate-500 font-black uppercase text-[10px]">Сеанс</span>
              <span class="text-[#22c55e] font-black">{{ data?.startTime }} — {{ data?.endTime }}</span>
            </div>
          </div>

          <button
            @click="emit('close')"
            class="w-full bg-[#22c55e] hover:bg-[#2ae06d] text-black font-black p-6 rounded-[0.875rem] transition-all active:scale-[0.98] uppercase italic tracking-widest text-xs shadow-[0_0_30px_rgba(34,197,94,0.3)]"
          >
            Завершить
          </button>
        </div>
      </div>
    </div>
  </Transition>
</template>

<style scoped>
.modal-enter-active { transition: all 0.5s cubic-bezier(0.16, 1, 0.3, 1); }
.modal-leave-active { transition: all 0.3s ease-in; }
.modal-enter-from, .modal-leave-to { opacity: 0; transform: scale(0.9) translateY(20px); }
</style>
