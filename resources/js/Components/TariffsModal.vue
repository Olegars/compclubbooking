<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import axios from 'axios'

const props = defineProps<{
  isOpen: boolean
  showcase?: {
    rates?: Array<{ zone: string; slug?: string; price: string; color: string }>
    packages?: Array<{ id?: number; name: string; discount: string; hours?: number; cost?: number; category?: string }>
  } | null
}>()
const emit = defineEmits(['close'])

const rates = ref(props.showcase?.rates ?? [])
const packages = ref(props.showcase?.packages ?? [])
const loading = ref(false)

const loadTariffs = async () => {
  if ((rates.value?.length || 0) > 0 || (packages.value?.length || 0) > 0) return
  loading.value = true
  try {
    const { data } = await axios.get('/api/booking/tariffs')
    rates.value = Array.isArray(data?.rates) ? data.rates : []
    packages.value = Array.isArray(data?.packages) ? data.packages : []
  } catch (e) {
    console.error('Не удалось загрузить тарифы', e)
  } finally {
    loading.value = false
  }
}

watch(() => props.showcase, (value) => {
  if (value?.rates) rates.value = value.rates
  if (value?.packages) packages.value = value.packages
}, { deep: true })

watch(() => props.isOpen, (open) => {
  if (open) loadTariffs()
})

onMounted(() => {
  if (props.isOpen) loadTariffs()
})

const hasRates = computed(() => rates.value.length > 0)
const hasPackages = computed(() => packages.value.length > 0)
</script>

<template>
  <Transition name="modal">
    <div v-if="isOpen" class="fixed inset-0 flex items-center justify-center p-4" style="z-index: 9999999 !important;">
      <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="emit('close')"></div>

      <div class="relative w-[440px] bg-[#0a0a0a] border border-white/10 rounded-[40px] shadow-[0_0_80px_rgba(0,0,0,1)] overflow-hidden z-[10000000]">
        <div class="h-1 bg-gradient-to-r from-transparent via-[#22c55e] to-transparent"></div>

        <div class="p-8">
          <div class="flex justify-between items-start mb-6 border-b border-white/5 pb-4">
            <div>
              <h3 class="text-[#22c55e] text-2xl font-black italic tracking-tighter uppercase">
                Тарифная сетка
              </h3>
              <p class="text-white/40 text-[10px] uppercase font-bold tracking-widest mt-1">Базовые цены и пакеты</p>
            </div>
            <button @click="emit('close')" class="text-white/20 hover:text-white transition-all text-3xl leading-none">&times;</button>
          </div>

          <div class="mb-8">
            <span class="text-[10px] text-slate-500 font-black uppercase tracking-widest block mb-4 italic">Почасовая оплата (за 1 место)</span>
            <div v-if="loading && !hasRates" class="text-white/30 text-xs uppercase tracking-widest py-4">Загрузка...</div>
            <div v-else-if="!hasRates" class="text-white/30 text-xs uppercase tracking-widest py-4">Тарифы пока не заданы</div>
            <div v-else class="space-y-2">
              <div v-for="rate in rates" :key="rate.slug || rate.zone" class="flex justify-between items-end bg-white/[0.02] p-3 rounded-xl border border-white/5">
                <div class="flex items-center gap-2">
                  <div class="w-2 h-2 rounded-full" :style="{ backgroundColor: rate.color, boxShadow: `0 0 8px ${rate.color}` }"></div>
                  <span class="text-white font-bold font-mono text-sm uppercase">{{ rate.zone }}</span>
                </div>
                <div class="flex items-baseline gap-1">
                  <span class="text-[#22c55e] font-black italic text-xl leading-none">{{ rate.price }}</span>
                  <span class="text-slate-500 text-[9px] uppercase font-black tracking-widest">руб/ч</span>
                </div>
              </div>
            </div>
          </div>

          <div>
            <span class="text-[10px] text-slate-500 font-black uppercase tracking-widest block mb-4 italic">Пакетные предложения</span>
            <div v-if="!hasPackages" class="text-white/30 text-xs uppercase tracking-widest py-2">Нет активных пакетов</div>
            <div v-else class="grid grid-cols-1 gap-2">
              <div v-for="pkg in packages" :key="pkg.id || pkg.name" class="flex justify-between items-center border border-white/10 rounded-xl p-3 bg-black">
                <div class="flex flex-col gap-0.5">
                  <span class="text-white font-black text-xs tracking-widest uppercase">{{ pkg.name }}</span>
                  <span v-if="pkg.cost != null" class="text-white/40 text-[9px] font-bold uppercase tracking-widest">{{ Math.round(Number(pkg.cost)) }} ₽ · {{ pkg.hours }}ч</span>
                </div>
                <span class="text-[#22c55e] text-[10px] font-bold uppercase tracking-widest px-2 py-1 bg-[#22c55e]/10 rounded-md">{{ pkg.discount }}</span>
              </div>
            </div>
          </div>

          <button @click="emit('close')" class="w-full mt-8 p-4 bg-white/5 border border-white/10 rounded-2xl text-[10px] text-white font-black uppercase tracking-widest transition-all hover:bg-[#22c55e] hover:text-black hover:border-[#22c55e] shadow-lg">
            Закрыть
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
