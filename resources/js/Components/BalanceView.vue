<script setup lang="ts">
import { ref, computed } from 'vue'
import ConfirmModal from './ConfirmModal.vue'
import SmsModal from './SmsModal.vue'
import PaymentModal from './PaymentModal.vue' // Заменили SuccessModal на PaymentModal

const amount = ref('')
const paymentMethod = ref<'card' | 'sbp' | null>(null)
const currentMode = ref<'topup' | 'view'>('topup')

// --- СОСТОЯНИЯ ОКОН ---
const showOverlay = ref(false)
const isConfirmModalOpen = ref(false)
const isSmsModalOpen = ref(false)
const isSuccessModalOpen = ref(false)
const userPhone = ref('')

const setAmount = (val: number) => {
  amount.value = val.toString()
}

const openModal = (mode: 'topup' | 'view') => {
  currentMode.value = mode
  showOverlay.value = true
  isConfirmModalOpen.value = true
}

const closeAll = () => {
  showOverlay.value = false
  isConfirmModalOpen.value = false
  isSmsModalOpen.value = false
  isSuccessModalOpen.value = false
}

const handleConfirm = (payload: any) => {
  userPhone.value = payload.phone
  isConfirmModalOpen.value = false
  setTimeout(() => { isSmsModalOpen.value = true }, 200)
}

const handleSmsVerify = () => {
  isSmsModalOpen.value = false
  setTimeout(() => { isSuccessModalOpen.value = true }, 200)
}

const handleSuccessClose = () => {
  closeAll()
  if (currentMode.value === 'topup') {
    amount.value = ''
    paymentMethod.value = null
  }
}

const modalData = computed(() => {
  const today = new Date().toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' }).replace('.', '').toUpperCase()
  return {
    date: today,
    price: currentMode.value === 'topup' ? (amount.value || '0') : '1250',
    pcNumber: currentMode.value === 'topup' ? 'СЧЕТ: REACTOR' : 'СТАТУС: БАЛАНС'
  }
})
</script>

