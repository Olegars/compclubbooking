<script setup lang="ts">
import { useClubName } from '@/Composables/useClubName'

defineProps<{
    isOpen: boolean;
}>();

const emit = defineEmits(['close', 'confirm']);
const clubName = useClubName()
</script>

<template>
    <div v-if="isOpen"
         class="fixed top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[500px] bg-[#050505] border-2 border-yellow-500/50 rounded-[16px] p-10 z-[9999995] shadow-[0_0_100px_rgba(234,179,8,0.2)] select-none animate-in">

        <div class="flex items-center gap-4 mb-6">
            <div class="w-12 h-12 rounded-full bg-yellow-500/10 flex items-center justify-center text-yellow-500 text-2xl animate-pulse">⚠️</div>
            <h2 class="text-yellow-500 text-2xl font-black uppercase italic tracking-tighter leading-none">Внимание: Ночной протокол</h2>
        </div>

        <p class="text-white/80 text-sm leading-relaxed mb-8 font-medium">
            Согласно законодательству РФ и правилам {{ clubName }}, посещение клуба гражданами младше
            <span class="text-yellow-500 font-bold text-lg">18 лет</span> в период
            с <span class="text-white font-bold">22:00 до 06:00</span> запрещено без сопровождения взрослых.

            <span class="block mt-4 p-4 bg-white/5 rounded-2xl border border-white/5 text-xs text-white/40 italic">
                * Если вы выглядите молодо, администратор вправе потребовать
                <span class="text-yellow-500 font-bold uppercase">оригинал паспорта</span> или цифровой ID в приложении
                <span class="text-white font-bold underline">MAX</span> для подтверждения личности.
            </span>
        </p>

        <div class="flex gap-4">
            <button @click="emit('close')"
                    class="flex-1 py-4 rounded-2xl border border-white/10 text-white/40 uppercase text-[10px] font-black tracking-widest hover:text-white transition-all">
                Мне нет 18
            </button>
            <button @click="emit('confirm')"
                    class="flex-[2] py-4 bg-yellow-500 hover:bg-yellow-400 text-black uppercase text-[12px] font-black italic tracking-widest rounded-2xl transition-all shadow-[0_0_20px_rgba(234,179,8,0.2)] active:scale-95">
                Да, мне есть 18
            </button>
        </div>
    </div>
</template>

<style scoped>
.animate-in { animation: zoom-in 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
@keyframes zoom-in { from { opacity: 0; transform: scale(0.95) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }
</style>
