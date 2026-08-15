<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useClubName } from '@/Composables/useClubName'

const props = withDefaults(defineProps<{
  isOpen: boolean;
  method?: string;
  mode?: 'booking' | 'topup' | 'view';
  data: any;
}>(), {
  mode: 'booking'
})

const emit = defineEmits(['close'])
const clubName = useClubName()

const status = ref<'processing' | 'success'>('processing')
const progress = ref(0)

const bookingGames = computed(() => {
  const games = props.data?.games
  if (!Array.isArray(games) || !games.length) return [] as string[]
  return games
    .map((game: any) => (typeof game === 'string' ? game : game?.title))
    .filter(Boolean)
})

watch(() => props.isOpen, (val) => {
  if (val) {
    status.value = 'processing'; progress.value = 0
    const int = setInterval(() => {
      progress.value += 10;
      if (progress.value >= 100) clearInterval(int)
    }, 150)
    setTimeout(() => { status.value = 'success' }, 2000)
  }
}, { immediate: true })
</script>

<template>
  <Transition name="fade">
    <div v-if="isOpen" class="fixed inset-0 flex items-center justify-center p-4" style="z-index: 9999999 !important;">
      <div class="absolute inset-0 bg-black/90 backdrop-blur-xl"></div>

      <div class="relative w-full max-w-md bg-[#0a0a0a] border border-white/5 rounded-[16px] overflow-hidden shadow-[0_0_100px_rgba(0,0,0,0.8)] text-center">

        <div class="h-1 w-full bg-white/5">
          <div class="h-full bg-[#22c55e] transition-all duration-300" :style="{ width: progress + '%' }"></div>
        </div>

        <div class="p-10">
          <div v-if="status === 'processing'" class="py-10">
            <div class="w-16 h-16 border-4 border-[#22c55e]/20 border-t-[#22c55e] rounded-full animate-spin mx-auto mb-8"></div>
            <h3 class="text-lg font-black text-[#22c55e] uppercase italic tracking-widest animate-pulse">
              {{ mode === 'view' ? 'Синхронизация данных...' : (mode === 'topup' ? 'Авторизация платежа...' : 'Резервирование узла...') }}
            </h3>
          </div>

          <div v-else class="animate-in zoom-in duration-500 fade-in">
            <div class="w-20 h-20 bg-[#22c55e]/10 border border-[#22c55e]/30 rounded-full flex items-center justify-center mx-auto mb-6 shadow-[0_0_30px_rgba(34,197,94,0.2)]">
              <svg class="w-10 h-10 text-[#22c55e]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
              </svg>
            </div>

            <h3 class="text-3xl font-black text-[#22c55e] mb-2 uppercase italic tracking-widest drop-shadow-[0_0_10px_rgba(34,197,94,0.5)]">
              ACCESS GRANTED
            </h3>

            <p class="text-[10px] text-slate-500 font-black uppercase tracking-[0.2em] mb-8 italic">
              {{ mode === 'view' ? 'ЗАПРОС БАЛАНСА ВЫПОЛНЕН' : (mode === 'topup' ? 'БАЛАНС УСПЕШНО ПОПОЛНЕН' : 'СЕАНС ЗАРЕЗЕРВИРОВАН') }}
            </p>

            <div class="bg-[#050505] border border-white/5 p-6 rounded-3xl mb-8 text-left font-mono shadow-inner relative overflow-hidden italic">
              <div class="absolute left-0 top-0 bottom-0 w-1 bg-[#22c55e]/30"></div>

              <div class="space-y-4">
                <div class="flex justify-between items-center border-b border-white/5 pb-3 gap-3">
                  <span class="text-[9px] text-slate-600 uppercase tracking-widest font-black shrink-0">ОБЪЕКТ</span>
                  <span class="text-white text-sm font-black uppercase leading-none italic text-right">
                    {{ mode === 'view' ? 'СТАТУС: БАЛАНС' : (mode === 'topup' ? `СЧЕТ: ${clubName}` : data?.pcNumber) }}
                  </span>
                </div>

                <div v-if="mode === 'booking' && bookingGames.length" class="flex justify-between items-start border-b border-white/5 pb-3 gap-3">
                  <span class="text-[9px] text-slate-600 uppercase tracking-widest font-black shrink-0">ИГРЫ</span>
                  <span class="text-white text-sm font-black uppercase leading-snug italic text-right">
                    {{ bookingGames.join(', ') }}
                  </span>
                </div>

                <div class="flex justify-between items-center border-b border-white/5 pb-3">
                  <span class="text-[9px] text-slate-600 uppercase tracking-widest font-black">ДАТА</span>
                  <span class="text-white font-black text-sm">{{ data?.date }}</span>
                </div>

                <div class="flex justify-between items-center">
                  <span class="text-[9px] text-slate-600 uppercase tracking-widest font-black italic">
                    {{ mode === 'view' ? 'ТЕКУЩИЙ ОСТАТОК' : 'СУММА ОПЕРАЦИИ' }}
                  </span>
                  <span class="text-[#22c55e] text-sm font-black uppercase leading-none italic">
                    {{ mode === 'view' ? '1 250' : data?.price }} РУБ
                  </span>
                </div>
              </div>
            </div>

            <button @click="emit('close')" class="group w-full py-5 bg-[#22c55e] hover:bg-[#2ae06d] rounded-2xl text-black font-black uppercase tracking-widest active:scale-95 transition-all shadow-[0_0_20px_rgba(34,197,94,0.2)] italic">
              Завершить протокол
            </button>
          </div>
        </div>
      </div>
    </div>
  </Transition>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.3s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