<template>
  <div class="max-w-[700px] mx-auto flex flex-col h-[720px] bg-[#050505] relative rounded-3xl border border-[#22c55e]/30 p-8 overflow-y-auto no-scrollbar shadow-[0_0_40px_rgba(34,197,94,0.15)] select-none">

    <div class="mb-8 flex justify-between items-end border-b border-[#22c55e]/10 pb-5 shrink-0">
      <div class="flex flex-col">
        <h3 class="text-[#22c55e] text-2xl font-black uppercase italic tracking-widest leading-none mb-2">Управление счетом</h3>
        <span class="text-[10px] text-slate-500 font-black uppercase tracking-[0.2em]">Финансовый шлюз REACTOR</span>
      </div>
      <div class="font-mono text-[10px] text-[#22c55e] flex items-center gap-2 px-3 py-1.5 bg-[#22c55e]/5 rounded-full border border-[#22c55e]/20 shadow-inner">
        <span class="w-1.5 h-1.5 bg-[#22c55e] rounded-full animate-pulse"></span>
        СЕТЬ АКТИВНА
      </div>
    </div>

    <div class="mb-8 shrink-0">
      <label class="block text-[10px] text-slate-500 font-black uppercase mb-3 tracking-widest italic ml-1">Сумма пополнения (РУБ)</label>
      <div class="relative group mb-3">
        <input v-model="amount" type="number" placeholder="0" class="w-full bg-[#0a0a0a] border border-white/5 rounded-2xl p-6 pl-8 text-white font-mono text-4xl focus:border-[#22c55e] focus:shadow-[0_0_30px_rgba(34,197,94,0.1)] outline-none transition-all placeholder:text-white/10 shadow-inner" />
        <div class="absolute right-8 top-1/2 -translate-y-1/2 text-white/20 font-black italic tracking-widest text-xl pointer-events-none group-focus-within:text-[#22c55e] transition-colors">RUB</div>
      </div>
      <div class="flex gap-2">
        <button v-for="val in [500, 1000, 2000, 5000]" :key="val" @click="setAmount(val)" class="flex-1 bg-white/[0.03] hover:bg-[#22c55e]/10 border border-white/5 hover:border-[#22c55e]/40 text-white/40 hover:text-[#22c55e] font-mono font-black py-3 rounded-xl transition-all active:scale-95 text-lg relative overflow-hidden">
          {{ val }}
          <div v-if="val >= 2000" class="absolute top-0 right-0 bg-[#22c55e] text-black text-[6px] font-black italic px-1.5 py-0.5 rounded-bl-md">+БОНУС</div>
        </button>
      </div>
    </div>

    <div class="mb-8 shrink-0">
      <label class="block text-[10px] text-slate-500 font-black uppercase mb-3 tracking-widest italic ml-1">Метод оплаты</label>
      <div class="grid grid-cols-2 gap-4">
        <button @click="paymentMethod = 'sbp'" :class="['group relative bg-[#0a0a0a] border rounded-2xl p-5 overflow-hidden flex flex-col items-center justify-center gap-3 transition-all active:scale-95', paymentMethod === 'sbp' ? 'border-[#22c55e] bg-[#22c55e]/5' : 'border-white/5 hover:border-white/20']">
          <svg class="w-7 h-7" :class="paymentMethod === 'sbp' ? 'text-[#22c55e]' : 'text-white/20'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <rect x="2" y="2" width="8" height="8" rx="1" /><rect x="14" y="2" width="8" height="8" rx="1" /><rect x="2" y="14" width="8" height="8" rx="1" /><path d="M14 14h2m4 0h2M14 18h2m0 4h2M20 18h2" />
          </svg>
          <span class="block font-black uppercase tracking-widest text-sm italic" :class="paymentMethod === 'sbp' ? 'text-[#22c55e]' : 'text-white'">СБП</span>
        </button>
        <button @click="paymentMethod = 'card'" :class="['group relative bg-[#0a0a0a] border rounded-2xl p-5 overflow-hidden flex flex-col items-center justify-center gap-3 transition-all active:scale-95', paymentMethod === 'card' ? 'border-[#22c55e] bg-[#22c55e]/5' : 'border-white/5 hover:border-white/20']">
          <svg class="w-7 h-7" :class="paymentMethod === 'card' ? 'text-[#22c55e]' : 'text-white/20'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <rect x="2" y="5" width="20" height="14" rx="2" /><path d="M2 10h20M7 15h2" />
          </svg>
          <span class="block font-black uppercase tracking-widest text-sm italic" :class="paymentMethod === 'card' ? 'text-[#22c55e]' : 'text-white'">Карта</span>
        </button>
      </div>
    </div>

    <div class="mt-auto flex flex-row gap-4">
      <button @click="openModal('topup')" :disabled="!amount || !paymentMethod" class="group flex-1 p-1 bg-[#22c55e] rounded-2xl active:scale-95 disabled:opacity-20 disabled:grayscale disabled:cursor-not-allowed">
        <div class="bg-[#0a0a0a] rounded-xl p-4 flex flex-col justify-center items-center h-full border border-white/10 group-hover:bg-transparent transition-all">
          <span class="font-black uppercase text-[11px] text-white group-hover:text-black italic tracking-widest">ПОПОЛНИТЬ</span>
          <div v-if="amount" class="flex items-baseline gap-1 mt-1 text-[#22c55e] group-hover:text-black leading-none font-black italic">
            <span class="text-3xl tracking-tighter">{{ amount }}</span><span class="text-[9px] uppercase">РУБ</span>
          </div>
        </div>
      </button>
      <button @click="openModal('view')" class="group flex-1 p-1 bg-white/5 hover:bg-[#22c55e] rounded-2xl active:scale-95">
        <div class="bg-[#0a0a0a] rounded-xl p-4 flex flex-col justify-center items-center h-full border border-white/5 group-hover:border-transparent transition-all">
          <span class="font-black uppercase text-[11px] text-white/50 group-hover:text-black italic tracking-widest">Проверить баланс</span>
        </div>
      </button>
    </div>

    <Teleport to="body">
      <div v-if="showOverlay" class="fixed inset-0 bg-black/95 backdrop-blur-xl z-[9999990]" @click="closeAll"></div>
      <ConfirmModal v-if="isConfirmModalOpen" :isOpen="isConfirmModalOpen" :data="modalData" :mode="currentMode" :paymentMethod="paymentMethod" @close="closeAll" @confirm="handleConfirm" />
      <SmsModal v-if="isSmsModalOpen" :isOpen="isSmsModalOpen" :phone="userPhone" @close="closeAll" @verify="handleSmsVerify" />
      <PaymentModal v-if="isSuccessModalOpen" :isOpen="isSuccessModalOpen" :data="modalData" :mode="currentMode" @close="handleSuccessClose" />
    </Teleport>
  </div>
</template>
